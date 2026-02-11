<?php

namespace App\Services;

use App\Models\CondominiumFeePeriod;
use App\Models\Fee;
use App\Models\Fraction;
use App\Models\Budget;
use App\Models\BudgetItem;

class FeeService
{
    /** Period config: count per year, last month of each period for due_date */
    private const PERIOD_CONFIG = [
        'monthly' => ['count' => 12, 'lastMonths' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
        'bimonthly' => ['count' => 6, 'lastMonths' => [2, 4, 6, 8, 10, 12]],
        'quarterly' => ['count' => 4, 'lastMonths' => [3, 6, 9, 12]],
        'semiannual' => ['count' => 2, 'lastMonths' => [6, 12]],
        'annual' => ['count' => 1, 'lastMonths' => [12]],
        'yearly' => ['count' => 1, 'lastMonths' => [12]], // alias
    ];

    protected $feeModel;
    protected $fractionModel;
    protected $budgetModel;
    protected $budgetItemModel;

    public function __construct()
    {
        $this->feeModel = new Fee();
        $this->fractionModel = new Fraction();
        $this->budgetModel = new Budget();
        $this->budgetItemModel = new BudgetItem();
    }

    /**
     * Generate monthly fees for all fractions in condominium
     * Now supports multiple months at once
     * @param int $condominiumId
     * @param int $year
     * @param array|int $months - Array of months or single month
     * @return array Array of generated fee IDs
     */
    public function generateMonthlyFees(int $condominiumId, int $year, $months): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        // Normalize months to array
        if (!is_array($months)) {
            $months = [(int)$months];
        }

        // Get budget for the year
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        
        if (!$budget) {
            throw new \Exception("Orçamento não encontrado para o ano {$year}. Crie um orçamento primeiro.");
        }
        
        // Allow generation if budget is draft or approved
        if (!in_array($budget['status'], ['draft', 'approved', 'active'])) {
            throw new \Exception("Orçamento não está disponível para geração de quotas. Status: {$budget['status']}");
        }

        // Get all active fractions
        $fractions = $this->fractionModel->getByCondominiumId($condominiumId);
        
        if (empty($fractions)) {
            throw new \Exception("Nenhuma fração encontrada no condomínio.");
        }

        // Calculate total permillage
        $totalPermillage = $this->fractionModel->getTotalPermillage($condominiumId);
        
        if ($totalPermillage == 0) {
            throw new \Exception("Permilagem total não pode ser zero. Verifique as frações.");
        }

        // Get total revenue from budget items (only revenue items, not expenses)
        $items = $this->budgetItemModel->getByBudget($budget['id']);
        $revenueItems = array_filter($items, function($item) {
            return strpos($item['category'], 'Receita:') === 0;
        });
        
        $totalRevenue = array_sum(array_column($revenueItems, 'amount'));
        
        if ($totalRevenue <= 0) {
            throw new \Exception("O orçamento não tem receitas definidas. Adicione receitas ao orçamento primeiro.");
        }

        // When generating all 12 months: use last-month adjustment so sum = annual per fraction
        $months = array_map('intval', $months);
        $months = array_filter($months, fn($m) => $m >= 1 && $m <= 12);
        $months = array_values(array_unique($months));
        $isFullYear = (count($months) === 12 && array_sum($months) === 78); // 1+2+...+12=78

        $fractionMonthlyAmounts = [];
        foreach ($fractions as $fraction) {
            $annualFraction = ($totalRevenue * (float)$fraction['permillage']) / $totalPermillage;
            if ($isFullYear) {
                $baseMonthly = floor($annualFraction * 100 / 12) / 100;
                $first11Sum = $baseMonthly * 11;
                $lastMonthAmount = round($annualFraction - $first11Sum, 2);
                $fractionMonthlyAmounts[$fraction['id']] = array_fill(1, 12, $baseMonthly);
                $fractionMonthlyAmounts[$fraction['id']][12] = $lastMonthAmount;
            } else {
                $fractionMonthlyAmounts[$fraction['id']] = null; // Use simple division per month
            }
        }

        $generatedFees = [];

        foreach ($months as $month) {
            $month = (int)$month;

            $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10")); // Due on 10th of month

            foreach ($fractions as $fraction) {
                $feeAmount = $fractionMonthlyAmounts[$fraction['id']] !== null
                    ? $fractionMonthlyAmounts[$fraction['id']][$month]
                    : round(($totalRevenue / 12 * (float)$fraction['permillage']) / $totalPermillage, 2);

                // Check if regular fee already exists (only check for regular fees, not extras)
                $existing = $db->prepare("
                    SELECT id FROM fees 
                    WHERE condominium_id = :condominium_id 
                    AND fraction_id = :fraction_id 
                    AND period_year = :year 
                    AND period_month = :month
                    AND fee_type = 'regular'
                ");

                $existing->execute([
                    ':condominium_id' => $condominiumId,
                    ':fraction_id' => $fraction['id'],
                    ':year' => $year,
                    ':month' => $month
                ]);

                if ($existing->fetch()) {
                    continue; // Regular fee already exists
                }

                // Generate reference
                $reference = $this->generateFeeReference($condominiumId, $fraction['id'], $year, $month);

                // Create fee
                $feeId = $this->feeModel->create([
                    'condominium_id' => $condominiumId,
                    'fraction_id' => $fraction['id'],
                    'period_type' => 'monthly',
                    'fee_type' => 'regular',
                    'period_year' => $year,
                    'period_month' => $month,
                    'amount' => round($feeAmount, 2),
                    'base_amount' => round($feeAmount, 2),
                    'status' => 'pending',
                    'due_date' => $dueDate,
                    'reference' => $reference
                ]);

                $generatedFees[] = $feeId;
            }
        }

        return $generatedFees;
    }

    /**
     * Generate extra fees for selected fractions
     * @param int $condominiumId
     * @param int $year
     * @param array|int $months - Array of months or single month
     * @param float $totalAmount - Total amount for extra fee (will be distributed by permillage)
     * @param string $description - Description/reason for extra fee
     * @param array $fractionIds - Array of fraction IDs (empty = all fractions)
     * @return array Array of generated fee IDs
     */
    public function generateExtraFees(int $condominiumId, int $year, $months, float $totalAmount, string $description = '', array $fractionIds = []): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        // Normalize months to array
        if (!is_array($months)) {
            $months = [(int)$months];
        }

        // Get fractions
        if (empty($fractionIds)) {
            $fractions = $this->fractionModel->getByCondominiumId($condominiumId);
        } else {
            $fractions = [];
            foreach ($fractionIds as $fractionId) {
                $fraction = $this->fractionModel->findById($fractionId);
                if ($fraction && $fraction['condominium_id'] == $condominiumId) {
                    $fractions[] = $fraction;
                }
            }
        }

        if (empty($fractions)) {
            throw new \Exception("Nenhuma fração selecionada ou encontrada.");
        }

        // Calculate total permillage for selected fractions
        $totalPermillage = 0;
        foreach ($fractions as $fraction) {
            $totalPermillage += (float)$fraction['permillage'];
        }

        if ($totalPermillage == 0) {
            throw new \Exception("Permilagem total não pode ser zero. Verifique as frações.");
        }

        $generatedFees = [];

        foreach ($months as $month) {
            $month = (int)$month;
            if ($month < 1 || $month > 12) {
                continue; // Skip invalid months
            }

            $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10")); // Due on 10th of month

            foreach ($fractions as $fraction) {
                // Calculate fee amount based on permillage
                $feeAmount = ($totalAmount * (float)$fraction['permillage']) / $totalPermillage;

                // Generate reference for extra fee (add -E suffix)
                $reference = $this->generateFeeReference($condominiumId, $fraction['id'], $year, $month) . '-E';

                // Create extra fee
                $feeId = $this->feeModel->create([
                    'condominium_id' => $condominiumId,
                    'fraction_id' => $fraction['id'],
                    'period_type' => 'monthly',
                    'fee_type' => 'extra',
                    'period_year' => $year,
                    'period_month' => $month,
                    'amount' => round($feeAmount, 2),
                    'base_amount' => round($feeAmount, 2),
                    'status' => 'pending',
                    'due_date' => $dueDate,
                    'reference' => $reference,
                    'notes' => $description
                ]);

                $generatedFees[] = $feeId;
            }
        }

        return $generatedFees;
    }

