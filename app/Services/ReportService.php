<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Fee;
use App\Models\FinancialTransaction;
use App\Models\Occurrence;
use App\Models\Revenue;

/**
 * Serviço de relatórios financeiros.
 * Nota: transferências (related_type = 'transfer') são sempre excluídas dos totais
 * de receitas e despesas em todos os relatórios.
 */
class ReportService
{
    protected $budgetModel;
    protected $budgetItemModel;
    protected $transactionModel;
    protected $feeModel;
    protected $occurrenceModel;
    protected $revenueModel;

    public function __construct()
    {
        $this->budgetModel = new Budget();
        $this->budgetItemModel = new BudgetItem();
        $this->transactionModel = new FinancialTransaction();
        $this->feeModel = new Fee();
        $this->occurrenceModel = new Occurrence();
        $this->revenueModel = new Revenue();
    }

    /**
     * Generate balance sheet.
     * Despesas: financial_transactions tipo expense, excluindo transferências.
     */
    public function generateBalanceSheet(int $condominiumId, int $year): array
    {
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        if (!$budget) {
            return ['no_budget' => true, 'year' => $year];
        }

        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";
        // Despesas sem transferências (getTotalByPeriodAndType já exclui related_type = 'transfer')
        $totalExpenses = $this->transactionModel->getTotalByPeriodAndType($condominiumId, $startDate, $endDate, 'expense');
        $fees = $this->feeModel->getByCondominium($condominiumId, ['year' => $year]);
        $totalFees = array_sum(array_column($fees, 'amount'));
        $paidFees = array_sum(array_filter(array_column($fees, 'amount'), function($fee, $key) use ($fees) {
            return $fees[$key]['status'] === 'paid';
        }, ARRAY_FILTER_USE_BOTH));

        // Quotas recebidas no ano: split pelo ano da quota (fee.period_year), igual à página de teste.
        // Assim o Balancete bate certo com os valores da página "Teste: Quotas pagas no ano".
        $paidFeesCurrentYear = 0;
        $paidFeesPriorYears = 0;
        $paidFeesFutureYears = 0;
        global $db;
        if ($db) {
            $stmt = $db->prepare("
                SELECT COALESCE(f.period_year, :year) as period_year, COALESCE(SUM(fp.amount), 0) as total
                FROM fee_payments fp
                INNER JOIN fees f ON f.id = fp.fee_id
                WHERE f.condominium_id = :condominium_id
                AND YEAR(fp.payment_date) = :year
                GROUP BY COALESCE(f.period_year, :year)
            ");
            $stmt->execute([':condominium_id' => $condominiumId, ':year' => $year]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $py = (int)$row['period_year'];
                $tot = (float)$row['total'];
                if ($py === $year) {
                    $paidFeesCurrentYear += $tot;
                } elseif ($py < $year) {
                    $paidFeesPriorYears += $tot;
                } else {
                    $paidFeesFutureYears += $tot;
                }
            }
        }

        // Saldos restantes de movimentos com categoria Quotas não aplicados a nenhuma quota (ex.: quotas de anos
        // posteriores ainda não criadas). Contam como "quotas recebidas anos posteriores" e saem de outras receitas.
        // "Allocated" = pagamentos ligados ao movimento por financial_transaction_id OU por fraction_account_movements.
        $remainingQuotasNotApplied = 0.0;
        if ($db) {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(ft.amount - COALESCE(alloc.total, 0)), 0) as remaining_total
                FROM financial_transactions ft
                LEFT JOIN (
                    SELECT linked_ft_id as financial_transaction_id, SUM(amount) as total
                    FROM (
                        SELECT fp.id, fp.amount,
                               COALESCE(fp.financial_transaction_id,
                                   (SELECT fam.source_financial_transaction_id FROM fraction_account_movements fam
                                    WHERE fam.source_reference_id = fp.id AND fam.type = 'debit' AND fam.source_type = 'quota_application'
                                    LIMIT 1)) as linked_ft_id
                        FROM fee_payments fp
                        WHERE fp.financial_transaction_id IS NOT NULL
                           OR EXISTS (SELECT 1 FROM fraction_account_movements fam
                                      WHERE fam.source_reference_id = fp.id AND fam.type = 'debit' AND fam.source_type = 'quota_application')
                    ) pay
                    WHERE linked_ft_id IS NOT NULL
                    GROUP BY linked_ft_id
                ) alloc ON alloc.financial_transaction_id = ft.id
                WHERE ft.condominium_id = :condominium_id
                AND ft.transaction_type = 'income'
                AND (ft.related_type = 'fee_payment' OR ft.related_type = 'fraction_account' OR ft.category = 'Quotas')
                AND COALESCE(ft.related_type, '') != 'transfer'
                AND ft.transaction_date >= :start
                AND ft.transaction_date <= :end
                AND (ft.amount - COALESCE(alloc.total, 0)) > 0
            ");
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':start' => $startDate,
                ':end' => $endDate,
            ]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $remainingQuotasNotApplied = (float)($row['remaining_total'] ?? 0);
        }
        $paidFeesFutureYears += $remainingQuotasNotApplied;

