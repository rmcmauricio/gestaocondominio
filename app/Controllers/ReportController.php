<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Condominium;
use App\Services\ReportService;

class ReportController extends Controller
{
    protected $condominiumModel;
    protected $reportService;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
        $this->reportService = new ReportService();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $currentYear = date('Y');

        $this->loadPageTranslations('reports');
        
        $this->data += [
            'viewName' => 'pages/finances/reports.html.twig',
            'page' => ['titulo' => 'Relatórios'],
            'condominium' => $condominium,
            'current_year' => $currentYear,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function balanceSheet(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $report = $this->reportService->generateBalanceSheet($condominiumId, $year);

        // For now, just display the report data
        // In production, generate PDF using a library like TCPDF or DomPDF
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderBalanceSheetHtml($report);
        exit;
    }

    public function feesReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $month = !empty($_POST['month']) ? (int)$_POST['month'] : null;
        
        $report = $this->reportService->generateFeesReport($condominiumId, $year, $month);

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderFeesReportHtml($report);
        exit;
    }

    public function expensesReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";
        
        $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);

        $format = $_POST['format'] ?? 'html';
        
        if ($format === 'excel') {
            $this->exportExpensesToExcel($report, $year);
            exit;
        } elseif ($format === 'pdf') {
            $this->exportExpensesToPdf($report, $year);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderExpensesReportHtml($report, $year);
        exit;
    }

    public function cashFlow(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $report = $this->reportService->generateCashFlow($condominiumId, $year);

        $format = $_POST['format'] ?? 'html';
        
        if ($format === 'excel') {
            $this->exportCashFlowToExcel($report, $year);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderCashFlowHtml($report, $year);
        exit;
    }

    public function budgetVsActual(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);

        $format = $_POST['format'] ?? 'html';
        
        if ($format === 'excel') {
            $this->exportBudgetVsActualToExcel($report, $year);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderBudgetVsActualHtml($report, $year);
        exit;
    }

    public function delinquencyReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $report = $this->reportService->generateDelinquencyReport($condominiumId);

        $format = $_POST['format'] ?? 'html';
        
        if ($format === 'excel') {
            $this->exportDelinquencyToExcel($report);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderDelinquencyHtml($report);
        exit;
    }

    protected function renderBalanceSheetHtml(array $report): string
    {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Balancete {$report['year']}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .total { font-weight: bold; }
            </style>
        </head>
        <body>
            <h1>Balancete {$report['year']}</h1>
            <table>
                <tr>
                    <th>Descrição</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>Orçamento</td>
                    <td>€" . number_format($report['total_budget'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Despesas</td>
                    <td>€" . number_format($report['total_expenses'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas Recebidas</td>
                    <td>€" . number_format($report['paid_fees'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas Pendentes</td>
                    <td>€" . number_format($report['pending_fees'], 2, ',', '.') . "</td>
                </tr>
                <tr class='total'>
                    <td>Saldo</td>
                    <td>€" . number_format($report['balance'], 2, ',', '.') . "</td>
                </tr>
            </table>
        </body>
        </html>";
    }

    protected function renderFeesReportHtml(array $report): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Relatório de Quotas</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Relatório de Quotas - {$report['year']}" . ($report['month'] ? " / {$report['month']}" : '') . "</h1>
            <table>
                <tr>
                    <th>Fração</th>
                    <th>Período</th>
                    <th>Valor</th>
                    <th>Status</th>
                </tr>";

        foreach ($report['fees'] as $fee) {
            $html .= "
                <tr>
                    <td>{$fee['fraction_identifier']}</td>
                    <td>{$fee['period_year']}/{$fee['period_month']}</td>
                    <td>€" . number_format($fee['amount'], 2, ',', '.') . "</td>
                    <td>{$fee['status']}</td>
                </tr>";
        }

        $html .= "
            </table>
            <h3>Resumo</h3>
            <p>Total: €" . number_format($report['summary']['total'], 2, ',', '.') . "</p>
            <p>Pagas: €" . number_format($report['summary']['paid'], 2, ',', '.') . "</p>
            <p>Pendentes: €" . number_format($report['summary']['pending'], 2, ',', '.') . "</p>
        </body>
        </html>";

        return $html;
    }

    protected function renderExpensesReportHtml(array $report, int $year): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Relatório de Despesas</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Relatório de Despesas por Categoria - {$year}</h1>
            <table>
                <tr>
                    <th>Categoria</th>
                    <th>Quantidade</th>
                    <th>Total</th>
                </tr>";

        $total = 0;
        foreach ($report as $item) {
            $html .= "
                <tr>
                    <td>{$item['category']}</td>
                    <td>{$item['count']}</td>
                    <td>€" . number_format($item['total'], 2, ',', '.') . "</td>
                </tr>";
            $total += $item['total'];
        }

        $html .= "
                <tr style='font-weight: bold;'>
                    <td>Total</td>
                    <td></td>
                    <td>€" . number_format($total, 2, ',', '.') . "</td>
                </tr>
            </table>
        </body>
        </html>";

        return $html;
    }

    protected function renderCashFlowHtml(array $report, int $year): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Fluxo de Caixa {$year}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background-color: #f2f2f2; text-align: left; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
            </style>
        </head>
        <body>
            <h1>Fluxo de Caixa - {$year}</h1>
            <table>
                <tr>
                    <th>Mês</th>
                    <th>Receitas</th>
                    <th>Despesas</th>
                    <th>Saldo Líquido</th>
                </tr>";

        foreach ($report['cash_flow'] as $month) {
            $netClass = $month['net'] >= 0 ? 'positive' : 'negative';
            $html .= "
                <tr>
                    <td>" . ucfirst($month['month_name']) . "</td>
                    <td>€" . number_format($month['revenue'], 2, ',', '.') . "</td>
                    <td>€" . number_format($month['expenses'], 2, ',', '.') . "</td>
                    <td class='{$netClass}'>€" . number_format($month['net'], 2, ',', '.') . "</td>
                </tr>";
        }

        $netTotalClass = $report['net_total'] >= 0 ? 'positive' : 'negative';
        $html .= "
                <tr style='font-weight: bold;'>
                    <td>Total</td>
                    <td>€" . number_format($report['total_revenue'], 2, ',', '.') . "</td>
                    <td>€" . number_format($report['total_expenses'], 2, ',', '.') . "</td>
                    <td class='{$netTotalClass}'>€" . number_format($report['net_total'], 2, ',', '.') . "</td>
                </tr>
            </table>
        </body>
        </html>";

        return $html;
    }

    protected function renderBudgetVsActualHtml(array $report, int $year): string
    {
        if (!$report['budget']) {
            return "<html><body><h1>Orçamento não encontrado para o ano {$year}</h1></body></html>";
        }

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Orçamento vs Realizado {$year}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background-color: #f2f2f2; text-align: left; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
            </style>
        </head>
        <body>
            <h1>Orçamento vs Realizado - {$year}</h1>
            <table>
                <tr>
                    <th>Categoria</th>
                    <th>Tipo</th>
                    <th>Orçado</th>
                    <th>Realizado</th>
                    <th>Variação</th>
                    <th>%</th>
                </tr>";

        foreach ($report['comparison'] as $item) {
            $varianceClass = $item['variance'] >= 0 ? 'positive' : 'negative';
            $html .= "
                <tr>
                    <td>{$item['category']}</td>
                    <td>" . ($item['type'] === 'revenue' ? 'Receita' : 'Despesa') . "</td>
                    <td>€" . number_format($item['budgeted'], 2, ',', '.') . "</td>
                    <td>€" . number_format($item['actual'], 2, ',', '.') . "</td>
                    <td class='{$varianceClass}'>€" . number_format($item['variance'], 2, ',', '.') . "</td>
                    <td class='{$varianceClass}'>" . number_format($item['variance_percent'], 2, ',', '.') . "%</td>
                </tr>";
        }

        $totalVarianceClass = $report['total_variance'] >= 0 ? 'positive' : 'negative';
        $html .= "
                <tr style='font-weight: bold;'>
                    <td colspan='2'>Total</td>
                    <td>€" . number_format($report['total_budgeted'], 2, ',', '.') . "</td>
                    <td>€" . number_format($report['total_actual'], 2, ',', '.') . "</td>
                    <td class='{$totalVarianceClass}'>€" . number_format($report['total_variance'], 2, ',', '.') . "</td>
                    <td></td>
                </tr>
            </table>
        </body>
        </html>";

        return $html;
    }

    protected function renderDelinquencyHtml(array $report): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Relatório de Inadimplência</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #dc3545; color: white; }
                .total { font-weight: bold; background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h1>Relatório de Inadimplência</h1>
            <p><strong>Total de devedores:</strong> {$report['total_delinquents']}</p>
            <p><strong>Dívida total:</strong> €" . number_format($report['total_debt'], 2, ',', '.') . "</p>
            <table>
                <tr>
                    <th>Fração</th>
                    <th>Proprietário</th>
                    <th>Email</th>
                    <th>Quotas em Atraso</th>
                    <th>Valor Total</th>
                    <th>Vencimento Mais Antigo</th>
                </tr>";

        foreach ($report['delinquents'] as $delinquent) {
            $html .= "
                <tr>
                    <td>{$delinquent['fraction_identifier']}</td>
                    <td>{$delinquent['owner_name']}</td>
                    <td>{$delinquent['owner_email']}</td>
                    <td>{$delinquent['overdue_count']}</td>
                    <td>€" . number_format($delinquent['total_debt'], 2, ',', '.') . "</td>
                    <td>" . date('d/m/Y', strtotime($delinquent['oldest_due_date'])) . "</td>
                </tr>";
        }

        $html .= "
                <tr class='total'>
                    <td colspan='4'>Total</td>
                    <td>€" . number_format($report['total_debt'], 2, ',', '.') . "</td>
                    <td></td>
                </tr>
            </table>
        </body>
        </html>";

        return $html;
    }

    protected function exportExpensesToExcel(array $report, int $year): void
    {
        $filename = "despesas_por_categoria_{$year}_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Categoria', 'Quantidade', 'Total'], ';');
        
        $total = 0;
        foreach ($report as $item) {
            fputcsv($output, [
                $item['category'],
                $item['count'],
                number_format($item['total'], 2, ',', '.')
            ], ';');
            $total += $item['total'];
        }
        
        fputcsv($output, ['Total', '', number_format($total, 2, ',', '.')], ';');
        
        fclose($output);
    }

    protected function exportExpensesToPdf(array $report, int $year): void
    {
        // For PDF, we'll use HTML that can be printed as PDF
        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderExpensesReportHtml($report, $year);
    }

    protected function exportCashFlowToExcel(array $report, int $year): void
    {
        $filename = "fluxo_caixa_{$year}_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Mês', 'Receitas', 'Despesas', 'Saldo Líquido'], ';');
        
        foreach ($report['cash_flow'] as $month) {
            fputcsv($output, [
                ucfirst($month['month_name']),
                number_format($month['revenue'], 2, ',', '.'),
                number_format($month['expenses'], 2, ',', '.'),
                number_format($month['net'], 2, ',', '.')
            ], ';');
        }
        
        fputcsv($output, [
            'Total',
            number_format($report['total_revenue'], 2, ',', '.'),
            number_format($report['total_expenses'], 2, ',', '.'),
            number_format($report['net_total'], 2, ',', '.')
        ], ';');
        
        fclose($output);
    }

    protected function exportBudgetVsActualToExcel(array $report, int $year): void
    {
        $filename = "orcamento_vs_realizado_{$year}_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Categoria', 'Tipo', 'Orçado', 'Realizado', 'Variação', '%'], ';');
        
        foreach ($report['comparison'] as $item) {
            fputcsv($output, [
                $item['category'],
                $item['type'] === 'revenue' ? 'Receita' : 'Despesa',
                number_format($item['budgeted'], 2, ',', '.'),
                number_format($item['actual'], 2, ',', '.'),
                number_format($item['variance'], 2, ',', '.'),
                number_format($item['variance_percent'], 2, ',', '.')
            ], ';');
        }
        
        fputcsv($output, [
            'Total',
            '',
            number_format($report['total_budgeted'], 2, ',', '.'),
            number_format($report['total_actual'], 2, ',', '.'),
            number_format($report['total_variance'], 2, ',', '.'),
            ''
        ], ';');
        
        fclose($output);
    }

    protected function exportDelinquencyToExcel(array $report): void
    {
        $filename = "inadimplencia_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Fração', 'Proprietário', 'Email', 'Quotas em Atraso', 'Valor Total', 'Vencimento Mais Antigo'], ';');
        
        foreach ($report['delinquents'] as $delinquent) {
            fputcsv($output, [
                $delinquent['fraction_identifier'],
                $delinquent['owner_name'],
                $delinquent['owner_email'],
                $delinquent['overdue_count'],
                number_format($delinquent['total_debt'], 2, ',', '.'),
                date('d/m/Y', strtotime($delinquent['oldest_due_date']))
            ], ';');
        }
        
        fputcsv($output, [
            'Total',
            '',
            '',
            '',
            number_format($report['total_debt'], 2, ',', '.'),
            ''
        ], ';');
        
        fclose($output);
    }

    public function occurrenceReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $startDate = $_POST['start_date'] ?? date('Y-01-01');
        $endDate = $_POST['end_date'] ?? date('Y-12-31');
        $status = $_POST['status'] ?? null;
        $priority = $_POST['priority'] ?? null;
        $category = $_POST['category'] ?? null;
        $export = $_POST['export'] ?? 'html';

        $filters = [];
        if ($status) $filters['status'] = $status;
        if ($priority) $filters['priority'] = $priority;
        if ($category) $filters['category'] = $category;

        $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);

        if ($export === 'excel') {
            $this->exportOccurrenceToExcel($report, $startDate, $endDate);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderOccurrenceReportHtml($condominiumId, $report);
        exit;
    }

    public function occurrenceBySupplierReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $startDate = $_POST['start_date'] ?? date('Y-01-01');
        $endDate = $_POST['end_date'] ?? date('Y-12-31');
        $export = $_POST['export'] ?? 'html';

        $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);

        if ($export === 'excel') {
            $this->exportOccurrenceBySupplierToExcel($report, $startDate, $endDate);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderOccurrenceBySupplierReportHtml($condominiumId, $report, $startDate, $endDate);
        exit;
    }

    protected function renderOccurrenceReportHtml(int $condominiumId, array $report): string
    {
        $condominium = $this->condominiumModel->findById($condominiumId);
        
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Relatório de Ocorrências</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;margin:20px;}table{border-collapse:collapse;width:100%;margin-top:20px;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background-color:#f2f2f2;}</style>';
        $html .= '</head><body>';
        $html .= '<h1>Relatório de Ocorrências</h1>';
        $html .= '<p><strong>Condomínio:</strong> ' . htmlspecialchars($condominium['name'] ?? '') . '</p>';
        $html .= '<p><strong>Período:</strong> ' . date('d/m/Y', strtotime($report['start_date'])) . ' a ' . date('d/m/Y', strtotime($report['end_date'])) . '</p>';
        
        $html .= '<h2>Estatísticas</h2>';
        $html .= '<p><strong>Total:</strong> ' . $report['stats']['total'] . '</p>';
        $html .= '<p><strong>Tempo Médio de Resolução:</strong> ' . $report['stats']['average_resolution_time'] . ' dias</p>';
        
        if (!empty($report['stats']['by_status'])) {
            $html .= '<h3>Por Estado</h3><ul>';
            foreach ($report['stats']['by_status'] as $status => $count) {
                $html .= '<li>' . ucfirst(str_replace('_', ' ', $status)) . ': ' . $count . '</li>';
            }
            $html .= '</ul>';
        }
        
        $html .= '<h2>Ocorrências</h2>';
        $html .= '<table><thead><tr><th>Título</th><th>Categoria</th><th>Prioridade</th><th>Estado</th><th>Reportado por</th><th>Data</th></tr></thead><tbody>';
        
        foreach ($report['occurrences'] as $occ) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($occ['title']) . '</td>';
            $html .= '<td>' . htmlspecialchars($occ['category'] ?? '-') . '</td>';
            $html .= '<td>' . ucfirst($occ['priority']) . '</td>';
            $html .= '<td>' . ucfirst(str_replace('_', ' ', $occ['status'])) . '</td>';
            $html .= '<td>' . htmlspecialchars($occ['reported_by_name'] ?? '-') . '</td>';
            $html .= '<td>' . date('d/m/Y', strtotime($occ['created_at'])) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></body></html>';
        return $html;
    }

    protected function renderOccurrenceBySupplierReportHtml(int $condominiumId, array $report, string $startDate, string $endDate): string
    {
        $condominium = $this->condominiumModel->findById($condominiumId);
        
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Relatório de Ocorrências por Fornecedor</title>';
        $html .= '<style>body{font-family:Arial,sans-serif;margin:20px;}table{border-collapse:collapse;width:100%;margin-top:20px;}th,td{border:1px solid #ddd;padding:8px;text-align:left;}th{background-color:#f2f2f2;}</style>';
        $html .= '</head><body>';
        $html .= '<h1>Relatório de Ocorrências por Fornecedor</h1>';
        $html .= '<p><strong>Condomínio:</strong> ' . htmlspecialchars($condominium['name'] ?? '') . '</p>';
        $html .= '<p><strong>Período:</strong> ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate)) . '</p>';
        
        $html .= '<table><thead><tr><th>Fornecedor</th><th>Total de Ocorrências</th><th>Resolvidas</th><th>Tempo Médio de Resolução (dias)</th></tr></thead><tbody>';
        
        foreach ($report as $supplier) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($supplier['name']) . '</td>';
            $html .= '<td>' . $supplier['total_occurrences'] . '</td>';
            $html .= '<td>' . $supplier['completed_count'] . '</td>';
            $html .= '<td>' . ($supplier['avg_resolution_days'] ? round($supplier['avg_resolution_days'], 1) : '-') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table></body></html>';
        return $html;
    }

    protected function exportOccurrenceToExcel(array $report, string $startDate, string $endDate): void
    {
        $filename = "relatorio_ocorrencias_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Relatório de Ocorrências'], ';');
        fputcsv($output, ['Período: ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate))], ';');
        fputcsv($output, [], ';');
        fputcsv($output, ['Título', 'Categoria', 'Prioridade', 'Estado', 'Reportado por', 'Atribuído a', 'Fornecedor', 'Data'], ';');
        
        foreach ($report['occurrences'] as $occ) {
            fputcsv($output, [
                $occ['title'],
                $occ['category'] ?? '',
                ucfirst($occ['priority']),
                ucfirst(str_replace('_', ' ', $occ['status'])),
                $occ['reported_by_name'] ?? '',
                $occ['assigned_to_name'] ?? '',
                $occ['supplier_name'] ?? '',
                date('d/m/Y', strtotime($occ['created_at']))
            ], ';');
        }
        
        fclose($output);
    }

    protected function exportOccurrenceBySupplierToExcel(array $report, string $startDate, string $endDate): void
    {
        $filename = "relatorio_ocorrencias_fornecedores_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, ['Relatório de Ocorrências por Fornecedor'], ';');
        fputcsv($output, ['Período: ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate))], ';');
        fputcsv($output, [], ';');
        fputcsv($output, ['Fornecedor', 'Total de Ocorrências', 'Resolvidas', 'Tempo Médio de Resolução (dias)'], ';');
        
        foreach ($report as $supplier) {
            fputcsv($output, [
                $supplier['name'],
                $supplier['total_occurrences'],
                $supplier['completed_count'],
                $supplier['avg_resolution_days'] ? round($supplier['avg_resolution_days'], 1) : ''
            ], ';');
        }
        
        fclose($output);
    }
}