    /**
     * Generate fee reference (month-based or period-based)
     */
    protected function generateFeeReference(int $condominiumId, int $fractionId, int $year, int $monthOrIndex, bool $usePeriodIndex = false): string
    {
        $suffix = str_pad((string)$monthOrIndex, 2, '0', STR_PAD_LEFT);
        return sprintf('Q%03d-%02d-%04d%02d', $condominiumId, $fractionId, $year, $suffix);
    }

    /**
     * Get due date for a period (last day of last month in period)
     */
    protected function getDueDateForPeriod(int $year, string $periodType, int $periodIndex): string
    {
        $config = self::PERIOD_CONFIG[$periodType] ?? self::PERIOD_CONFIG['monthly'];
        $lastMonth = $config['lastMonths'][$periodIndex - 1] ?? 12;
        $lastDay = (int)date('t', strtotime("{$year}-{$lastMonth}-01"));
        return sprintf('%04d-%02d-%02d', $year, $lastMonth, min(10, $lastDay));
    }

    /**
     * Calculate amounts per period with floor on first N-1 and adjustment on last (for clean liquidation)
     */
    protected function splitAmountByPeriod(float $annualAmount, int $periodCount): array
    {
        if ($periodCount <= 1) {
            return [1 => round($annualAmount, 2)];
        }
        $baseAmount = floor($annualAmount * 100 / $periodCount) / 100;
        $firstNMinus1Sum = $baseAmount * ($periodCount - 1);
        $lastAmount = round($annualAmount - $firstNMinus1Sum, 2);
        $amounts = array_fill(1, $periodCount, $baseAmount);
        $amounts[$periodCount] = $lastAmount;
        return $amounts;
    }

