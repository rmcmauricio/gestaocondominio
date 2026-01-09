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

    /**
     * Generate cash flow report (monthly)
     */
    public function generateCashFlow(int $condominiumId, int $year): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $cashFlow = [];
        
        for ($month = 1; $month <= 12; $month++) {
            $monthStart = sprintf('%04d-%02d-01', $year, $month);
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            
            // Revenue (paid fees)
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(fp.amount), 0) as revenue
                FROM fee_payments fp
                INNER JOIN fees f ON f.id = fp.fee_id
                WHERE f.condominium_id = :condominium_id
                AND DATE(fp.payment_date) BETWEEN :start_date AND :end_date
            ");
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':start_date' => $monthStart,
                ':end_date' => $monthEnd
            ]);
            $revenue = (float)($stmt->fetch()['revenue'] ?? 0);
            
            // Expenses
            $expenses = $this->expenseModel->getTotalByPeriod($condominiumId, $monthStart, $monthEnd);
            
            $cashFlow[] = [
                'month' => $month,
                'month_name' => date('F', strtotime($monthStart)),
                'revenue' => $revenue,
                'expenses' => $expenses,
                'net' => $revenue - $expenses
            ];
        }
        
        return [
            'year' => $year,
            'cash_flow' => $cashFlow,
            'total_revenue' => array_sum(array_column($cashFlow, 'revenue')),
            'total_expenses' => array_sum(array_column($cashFlow, 'expenses')),
            'net_total' => array_sum(array_column($cashFlow, 'net'))
        ];
    }

    /**
     * Generate budget vs actual comparison
     */
    public function generateBudgetVsActual(int $condominiumId, int $year): array
    {
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        
        if (!$budget) {
            return [
                'year' => $year,
                'budget' => null,
                'comparison' => []
            ];
        }

        global $db;
        if (!$db) {
            return [];
        }

        // Get budget items
        $stmt = $db->prepare("
            SELECT * FROM budget_items
            WHERE budget_id = :budget_id
            ORDER BY sort_order
        ");
        $stmt->execute([':budget_id' => $budget['id']]);
        $budgetItems = $stmt->fetchAll() ?: [];

        $comparison = [];
        $totalBudgeted = 0;
        $totalActual = 0;

        foreach ($budgetItems as $item) {
            $isRevenue = strpos($item['category'], 'Receita:') === 0;
            $category = str_replace(['Receita: ', 'Despesa: '], '', $item['category']);
            
            $actual = 0;
            if ($isRevenue) {
                // Actual revenue from paid fees
                $stmt = $db->prepare("
                    SELECT COALESCE(SUM(fp.amount), 0) as total
                    FROM fee_payments fp
                    INNER JOIN fees f ON f.id = fp.fee_id
                    WHERE f.condominium_id = :condominium_id
                    AND YEAR(fp.payment_date) = :year
                ");
                $stmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':year' => $year
                ]);
                $result = $stmt->fetch();
                $actual = (float)($result['total'] ?? 0);
            } else {
                // Actual expenses
                $actual = $this->expenseModel->getTotalByPeriod(
                    $condominiumId,
                    "{$year}-01-01",
                    "{$year}-12-31"
                );
            }

            $variance = $actual - $item['amount'];
            $variancePercent = $item['amount'] > 0 ? ($variance / $item['amount']) * 100 : 0;

            $comparison[] = [
                'category' => $category,
                'type' => $isRevenue ? 'revenue' : 'expense',
                'budgeted' => (float)$item['amount'],
                'actual' => $actual,
                'variance' => $variance,
                'variance_percent' => $variancePercent
            ];

            $totalBudgeted += (float)$item['amount'];
            $totalActual += $actual;
        }

        return [
            'year' => $year,
            'budget' => $budget,
            'comparison' => $comparison,
            'total_budgeted' => $totalBudgeted,
            'total_actual' => $totalActual,
            'total_variance' => $totalActual - $totalBudgeted
        ];
    }

    /**
     * Generate delinquency report
     */
    public function generateDelinquencyReport(int $condominiumId): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $sql = "
            SELECT 
                fr.identifier as fraction_identifier,
                u.name as owner_name,
                u.email as owner_email,
                COUNT(f.id) as overdue_count,
                SUM(f.amount - COALESCE((
                    SELECT SUM(fp.amount) 
                    FROM fee_payments fp 
                    WHERE fp.fee_id = f.id
                ), 0)) as total_debt,
                MIN(f.due_date) as oldest_due_date,
                MAX(f.due_date) as newest_due_date
            FROM fees f
            INNER JOIN fractions fr ON fr.id = f.fraction_id
            INNER JOIN condominium_users cu ON cu.fraction_id = f.fraction_id AND cu.condominium_id = f.condominium_id
            INNER JOIN users u ON u.id = cu.user_id
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'pending'
            AND f.due_date < CURDATE()
            AND COALESCE(f.is_historical, 0) = 0
            AND (f.amount - COALESCE((
                SELECT SUM(fp.amount) 
                FROM fee_payments fp 
                WHERE fp.fee_id = f.id
            ), 0)) > 0
            GROUP BY fr.id, fr.identifier, u.id, u.name, u.email
            ORDER BY total_debt DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([':condominium_id' => $condominiumId]);
        $delinquents = $stmt->fetchAll() ?: [];

        $totalDebt = array_sum(array_column($delinquents, 'total_debt'));

        return [
            'delinquents' => $delinquents,
            'total_debt' => $totalDebt,
            'total_delinquents' => count($delinquents)
        ];
    }
}





