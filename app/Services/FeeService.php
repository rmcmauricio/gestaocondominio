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
}

