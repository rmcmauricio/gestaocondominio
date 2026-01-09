<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Fee;

class ReportService
{
    protected $budgetModel;
    protected $expenseModel;
    protected $feeModel;

    public function __construct()
    {
        $this->budgetModel = new Budget();
        $this->expenseModel = new Expense();
        $this->feeModel = new Fee();
    }

    /**
     * Generate balance sheet
     */
    public function generateBalanceSheet(int $condominiumId, int $year): array
    {
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        $expenses = $this->expenseModel->getByCondominium($condominiumId, ['year' => $year]);
        $fees = $this->feeModel->getByCondominium($condominiumId, ['year' => $year]);

        $totalExpenses = array_sum(array_column($expenses, 'amount'));
        $totalFees = array_sum(array_column($fees, 'amount'));
        $paidFees = array_sum(array_filter(array_column($fees, 'amount'), function($fee, $key) use ($fees) {
            return $fees[$key]['status'] === 'paid';
        }, ARRAY_FILTER_USE_BOTH));

        return [
            'year' => $year,
            'budget' => $budget,
            'total_budget' => $budget['total_amount'] ?? 0,
            'total_expenses' => $totalExpenses,
            'total_fees' => $totalFees,
            'paid_fees' => $paidFees,
            'pending_fees' => $totalFees - $paidFees,
            'balance' => ($budget['total_amount'] ?? 0) - $totalExpenses
        ];
    }

    /**
     * Generate expenses by category
     */
    public function generateExpensesByCategory(int $condominiumId, string $startDate, string $endDate): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT category, SUM(amount) as total, COUNT(*) as count
            FROM expenses
            WHERE condominium_id = :condominium_id
            AND expense_date BETWEEN :start_date AND :end_date
            GROUP BY category
            ORDER BY total DESC
        ");

        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Generate fees report
     */
    public function generateFeesReport(int $condominiumId, int $year, int $month = null): array
    {
        $filters = ['year' => $year];
        if ($month) {
            $filters['month'] = $month;
        }

        $fees = $this->feeModel->getByCondominium($condominiumId, $filters);

        $summary = [
            'total' => 0,
            'paid' => 0,
            'pending' => 0,
            'overdue' => 0,
            'by_status' => []
        ];

        foreach ($fees as $fee) {
            $summary['total'] += $fee['amount'];
            
            if ($fee['status'] === 'paid') {
                $summary['paid'] += $fee['amount'];
            } elseif ($fee['status'] === 'pending') {
                $summary['pending'] += $fee['amount'];
                if (strtotime($fee['due_date']) < time()) {
                    $summary['overdue'] += $fee['amount'];
                }
            }

            if (!isset($summary['by_status'][$fee['status']])) {
                $summary['by_status'][$fee['status']] = 0;
            }
            $summary['by_status'][$fee['status']] += $fee['amount'];
        }

        return [
            'fees' => $fees,
            'summary' => $summary,
            'year' => $year,
            'month' => $month
        ];
    }
}