        // Total receitas do período (FT income + tabela revenues)
        $totalRevenueRealized = $this->transactionModel->getTotalByPeriodAndType($condominiumId, $startDate, $endDate, 'income');
        $totalRevenueRealized += $this->revenueModel->getTotalByPeriod($condominiumId, $startDate, $endDate);
        // Outras receitas = total receitas − quotas (pagamentos aplicados + saldos restantes Quotas não aplicados)
        $totalQuotasFromPayments = $paidFeesCurrentYear + $paidFeesPriorYears + $paidFeesFutureYears;
        $otherRevenues = $totalRevenueRealized - $totalQuotasFromPayments;
        if ($otherRevenues < 0) {
            $otherRevenues = 0;
        }
        // Total quotas (ano em curso + anos anteriores) para comparação com orçamento
        $totalQuotasReceivedInYear = $paidFeesCurrentYear + $paidFeesPriorYears;

        $totalBudget = $budget['total_amount'] ?? 0;
        $pendingFees = $totalFees - $paidFees;
        // Receita e despesa orçamentadas (por categoria Receita: / Despesa:)
        $budgetRevenue = $this->budgetItemModel->getTotalByType((int)$budget['id'], 'Receita:');
        $budgetExpenses = $this->budgetItemModel->getTotalByType((int)$budget['id'], 'Despesa:');
        // Resultado realizado = total receitas realizadas − despesas
        $balance = $totalRevenueRealized - $totalExpenses;
        $budgetResultPlanned = $budgetRevenue - $budgetExpenses;
        // Diferença = Realizado − Orçamentado (positivo = a mais, negativo = a menos face ao orçamento)
        return [
            'year' => $year,
            'budget' => $budget,
            'total_budget' => $totalBudget,
            'total_expenses' => $totalExpenses,
            'total_fees' => $totalFees,
            'paid_fees' => $paidFees,
            'pending_fees' => $pendingFees,
            'paid_fees_current_year' => $paidFeesCurrentYear,
            'paid_fees_prior_years' => $paidFeesPriorYears,
            'paid_fees_future_years' => $paidFeesFutureYears,
            'remaining_quotas_not_applied' => $remainingQuotasNotApplied,
            'other_revenues' => $otherRevenues,
            'total_quotas_received_in_year' => $totalQuotasReceivedInYear,
            'total_revenue_realized' => $totalRevenueRealized,
            'balance' => $balance,
            'budget_result' => $totalBudget - $totalExpenses,
            'budget_revenue' => $budgetRevenue,
            'budget_expenses' => $budgetExpenses,
            'revenue_deviation' => $totalRevenueRealized - $budgetRevenue,
            'expense_deviation' => $totalExpenses - $budgetExpenses,
            'budget_result_planned' => $budgetResultPlanned,
            'budget_deviation' => $balance - $budgetResultPlanned,
        ];
    }

    /**
     * Debug: lista todos os pagamentos de quotas no ano para um condomínio, com ano da quota (period_year).
     * Útil para despistar Balancete (anos anteriores / adiantamentos).
     * Retorna: rows (lista por movimento), summary_by_period_year (totais por ano), summary_totals (ano em curso, anteriores, posteriores).
     */
    public function getDebugQuotasPaymentsByYear(int $condominiumId, int $year): array
    {
        global $db;
        if (!$db) {
            return ['rows' => [], 'summary_by_period_year' => [], 'summary_totals' => ['current' => 0, 'prior' => 0, 'future' => 0], 'year' => $year];
        }
        $stmt = $db->prepare("
            SELECT fp.id as payment_id, fp.payment_date, fp.amount, fp.financial_transaction_id,
                   f.id as fee_id, f.period_year, f.period_month, f.period_index, f.reference as fee_reference,
                   fr.identifier as fraction_identifier,
                   ft.category as ft_category
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            INNER JOIN fractions fr ON fr.id = f.fraction_id
            LEFT JOIN financial_transactions ft ON ft.id = fp.financial_transaction_id
            WHERE f.condominium_id = :condominium_id
            AND YEAR(fp.payment_date) = :year
            ORDER BY fp.payment_date ASC, fp.id ASC, f.period_year ASC
        ");
        $stmt->execute([':condominium_id' => $condominiumId, ':year' => $year]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $byPeriodYear = [];
        $totalCurrent = 0;
        $totalPrior = 0;
        $totalFuture = 0;
        foreach ($rows as $row) {
            $py = isset($row['period_year']) ? (int)$row['period_year'] : $year;
            $amount = (float)($row['amount'] ?? 0);
            $byPeriodYear[$py] = ($byPeriodYear[$py] ?? 0) + $amount;
            if ($py === $year) {
                $totalCurrent += $amount;
            } elseif ($py < $year) {
                $totalPrior += $amount;
            } else {
                $totalFuture += $amount;
            }
        }
        ksort($byPeriodYear);

        // Saldos restantes de movimentos Quotas no ano não aplicados a nenhuma quota (contam como anos posteriores).
        // "Allocated" = pagamentos ligados ao movimento por financial_transaction_id OU por fraction_account_movements.
        $remainingQuotasNotApplied = 0.0;
        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";
        $stmtRem = $db->prepare("
            SELECT COALESCE(SUM(ft.amount - COALESCE(alloc.total, 0)), 0) as remaining_total
            FROM financial_transactions ft
            LEFT JOIN (
                SELECT linked_ft_id as financial_transaction_id, SUM(amount) as total
                FROM (
                    SELECT fp.id, fp.amount,
                           COALESCE(fp.financial_transaction_id,
                               (SELECT fam.source_financial_transaction_id FROM fraction_account_movements fam
                                WHERE fam.source_reference_id = fp.id AND fam.type = 'debit' AND fam.source_type = 'quota_application'
                                LIMIT 1)) as linked_ft_id
                    FROM fee_payments fp
                    WHERE fp.financial_transaction_id IS NOT NULL
                       OR EXISTS (SELECT 1 FROM fraction_account_movements fam
                                  WHERE fam.source_reference_id = fp.id AND fam.type = 'debit' AND fam.source_type = 'quota_application')
                ) pay
                WHERE linked_ft_id IS NOT NULL
                GROUP BY linked_ft_id
            ) alloc ON alloc.financial_transaction_id = ft.id
            WHERE ft.condominium_id = :condominium_id
            AND ft.transaction_type = 'income'
            AND (ft.related_type = 'fee_payment' OR ft.related_type = 'fraction_account' OR ft.category = 'Quotas')
            AND COALESCE(ft.related_type, '') != 'transfer'
            AND ft.transaction_date >= :start
            AND ft.transaction_date <= :end
            AND (ft.amount - COALESCE(alloc.total, 0)) > 0
        ");
        $stmtRem->execute([
            ':condominium_id' => $condominiumId,
            ':start' => $startDate,
            ':end' => $endDate,
        ]);
        $rowRem = $stmtRem->fetch(\PDO::FETCH_ASSOC);
        $remainingQuotasNotApplied = (float)($rowRem['remaining_total'] ?? 0);
        $totalFuture += $remainingQuotasNotApplied;

        return [
            'year' => $year,
            'rows' => $rows,
            'summary_by_period_year' => $byPeriodYear,
            'summary_totals' => [
                'current' => $totalCurrent,
                'prior' => $totalPrior,
                'future' => $totalFuture,
            ],
            'remaining_quotas_not_applied' => $remainingQuotasNotApplied,
        ];
    }

    /**
     * Generate expenses by category (from financial_transactions, excluindo transferências).
     */
    public function generateExpensesByCategory(int $condominiumId, string $startDate, string $endDate): array
    {
        return $this->transactionModel->getExpensesByCategory($condominiumId, $startDate, $endDate);
    }

    /**
     * Generate expenses by category evolution over years (for chart and table).
     * Returns: years (labels), categories (unique names), data (category => [year => total]), totals_by_year.
     */
    public function generateExpensesByCategoryEvolution(int $condominiumId, int $startYear, int $endYear): array
    {
        $rows = $this->transactionModel->getExpensesByCategoryByYear($condominiumId, $startYear, $endYear);
        $years = range($startYear, $endYear);
        $categories = [];
        $data = [];
        $totalsByYear = array_fill_keys($years, 0.0);

        foreach ($rows as $row) {
            $y = (int)$row['year'];
            $cat = $row['category'];
            $total = (float)$row['total'];
            if (!in_array($cat, $categories, true)) {
                $categories[] = $cat;
            }
            if (!isset($data[$cat])) {
                $data[$cat] = array_fill_keys($years, 0.0);
            }
            $data[$cat][$y] = $total;
            if (isset($totalsByYear[$y])) {
                $totalsByYear[$y] += $total;
            }
        }

        return [
            'years' => $years,
            'categories' => $categories,
            'data' => $data,
            'totals_by_year' => $totalsByYear,
            'raw_rows' => $rows
        ];
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
     * Generate cash flow report (monthly).
     * Receitas: movimentos de entrada em todas as contas (financial_transactions income, excl. transferências) + receitas (tabela revenues).
     * Despesas: financial_transactions expense em todas as contas, excluindo transferências.
     * Saldo (todas as contas): soma dos saldos de cada conta bancária no fim do mês (já reflete transferências entre contas).
     */
    public function generateCashFlow(int $condominiumId, int $year): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $bankAccountModel = new BankAccount();
        $accounts = $bankAccountModel->getActiveAccounts($condominiumId);
        $endPrevYear = ($year - 1) . '-12-31';
        $balanceStartYear = 0.0;
        foreach ($accounts as $acc) {
            $balanceStartYear += $bankAccountModel->calculateBalanceAsOfDate((int)$acc['id'], $endPrevYear);
        }

        $cashFlow = [];
        $monthNames = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = sprintf('%04d-%02d-01', $year, $month);
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            
            // Receitas: movimentos de entrada em TODAS as contas (excl. transferências). Inclui pagamentos de quotas (registados como FT).
            $revenueIncome = $this->transactionModel->getTotalByPeriodAndType($condominiumId, $monthStart, $monthEnd, 'income');
            $revenueTable = $this->revenueModel->getTotalByPeriod($condominiumId, $monthStart, $monthEnd);
            $revenue = $revenueIncome + $revenueTable;
            
            // Despesas: todas as contas, excluindo transferências
            $expenses = $this->transactionModel->getTotalByPeriodAndType($condominiumId, $monthStart, $monthEnd, 'expense');
            
            // Saldo total (todas as contas) no fim do mês: reflete transferências entre contas corretamente
            $balanceTotal = 0.0;
            foreach ($accounts as $acc) {
                $balanceTotal += $bankAccountModel->calculateBalanceAsOfDate((int)$acc['id'], $monthEnd);
            }
            
            $cashFlow[] = [
                'month' => $month,
                'month_name' => $monthNames[$month] ?? date('F', strtotime($monthStart)),
                'revenue' => $revenue,
                'expenses' => $expenses,
                'net' => $revenue - $expenses,
                'balance_total' => $balanceTotal
            ];
        }
        
        return [
            'year' => $year,
            'balance_start_year' => $balanceStartYear,
            'cash_flow' => $cashFlow,
            'total_revenue' => array_sum(array_column($cashFlow, 'revenue')),
            'total_expenses' => array_sum(array_column($cashFlow, 'expenses')),
            'net_total' => array_sum(array_column($cashFlow, 'net'))
        ];
    }

    /**
     * Generate budget vs actual comparison.
     * Receitas realizadas: fee_payments + movimentos de entrada (excl. transferências) + tabela revenues.
     * Despesas realizadas: financial_transactions expense, excluindo transferências.
     */
    public function generateBudgetVsActual(int $condominiumId, int $year): array
    {
        $budget = $this->budgetModel->getByCondominiumAndYear($condominiumId, $year);
        
        if (!$budget) {
            return ['no_budget' => true, 'year' => $year];
        }

        global $db;
        if (!$db) {
            return [];
        }

        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";

        // Get budget items
        $stmt = $db->prepare("
            SELECT * FROM budget_items
            WHERE budget_id = :budget_id
            ORDER BY sort_order
        ");
        $stmt->execute([':budget_id' => $budget['id']]);
        $budgetItems = $stmt->fetchAll() ?: [];

        // Despesas realizadas por categoria (evita repetir o total global em todas as linhas)
        $expensesByCategory = [];
        foreach ($this->transactionModel->getExpensesByCategory($condominiumId, $startDate, $endDate) as $row) {
            $expensesByCategory[$row['category']] = (float)$row['total'];
        }

        // Receitas realizadas por categoria: quotas (fee_payments) + movimentos de entrada por categoria + tabela revenues por categoria
        $feePaymentsTotal = 0;
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(fp.amount), 0) as total
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            WHERE f.condominium_id = :condominium_id
            AND YEAR(fp.payment_date) = :year
        ");
        $stmt->execute([':condominium_id' => $condominiumId, ':year' => $year]);
        $result = $stmt->fetch();
        $feePaymentsTotal = (float)($result['total'] ?? 0);

        $incomesByCategory = [];
        foreach ($this->transactionModel->getIncomesByCategory($condominiumId, $startDate, $endDate) as $row) {
            $incomesByCategory[$row['category']] = (float)$row['total'];
        }

        $revenuesByCategory = [];
        foreach ($this->revenueModel->getRevenuesByCategory($condominiumId, $startDate, $endDate) as $row) {
            $revenuesByCategory[$row['category']] = (float)$row['total'];
        }

        $comparison = [];
        $totalBudgeted = 0;
        $totalActual = 0;

        foreach ($budgetItems as $item) {
            $isRevenue = strpos($item['category'], 'Receita:') === 0;
            $category = str_replace(['Receita: ', 'Despesa: '], '', $item['category']);

            $actual = 0;
            if ($isRevenue) {
                // Receitas realizadas por categoria: Quotas = fee_payments + entradas/revenues na mesma categoria
                $actual = ($incomesByCategory[$category] ?? 0) + ($revenuesByCategory[$category] ?? 0);
                if (strcasecmp($category, 'Quotas') === 0) {
                    $actual += $feePaymentsTotal;
                }
            } else {
                // Despesas realizadas: total da categoria (não o total global)
                $actual = $expensesByCategory[$category] ?? 0;
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
            AND f.status IN ('pending', 'overdue')
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
     * Generate summary financial report.
     * Receitas: fee_payments + movimentos de entrada (excl. transferências) + tabela revenues.
     * Despesas: financial_transactions expense, excluindo transferências.
     */
    public function generateSummaryReport(int $condominiumId, string $startDate, string $endDate): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        // Receitas: apenas movimentos de entrada (excl. transferências) + tabela revenues.
        // Não somar fee_payments em separado: os pagamentos de quotas já estão em financial_transactions
        // como income com related_type = 'fee_payment'; somar fee_payments duplicaria as quotas.
        $totalRevenue = $this->transactionModel->getTotalByPeriodAndType($condominiumId, $startDate, $endDate, 'income');
        $totalRevenue += $this->revenueModel->getTotalByPeriod($condominiumId, $startDate, $endDate);

        // Despesas: financial_transactions expense, excluindo transferências
        $totalExpenses = $this->transactionModel->getTotalByPeriodAndType($condominiumId, $startDate, $endDate, 'expense');

        // Saldos em contas: por conta (início, fim, diferença) e totais
        $bankAccountModel = new BankAccount();
        $accounts = $bankAccountModel->getActiveAccounts($condominiumId);
        $dayBeforeStart = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $accountsBalances = [];
        $balanceStart = 0.0;
        $balanceEnd = 0.0;
        foreach ($accounts as $acc) {
            $start = $bankAccountModel->calculateBalanceAsOfDate((int)$acc['id'], $dayBeforeStart);
            $end = $bankAccountModel->calculateBalanceAsOfDate((int)$acc['id'], $endDate);
            $balanceStart += $start;
            $balanceEnd += $end;
            $accountsBalances[] = [
                'name' => $acc['name'] ?? 'Conta',
                'balance_start' => $start,
                'balance_end' => $end,
                'balance_variation' => $end - $start
            ];
        }
        $balanceVariation = $balanceEnd - $balanceStart;

        // Get fees summary: quotas com vencimento (due_date) no período do relatório
        // Pendentes = não pagas e data limite ainda não passou (due_date >= hoje ou sem data)
        // Em atraso = não pagas e data limite já passou (due_date < hoje)
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_count,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_total,
                SUM(CASE WHEN status IN ('pending', 'overdue') AND (due_date IS NULL OR due_date >= CURDATE()) THEN amount ELSE 0 END) as pending_total,
                SUM(CASE WHEN status IN ('pending', 'overdue') AND due_date IS NOT NULL AND due_date < CURDATE() THEN amount ELSE 0 END) as overdue_total
            FROM fees
            WHERE condominium_id = :condominium_id
            AND (
                (due_date IS NOT NULL AND DATE(due_date) BETWEEN :start_date AND :end_date)
                OR (due_date IS NULL AND period_year >= YEAR(:start_date) AND period_year <= YEAR(:end_date))
            )
        ");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':start_date' => $startDate,
            ':end_date' => $endDate
        ]);
        $feesResult = $stmt->fetch();
        
        $balance = $totalRevenue - $totalExpenses;

        // Despesas por categoria (financial_transactions)
        $expensesByCategory = $this->transactionModel->getExpensesByCategory($condominiumId, $startDate, $endDate);

        // Receitas/Quotas por categoria: movimentos de entrada (FT) + tabela revenues, agrupados por categoria
        $incomesByCategory = $this->transactionModel->getIncomesByCategory($condominiumId, $startDate, $endDate);
        $revenuesByCategory = $this->revenueModel->getRevenuesByCategory($condominiumId, $startDate, $endDate);
        $revenuesByCategoryMerged = [];
        foreach ($incomesByCategory as $row) {
            $cat = $row['category'] ?? 'Sem categoria';
            if (!isset($revenuesByCategoryMerged[$cat])) {
                $revenuesByCategoryMerged[$cat] = ['category' => $cat, 'total' => 0.0, 'count' => 0];
            }
            $revenuesByCategoryMerged[$cat]['total'] += (float)$row['total'];
            $revenuesByCategoryMerged[$cat]['count'] += (int)($row['count'] ?? 0);
        }
        foreach ($revenuesByCategory as $row) {
            $cat = $row['category'] ?? 'Sem categoria';
            if (!isset($revenuesByCategoryMerged[$cat])) {
                $revenuesByCategoryMerged[$cat] = ['category' => $cat, 'total' => 0.0, 'count' => 0];
            }
            $revenuesByCategoryMerged[$cat]['total'] += (float)$row['total'];
            $revenuesByCategoryMerged[$cat]['count'] += (int)($row['count'] ?? 0);
        }
        usort($revenuesByCategoryMerged, function ($a, $b) {
            return $b['total'] <=> $a['total'];
        });

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'balance' => $balance,
            'balance_start' => $balanceStart,
            'balance_end' => $balanceEnd,
            'balance_variation' => $balanceVariation,
            'accounts_balances' => $accountsBalances,
            'expenses_by_category' => $expensesByCategory,
            'revenues_by_category' => array_values($revenuesByCategoryMerged),
            'fees' => [
                'total_count' => (int)($feesResult['total_count'] ?? 0),
                'paid_total' => (float)($feesResult['paid_total'] ?? 0),
                'pending_total' => (float)($feesResult['pending_total'] ?? 0),
                'overdue_total' => (float)($feesResult['overdue_total'] ?? 0)
            ]
        ];
    }
}





