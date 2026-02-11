<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Expense;
use App\Models\Fee;
use App\Models\Occurrence;

class ReportService
{
    protected $budgetModel;
    protected $expenseModel;
    protected $feeModel;
    protected $occurrenceModel;

    public function __construct()
    {
        $this->budgetModel = new Budget();
        $this->expenseModel = new Expense();
        $this->feeModel = new Fee();
        $this->occurrenceModel = new Occurrence();
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
        global $db;
        
        if (!$db) {
            return [];
        }

        $sql = "SELECT f.*, fr.identifier as fraction_identifier,
                       GROUP_CONCAT(DISTINCT DATE(fp.payment_date) ORDER BY fp.payment_date DESC SEPARATOR ', ') as payment_dates
                FROM fees f
                INNER JOIN fractions fr ON fr.id = f.fraction_id
                LEFT JOIN fee_payments fp ON fp.fee_id = f.id
                WHERE f.condominium_id = :condominium_id
                AND f.period_year = :year";

        $params = [
            ':condominium_id' => $condominiumId,
            ':year' => $year
        ];

        if ($month) {
            $sql .= " AND f.period_month = :month";
            $params[':month'] = $month;
        }

        $sql .= " GROUP BY f.id
                  ORDER BY f.period_year DESC, COALESCE(f.period_index, f.period_month) DESC, fr.identifier ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $fees = $stmt->fetchAll() ?: [];

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
            
            // Translate month name to Portuguese
            $monthNames = [
                1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
            ];
            
            $cashFlow[] = [
                'month' => $month,
                'month_name' => $monthNames[$month] ?? date('F', strtotime($monthStart)),
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

    /**
     * Generate occurrence report by period
     */
    public function generateOccurrenceReport(int $condominiumId, string $startDate, string $endDate, array $filters = []): array
    {
        global $db;
        if (!$db) {
            return [];
        }

        $sql = "SELECT o.*, 
                       u1.name as reported_by_name, 
                       u2.name as assigned_to_name,
                       s.name as supplier_name,
                       f.identifier as fraction_identifier
                FROM occurrences o
                LEFT JOIN users u1 ON u1.id = o.reported_by
                LEFT JOIN users u2 ON u2.id = o.assigned_to
                LEFT JOIN suppliers s ON s.id = o.supplier_id
                LEFT JOIN fractions f ON f.id = o.fraction_id
                WHERE o.condominium_id = :condominium_id
                AND DATE(o.created_at) BETWEEN :start_date AND :end_date";

        $params = [
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ];

        if (isset($filters['status'])) {
            $sql .= " AND o.status = :status";
            $params[':status'] = $filters['status'];
        }

        if (isset($filters['priority'])) {
            $sql .= " AND o.priority = :priority";
            $params[':priority'] = $filters['priority'];
        }

        if (isset($filters['category'])) {
            $sql .= " AND o.category = :category";
            $params[':category'] = $filters['category'];
        }

        $sql .= " ORDER BY o.created_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $occurrences = $stmt->fetchAll() ?: [];

        // Calculate statistics
        $stats = [
            'total' => count($occurrences),
            'by_status' => [],
            'by_priority' => [],
            'by_category' => [],
            'average_resolution_time' => 0
        ];

        foreach ($occurrences as $occ) {
            // Count by status
            if (!isset($stats['by_status'][$occ['status']])) {
                $stats['by_status'][$occ['status']] = 0;
            }
            $stats['by_status'][$occ['status']]++;

            // Count by priority
            if (!isset($stats['by_priority'][$occ['priority']])) {
                $stats['by_priority'][$occ['priority']] = 0;
            }
            $stats['by_priority'][$occ['priority']]++;

            // Count by category
            if ($occ['category']) {
                if (!isset($stats['by_category'][$occ['category']])) {
                    $stats['by_category'][$occ['category']] = 0;
                }
                $stats['by_category'][$occ['category']]++;
            }
        }

        // Calculate average resolution time
        $stmt = $db->prepare("
            SELECT AVG(DATEDIFF(completed_at, created_at)) as avg_days
            FROM occurrences
            WHERE condominium_id = :condominium_id
            AND DATE(created_at) BETWEEN :start_date AND :end_date
            AND status = 'completed'
            AND completed_at IS NOT NULL
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $result = $stmt->fetch();
        $stats['average_resolution_time'] = round($result['avg_days'] ?? 0, 1);

        return [
            'occurrences' => $occurrences,
            'stats' => $stats,
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Generate occurrence report by supplier
     */
    public function generateOccurrenceBySupplierReport(int $condominiumId, string $startDate, string $endDate): array
    {
        global $db;
        if (!$db) {
            return [];
        }

        $sql = "
            SELECT s.id, s.name,
                   COUNT(o.id) as total_occurrences,
                   SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                   AVG(CASE WHEN o.status = 'completed' AND o.completed_at IS NOT NULL 
                       THEN DATEDIFF(o.completed_at, o.created_at) ELSE NULL END) as avg_resolution_days
            FROM suppliers s
            INNER JOIN occurrences o ON o.supplier_id = s.id
            WHERE s.condominium_id = :condominium_id
            AND DATE(o.created_at) BETWEEN :start_date AND :end_date
            GROUP BY s.id, s.name
            ORDER BY total_occurrences DESC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * Generate summary financial report
     */
    public function generateSummaryReport(int $condominiumId, string $startDate, string $endDate): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        // Get total revenue (paid fees)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(fp.amount), 0) as total_revenue
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            WHERE f.condominium_id = :condominium_id
            AND DATE(fp.payment_date) BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $revenueResult = $stmt->fetch();
        $totalRevenue = (float)($revenueResult['total_revenue'] ?? 0);

        // Get total expenses
        $totalExpenses = $this->expenseModel->getTotalByPeriod($condominiumId, $startDate, $endDate);

        // Get fees summary
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_total,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_total,
                SUM(CASE WHEN status = 'pending' AND due_date < CURDATE() THEN amount ELSE 0 END) as overdue_total
            FROM fees
            WHERE condominium_id = :condominium_id
            AND DATE(created_at) BETWEEN :start_date AND :end_date
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $feesResult = $stmt->fetch();
        
        $balance = $totalRevenue - $totalExpenses;

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'balance' => $balance,
            'fees' => [
                'total_count' => (int)($feesResult['total_count'] ?? 0),
                'paid_total' => (float)($feesResult['paid_total'] ?? 0),
                'pending_total' => (float)($feesResult['pending_total'] ?? 0),
                'overdue_total' => (float)($feesResult['overdue_total'] ?? 0)
            ]
        ];
    }
}





