<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\Fraction;
use App\Models\Budget;
use App\Models\BudgetItem;

class FeeService
{
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
        
        // Get monthly budget amount (assuming equal distribution)
        $monthlyAmount = $totalRevenue / 12;

        $generatedFees = [];

        foreach ($months as $month) {
            $month = (int)$month;
            if ($month < 1 || $month > 12) {
                continue; // Skip invalid months
            }

            $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10")); // Due on 10th of month

            foreach ($fractions as $fraction) {
                // Calculate fee based on permillage
                $feeAmount = ($monthlyAmount * $fraction['permillage']) / $totalPermillage;

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
     * Generate fee reference
     */
    protected function generateFeeReference(int $condominiumId, int $fractionId, int $year, int $month): string
    {
        $monthStr = str_pad($month, 2, '0', STR_PAD_LEFT);
        return sprintf('Q%03d-%02d-%04d%02d', $condominiumId, $fractionId, $year, $monthStr);
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
     * Generate annual fees for all 12 months based on approved budget
     * @param int $condominiumId
     * @param int $year
     * @return array Array of generated fee IDs
     */
    public function generateAnnualFeesFromBudget(int $condominiumId, int $year): array
    {
        // Get budget for the year
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        
        if (!$budget) {
            throw new \Exception("Orçamento não encontrado para o ano {$year}. Crie um orçamento primeiro.");
        }
        
        // Check if budget is approved
        if ($budget['status'] !== 'approved' && $budget['status'] !== 'active') {
            throw new \Exception("O orçamento deve estar aprovado para gerar quotas automaticamente. Status atual: {$budget['status']}");
        }
        
        // Check if annual fees have already been generated (use actual fee data as source of truth)
        if ($this->feeModel->hasAnnualFeesForYear($condominiumId, $year)) {
            throw new \Exception("As quotas anuais já foram geradas automaticamente para este ano. Não é possível gerar novamente.");
        }
        
        // Generate for all 12 months
        $allMonths = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
        $generatedFees = $this->generateMonthlyFees($condominiumId, $year, $allMonths);
        
        // Mark as generated
        if (!empty($generatedFees)) {
            $this->budgetModel->markAnnualFeesGenerated($budget['id']);
        }
        
        return $generatedFees;
    }

    /**
     * Generate annual fees manually with permillage
     * @param int $condominiumId
     * @param int $year
     * @param float $totalAnnualAmount - Total annual amount (will be distributed monthly and by permillage)
     * @return array Array of generated fee IDs
     */
    public function generateAnnualFeesManual(int $condominiumId, int $year, float $totalAnnualAmount): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        if ($totalAnnualAmount <= 0) {
            throw new \Exception("O valor total anual deve ser maior que zero.");
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

        // Calculate monthly amount per fraction
        $monthlyTotalAmount = $totalAnnualAmount / 12;

        $generatedFees = [];
        $allMonths = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

        foreach ($allMonths as $month) {
            $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10"));

            foreach ($fractions as $fraction) {
                // Calculate fee based on permillage
                $feeAmount = ($monthlyTotalAmount * $fraction['permillage']) / $totalPermillage;

                // Check if regular fee already exists
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
     * Generate annual fees manually with specific amounts per fraction
     * @param int $condominiumId
     * @param int $year
     * @param array $fractionAmounts - Array with fraction_id => annual_amount
     * @return array Array of generated fee IDs
     */
    public function generateAnnualFeesManualPerFraction(int $condominiumId, int $year, array $fractionAmounts): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        if (empty($fractionAmounts)) {
            throw new \Exception("Deve fornecer valores para pelo menos uma fração.");
        }

        // Get all active fractions
        $fractions = $this->fractionModel->getByCondominiumId($condominiumId);
        
        if (empty($fractions)) {
            throw new \Exception("Nenhuma fração encontrada no condomínio.");
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
        $allMonths = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

        foreach ($allMonths as $month) {
            $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10"));

            foreach ($fractionAmounts as $fractionId => $annualAmount) {
                $fractionId = (int)$fractionId;
                $annualAmount = (float)$annualAmount;
                
                // Calculate monthly amount for this fraction
                $monthlyAmount = $annualAmount / 12;

                // Check if regular fee already exists
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
                    ':fraction_id' => $fractionId,
                    ':year' => $year,
                    ':month' => $month
                ]);

                if ($existing->fetch()) {
                    continue; // Regular fee already exists
                }

                // Generate reference
                $reference = $this->generateFeeReference($condominiumId, $fractionId, $year, $month);

                // Create fee
                $feeId = $this->feeModel->create([
                    'condominium_id' => $condominiumId,
                    'fraction_id' => $fractionId,
                    'period_type' => 'monthly',
                    'fee_type' => 'regular',
                    'period_year' => $year,
                    'period_month' => $month,
                    'amount' => round($monthlyAmount, 2),
                    'base_amount' => round($monthlyAmount, 2),
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