    /**
     * Calculate fee amount based on permillage
     */
    public function calculateFeeAmount(float $totalAmount, float $fractionPermillage, float $totalPermillage): float
    {
        if ($totalPermillage == 0) {
            return 0;
        }

        return ($totalAmount * $fractionPermillage) / $totalPermillage;
    }

    /**
     * Generate annual fees for all periods based on approved budget
     * @param int $condominiumId
     * @param int $year
     * @param string $periodType monthly|bimonthly|quarterly|semiannual|annual
     * @return array Array of generated fee IDs
     */
    public function generateAnnualFeesFromBudget(int $condominiumId, int $year, string $periodType = 'monthly'): array
    {
        $periodType = $this->normalizePeriodType($periodType);

        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        if (!$budget) {
            throw new \Exception("Orçamento não encontrado para o ano {$year}. Crie um orçamento primeiro.");
        }
        if ($budget['status'] !== 'approved' && $budget['status'] !== 'active') {
            throw new \Exception("O orçamento deve estar aprovado para gerar quotas automaticamente. Status atual: {$budget['status']}");
        }
        if ($this->feeModel->hasAnnualFeesForYear($condominiumId, $year, $periodType)) {
            throw new \Exception("As quotas anuais já foram geradas automaticamente para este ano. Não é possível gerar novamente.");
        }

        $items = $this->budgetItemModel->getByBudget($budget['id']);
        $revenueItems = array_filter($items, fn($i) => strpos($i['category'], 'Receita:') === 0);
        $totalRevenue = array_sum(array_column($revenueItems, 'amount'));
        if ($totalRevenue <= 0) {
            throw new \Exception("O orçamento não tem receitas definidas. Adicione receitas ao orçamento primeiro.");
        }

        $fractions = $this->fractionModel->getByCondominiumId($condominiumId);
        $totalPermillage = $this->fractionModel->getTotalPermillage($condominiumId);
        if (empty($fractions) || $totalPermillage == 0) {
            throw new \Exception("Nenhuma fração encontrada ou permilagem zero.");
        }

        $fractionAmounts = [];
        foreach ($fractions as $f) {
            $fractionAmounts[$f['id']] = ($totalRevenue * (float)$f['permillage']) / $totalPermillage;
        }

        $generatedFees = $this->generateRegularFeesByPeriod($condominiumId, $year, $fractionAmounts, $periodType);

        if (!empty($generatedFees)) {
            $this->budgetModel->markAnnualFeesGenerated($budget['id']);
            (new CondominiumFeePeriod())->set($condominiumId, $year, $periodType);
        }

        return $generatedFees;
    }

    protected function normalizePeriodType(string $type): string
    {
        $type = strtolower($type);
        if ($type === 'yearly') {
            return 'annual';
        }
        return in_array($type, CondominiumFeePeriod::PERIOD_TYPES) ? $type : 'monthly';
    }

