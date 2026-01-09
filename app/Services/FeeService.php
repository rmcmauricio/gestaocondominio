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
     */
    public function generateMonthlyFees(int $condominiumId, int $year, int $month): array
    {
        global $db;
        
        if (!$db) {
            return [];
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
        $dueDate = date('Y-m-d', strtotime("{$year}-{$month}-10")); // Due on 10th of month

        foreach ($fractions as $fraction) {
            // Calculate fee based on permillage
            $feeAmount = ($monthlyAmount * $fraction['permillage']) / $totalPermillage;

            // Check if fee already exists
            $existing = $db->prepare("
                SELECT id FROM fees 
                WHERE condominium_id = :condominium_id 
                AND fraction_id = :fraction_id 
                AND period_year = :year 
                AND period_month = :month
            ");

            $existing->execute([
                ':condominium_id' => $condominiumId,
                ':fraction_id' => $fraction['id'],
                ':year' => $year,
                ':month' => $month
            ]);

            if ($existing->fetch()) {
                continue; // Fee already exists
            }

            // Generate reference
            $reference = $this->generateFeeReference($condominiumId, $fraction['id'], $year, $month);

            // Create fee
            $feeId = $this->feeModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => $fraction['id'],
                'period_type' => 'monthly',
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