    /**
     * Core: generate regular fees by period type (amounts per fraction = annual values)
     */
    protected function generateRegularFeesByPeriod(int $condominiumId, int $year, array $fractionAmounts, string $periodType): array
    {
        global $db;
        if (!$db) {
            return [];
        }

        $periodType = $this->normalizePeriodType($periodType);
        $config = self::PERIOD_CONFIG[$periodType] ?? self::PERIOD_CONFIG['monthly'];
        $periodCount = $config['count'];
        $generatedFees = [];

        foreach ($fractionAmounts as $fractionId => $annualAmount) {
            $fractionId = (int)$fractionId;
            $annualAmount = (float)$annualAmount;
            if ($annualAmount <= 0) {
                continue;
            }

            $amounts = $this->splitAmountByPeriod($annualAmount, $periodCount);

            for ($periodIndex = 1; $periodIndex <= $periodCount; $periodIndex++) {
                $lastMonth = $config['lastMonths'][$periodIndex - 1] ?? 12;
                $existing = $db->prepare("
                    SELECT id FROM fees WHERE condominium_id = :cid AND fraction_id = :fid
                    AND period_year = :year AND fee_type = 'regular'
                    AND (period_index = :pidx OR (period_index IS NULL AND period_month = :month))
                ");
                $existing->execute([
                    ':cid' => $condominiumId,
                    ':fid' => $fractionId,
                    ':year' => $year,
                    ':pidx' => $periodIndex,
                    ':month' => $lastMonth
                ]);
                if ($existing->fetch()) {
                    continue;
                }

                $dueDate = $this->getDueDateForPeriod($year, $periodType, $periodIndex);
                $refSuffix = ($periodType === 'monthly') ? $periodIndex : $periodIndex;
                $reference = $this->generateFeeReference($condominiumId, $fractionId, $year, $refSuffix);

                $feeId = $this->feeModel->create([
                    'condominium_id' => $condominiumId,
                    'fraction_id' => $fractionId,
                    'period_type' => $periodType,
                    'fee_type' => 'regular',
                    'period_year' => $year,
                    'period_month' => ($periodType === 'monthly') ? $periodIndex : null,
                    'period_quarter' => ($periodType === 'quarterly') ? $periodIndex : null,
                    'period_index' => $periodIndex,
                    'amount' => round($amounts[$periodIndex], 2),
                    'base_amount' => round($amounts[$periodIndex], 2),
                    'status' => 'pending',
                    'due_date' => $dueDate,
                    'reference' => $reference
                ]);
                $generatedFees[] = $feeId;
            }
        }

        return $generatedFees;
    }

    /**
     * Generate annual fees manually with permillage
     * @param int $condominiumId
     * @param int $year
     * @param float $totalAnnualAmount
     * @param string $periodType monthly|bimonthly|quarterly|semiannual|annual
     * @return array Array of generated fee IDs
     */
    public function generateAnnualFeesManual(int $condominiumId, int $year, float $totalAnnualAmount, string $periodType = 'monthly'): array
    {
        $periodType = $this->normalizePeriodType($periodType);
        if ($totalAnnualAmount <= 0) {
            throw new \Exception("O valor total anual deve ser maior que zero.");
        }

        $fractions = $this->fractionModel->getByCondominiumId($condominiumId);
        if (empty($fractions)) {
            throw new \Exception("Nenhuma fração encontrada no condomínio.");
        }
        $totalPermillage = $this->fractionModel->getTotalPermillage($condominiumId);
        if ($totalPermillage == 0) {
            throw new \Exception("Permilagem total não pode ser zero. Verifique as frações.");
        }

        $fractionAmounts = [];
        foreach ($fractions as $f) {
            $fractionAmounts[$f['id']] = ($totalAnnualAmount * (float)$f['permillage']) / $totalPermillage;
        }

        $generatedFees = $this->generateRegularFeesByPeriod($condominiumId, $year, $fractionAmounts, $periodType);
        if (!empty($generatedFees)) {
            (new CondominiumFeePeriod())->set($condominiumId, $year, $periodType);
        }
        return $generatedFees;
    }

    /**
     * Generate annual fees manually with specific amounts per fraction
     * @param int $condominiumId
     * @param int $year
     * @param array $fractionAmounts - Array with fraction_id => annual_amount
     * @param string $periodType monthly|bimonthly|quarterly|semiannual|annual
     * @return array Array of generated fee IDs
     */
    public function generateAnnualFeesManualPerFraction(int $condominiumId, int $year, array $fractionAmounts, string $periodType = 'monthly'): array
    {
        if (empty($fractionAmounts)) {
            throw new \Exception("Deve fornecer valores para pelo menos uma fração.");
        }

        foreach ($fractionAmounts as $fractionId => $annualAmount) {
            $fraction = $this->fractionModel->findById((int)$fractionId);
            if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
                throw new \Exception("Fração ID {$fractionId} não encontrada ou não pertence ao condomínio.");
            }
            if ((float)$annualAmount <= 0) {
                throw new \Exception("O valor anual para a fração ID {$fractionId} deve ser maior que zero.");
            }
        }

        $periodType = $this->normalizePeriodType($periodType);
        $generatedFees = $this->generateRegularFeesByPeriod($condominiumId, $year, $fractionAmounts, $periodType);
        if (!empty($generatedFees)) {
            (new CondominiumFeePeriod())->set($condominiumId, $year, $periodType);
        }
        return $generatedFees;
    }

    /**
     * Generate extra fees with permillage (wrapper for generateExtraFees)
     * @param int $condominiumId
     * @param int $year
     * @param array $months
     * @param float $totalAmount - Total amount for all fractions (will be distributed by permillage)
     * @param string $description
     * @return array Array of generated fee IDs
     */
    public function generateExtraFeesWithPermillage(int $condominiumId, int $year, array $months, float $totalAmount, string $description = ''): array
    {
        return $this->generateExtraFees($condominiumId, $year, $months, $totalAmount, $description, []);
    }

    /**
     * Generate extra fees manually with specific amounts per fraction
     * @param int $condominiumId
     * @param int $year
     * @param array $months
     * @param array $fractionAmounts - Array with fraction_id => annual_amount
     * @param string $description
     * @return array Array of generated fee IDs
     */
    public function generateExtraFeesManual(int $condominiumId, int $year, array $months, array $fractionAmounts, string $description = ''): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        if (empty($months)) {
            throw new \Exception("Deve selecionar pelo menos um mês.");
        }

        if (empty($fractionAmounts)) {
            throw new \Exception("Deve fornecer valores para pelo menos uma fração.");
        }

        // Normalize months to array
        if (!is_array($months)) {
            $months = [(int)$months];
        }

        // Validate all fractions exist and belong to condominium
        foreach ($fractionAmounts as $fractionId => $annualAmount) {
            $fraction = $this->fractionModel->findById((int)$fractionId);
            if (!$fraction || $fraction['condominium_id'] != $condominiumId) {
                throw new \Exception("Fração ID {$fractionId} não encontrada ou não pertence ao condomínio.");
            }
            if ((float)$annualAmount <= 0) {
                throw new \Exception("O valor anual para a fração ID {$fractionId} deve ser maior que zero.");
            }
        }

        $generatedFees = [];

        foreach ($months as $month) {
            $month = (int)$month;
            if ($month < 1 || $month > 12) {
                continue; // Skip invalid months
            }

            $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10"));

            foreach ($fractionAmounts as $fractionId => $annualAmount) {
                $fractionId = (int)$fractionId;
                $annualAmount = (float)$annualAmount;
                
                // Divide the annual amount by the number of selected months
                // Example: €1200 annual / 3 months = €400 per month
                $monthlyAmount = $annualAmount / count($months);
                $feeAmount = $monthlyAmount;

                // Generate reference for extra fee (add -E suffix)
                $reference = $this->generateFeeReference($condominiumId, $fractionId, $year, $month) . '-E';

                // Create extra fee
                $feeId = $this->feeModel->create([
                    'condominium_id' => $condominiumId,
                    'fraction_id' => $fractionId,
                    'period_type' => 'monthly',
                    'fee_type' => 'extra',
                    'period_year' => $year,
                    'period_month' => $month,
                    'amount' => round($feeAmount, 2),
                    'base_amount' => round($feeAmount, 2),
                    'status' => 'pending',
                    'due_date' => $dueDate,
                    'reference' => $reference,
                    'notes' => $description
                ]);

                $generatedFees[] = $feeId;
            }
        }

        return $generatedFees;
    }
}

