<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Condominium;
use App\Models\Document;
use App\Services\ReportService;
use App\Services\FileStorageService;

class ReportController extends Controller
{
    protected $condominiumModel;
    protected $reportService;
    protected $documentModel;
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
        $this->reportService = new ReportService();
        $this->documentModel = new Document();
        $this->fileStorageService = new FileStorageService();
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
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');

        $this->loadPageTranslations('reports');
        
        // Get and clear session messages
        $error = $_SESSION['error'] ?? null;
        $success = $_SESSION['success'] ?? null;
        unset($_SESSION['error'], $_SESSION['success']);
        
        $this->data += [
            'viewName' => 'pages/finances/reports.html.twig',
            'page' => ['titulo' => 'Relatórios'],
            'condominium' => $condominium,
            'current_year' => $currentYear,
            'csrf_token' => Security::generateCSRFToken(),
            'is_admin' => $isAdmin,
            'error' => $error,
            'success' => $success
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function balanceSheet(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $mode = $_POST['mode'] ?? '';
        $report = $this->reportService->generateBalanceSheet($condominiumId, $year);
        $html = $this->renderBalanceSheetHtml($report);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/balance-sheet/print?year=' . $year;
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Balancete ' . $year
            ], 'Relatório gerado com sucesso');
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public function feesReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $month = !empty($_POST['month']) ? (int)$_POST['month'] : null;
        $mode = $_POST['mode'] ?? '';
        
        $report = $this->reportService->generateFeesReport($condominiumId, $year, $month);
        $html = $this->renderFeesReportHtml($report);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/fees/print?year=' . $year;
            if ($month) {
                $printUrl .= '&month=' . $month;
            }
            $title = 'Relatório de Quotas - ' . $year . ($month ? ' / ' . $month : '');
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => $title
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Fees report print (GET)
     */
    public function feesReportPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $year = (int)($_GET['year'] ?? date('Y'));
        $month = !empty($_GET['month']) ? (int)$_GET['month'] : null;
        $report = $this->reportService->generateFeesReport($condominiumId, $year, $month);
        $html = $this->renderFeesReportHtml($report);
        $title = 'Relatório de Quotas - ' . $year . ($month ? ' / ' . $month : '');
        
        echo $this->renderPrintTemplate($html, $title);
        exit;
    }

    public function expensesReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";
        $format = $_POST['format'] ?? 'html';
        $mode = $_POST['mode'] ?? '';
        
        $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);
        
        if ($format === 'excel') {
            $this->exportExpensesToExcel($report, $year);
            exit;
        } elseif ($format === 'pdf') {
            $this->exportExpensesToPdf($report, $year);
            exit;
        }

        $html = $this->renderExpensesReportHtml($report, $year);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/expenses/print?year=' . $year;
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Relatório de Despesas - ' . $year
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Expenses report print (GET)
     */
    public function expensesReportPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $year = (int)($_GET['year'] ?? date('Y'));
        $startDate = "{$year}-01-01";
        $endDate = "{$year}-12-31";
        $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);
        $html = $this->renderExpensesReportHtml($report, $year);
        
        echo $this->renderPrintTemplate($html, 'Relatório de Despesas - ' . $year);
        exit;
    }

    public function cashFlow(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $format = $_POST['format'] ?? 'html';
        $mode = $_POST['mode'] ?? '';
        $report = $this->reportService->generateCashFlow($condominiumId, $year);
        
        if ($format === 'excel') {
            $this->exportCashFlowToExcel($report, $year);
            exit;
        }

        $html = $this->renderCashFlowHtml($report, $year);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/cash-flow/print?year=' . $year;
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Fluxo de Caixa - ' . $year
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Cash flow report print (GET)
     */
    public function cashFlowPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $year = (int)($_GET['year'] ?? date('Y'));
        $report = $this->reportService->generateCashFlow($condominiumId, $year);
        $html = $this->renderCashFlowHtml($report, $year);
        
        echo $this->renderPrintTemplate($html, 'Fluxo de Caixa - ' . $year);
        exit;
    }

    public function budgetVsActual(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $year = (int)($_POST['year'] ?? date('Y'));
        $format = $_POST['format'] ?? 'html';
        $mode = $_POST['mode'] ?? '';
        $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);
        
        if ($format === 'excel') {
            $this->exportBudgetVsActualToExcel($report, $year);
            exit;
        }

        $html = $this->renderBudgetVsActualHtml($report, $year);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/budget-vs-actual/print?year=' . $year;
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Orçamento vs Realizado - ' . $year
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Budget vs actual report print (GET)
     */
    public function budgetVsActualPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $year = (int)($_GET['year'] ?? date('Y'));
        $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);
        $html = $this->renderBudgetVsActualHtml($report, $year);
        
        echo $this->renderPrintTemplate($html, 'Orçamento vs Realizado - ' . $year);
        exit;
    }

    public function delinquencyReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $format = $_POST['format'] ?? 'html';
        $mode = $_POST['mode'] ?? '';
        $report = $this->reportService->generateDelinquencyReport($condominiumId);
        
        if ($format === 'excel') {
            $this->exportDelinquencyToExcel($report);
            exit;
        }

        $html = $this->renderDelinquencyHtml($report);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/delinquency/print';
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Relatório de Quotas em Atraso'
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Delinquency report print (GET)
     */
    public function delinquencyReportPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $report = $this->reportService->generateDelinquencyReport($condominiumId);
        $html = $this->renderDelinquencyHtml($report);
        
        echo $this->renderPrintTemplate($html, 'Relatório de Quotas em Atraso');
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
        // Prepare data for chart
        $paidAmount = $report['summary']['paid'] ?? 0;
        $pendingAmount = $report['summary']['pending'] ?? 0;
        $overdueAmount = $report['summary']['overdue'] ?? 0;
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Relatório de Quotas</title>
            <script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'></script>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .chart-container { margin: 30px 0; max-width: 400px; height: 400px; }
            </style>
        </head>
        <body>
            <h1>Relatório de Quotas - {$report['year']}" . ($report['month'] ? " / {$report['month']}" : '') . "</h1>
            
            <div class='chart-container'>
                <canvas id='feesStatusChart'></canvas>
            </div>
            
            <table>
                <tr>
                    <th>Fração</th>
                    <th>Período</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Data(s) de Pagamento</th>
                </tr>";

        foreach ($report['fees'] as $fee) {
            $paymentDates = '';
            if (!empty($fee['payment_dates'])) {
                $dates = explode(', ', $fee['payment_dates']);
                $formattedDates = array_map(function($date) {
                    return date('d/m/Y', strtotime($date));
                }, $dates);
                $paymentDates = implode(', ', $formattedDates);
            } else {
                $paymentDates = '-';
            }
            
            $html .= "
                <tr>
                    <td>{$fee['fraction_identifier']}</td>
                    <td>" . htmlspecialchars(\App\Models\Fee::formatPeriodForDisplay($fee)) . "</td>
                    <td>€" . number_format($fee['amount'], 2, ',', '.') . "</td>
                    <td>" . $this->translateStatus($fee['status']) . "</td>
                    <td>{$paymentDates}</td>
                </tr>";
        }

        $html .= "
            </table>
            <h3>Resumo</h3>
            <p>Total: €" . number_format($report['summary']['total'], 2, ',', '.') . "</p>
            <p>Pagas: €" . number_format($paidAmount, 2, ',', '.') . "</p>
            <p>Pendentes: €" . number_format($pendingAmount, 2, ',', '.') . "</p>
            <p>Em Atraso: €" . number_format($overdueAmount, 2, ',', '.') . "</p>
            
            <script>
                const ctx = document.getElementById('feesStatusChart');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: ['Pagas', 'Pendentes', 'Em Atraso'],
                            datasets: [{
                                data: [" . $paidAmount . ", " . $pendingAmount . ", " . $overdueAmount . "],
                                backgroundColor: [
                                    'rgba(40, 167, 69, 0.7)',
                                    'rgba(255, 206, 86, 0.7)',
                                    'rgba(220, 53, 69, 0.7)'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                            return label + ': €' + value.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + percentage + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            </script>
        </body>
        </html>";

        return $html;
    }

    protected function renderExpensesReportHtml(array $report, int $year): string
    {
        // Prepare data for chart
        $categories = [];
        $amounts = [];
        $colors = [
            'rgba(255, 99, 132, 0.7)', 'rgba(54, 162, 235, 0.7)', 'rgba(255, 206, 86, 0.7)',
            'rgba(75, 192, 192, 0.7)', 'rgba(153, 102, 255, 0.7)', 'rgba(255, 159, 64, 0.7)',
            'rgba(199, 199, 199, 0.7)', 'rgba(83, 102, 255, 0.7)', 'rgba(255, 99, 255, 0.7)',
            'rgba(99, 255, 132, 0.7)'
        ];
        
        $total = 0;
        foreach ($report as $item) {
            $categories[] = $item['category'];
            $amounts[] = $item['total'];
            $total += $item['total'];
        }
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Relatório de Despesas</title>
            <script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'></script>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .chart-container { margin: 30px 0; max-width: 600px; height: 400px; }
            </style>
        </head>
        <body>
            <h1>Relatório de Despesas por Categoria - {$year}</h1>
            
            <div class='chart-container'>
                <canvas id='expensesChart'></canvas>
            </div>
            
            <table>
                <tr>
                    <th>Categoria</th>
                    <th>Quantidade</th>
                    <th>Total</th>
                </tr>";

        foreach ($report as $item) {
            $html .= "
                <tr>
                    <td>{$item['category']}</td>
                    <td>{$item['count']}</td>
                    <td>€" . number_format($item['total'], 2, ',', '.') . "</td>
                </tr>";
        }

        $html .= "
                <tr style='font-weight: bold;'>
                    <td>Total</td>
                    <td></td>
                    <td>€" . number_format($total, 2, ',', '.') . "</td>
                </tr>
            </table>
            
            <script>
                const ctx = document.getElementById('expensesChart');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: " . json_encode($categories) . ",
                            datasets: [{
                                data: " . json_encode($amounts) . ",
                                backgroundColor: " . json_encode(array_slice($colors, 0, count($categories))) . "
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right'
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = ((value / total) * 100).toFixed(1);
                                            return label + ': €' + value.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + percentage + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            </script>
        </body>
        </html>";

        return $html;
    }

    protected function renderCashFlowHtml(array $report, int $year): string
    {
        // Prepare data for chart
        $months = [];
        $revenues = [];
        $expenses = [];
        $nets = [];
        
        foreach ($report['cash_flow'] as $month) {
            $months[] = $month['month_name'];
            $revenues[] = $month['revenue'];
            $expenses[] = $month['expenses'];
            $nets[] = $month['net'];
        }
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Fluxo de Caixa {$year}</title>
            <script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'></script>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background-color: #f2f2f2; text-align: left; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
                .chart-container { margin: 30px 0; max-width: 100%; height: 400px; }
            </style>
        </head>
        <body>
            <h1>Fluxo de Caixa - {$year}</h1>
            
            <div class='chart-container'>
                <canvas id='cashFlowChart'></canvas>
            </div>
            
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
                    <td>{$month['month_name']}</td>
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
            
            <script>
                const ctx = document.getElementById('cashFlowChart');
                if (ctx) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: " . json_encode($months) . ",
                            datasets: [
                                {
                                    label: 'Receitas',
                                    data: " . json_encode($revenues) . ",
                                    backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                    borderColor: 'rgba(40, 167, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Despesas',
                                    data: " . json_encode($expenses) . ",
                                    backgroundColor: 'rgba(220, 53, 69, 0.7)',
                                    borderColor: 'rgba(220, 53, 69, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Saldo Líquido',
                                    data: " . json_encode($nets) . ",
                                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1,
                                    type: 'line',
                                    tension: 0.1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '€' + value.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': €' + context.parsed.y.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            </script>
        </body>
        </html>";

        return $html;
    }

    protected function renderBudgetVsActualHtml(array $report, int $year): string
    {
        if (!$report['budget']) {
            return "<html><body><h1>Orçamento não encontrado para o ano {$year}</h1></body></html>";
        }

        // Prepare data for chart - limit to top 10 categories for readability
        $categories = [];
        $budgeted = [];
        $actual = [];
        $topItems = array_slice($report['comparison'], 0, 10);
        
        foreach ($topItems as $item) {
            $categories[] = substr($item['category'], 0, 20) . (strlen($item['category']) > 20 ? '...' : '');
            $budgeted[] = $item['budgeted'];
            $actual[] = $item['actual'];
        }
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Orçamento vs Realizado {$year}</title>
            <script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'></script>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: right; }
                th { background-color: #f2f2f2; text-align: left; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
                .chart-container { margin: 30px 0; max-width: 100%; height: 500px; }
            </style>
        </head>
        <body>
            <h1>Orçamento vs Realizado - {$year}</h1>
            
            <div class='chart-container'>
                <canvas id='budgetChart'></canvas>
            </div>
            
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
            
            <script>
                const ctx = document.getElementById('budgetChart');
                if (ctx && " . json_encode($categories) . ".length > 0) {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: " . json_encode($categories) . ",
                            datasets: [
                                {
                                    label: 'Orçado',
                                    data: " . json_encode($budgeted) . ",
                                    backgroundColor: 'rgba(54, 162, 235, 0.7)',
                                    borderColor: 'rgba(54, 162, 235, 1)',
                                    borderWidth: 1
                                },
                                {
                                    label: 'Realizado',
                                    data: " . json_encode($actual) . ",
                                    backgroundColor: 'rgba(255, 99, 132, 0.7)',
                                    borderColor: 'rgba(255, 99, 132, 1)',
                                    borderWidth: 1
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '€' + value.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': €' + context.parsed.y.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            </script>
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
            <title>Relatório de Quotas em Atraso</title>
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
            <h1>Relatório de Quotas em Atraso</h1>
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
        $filename = "quotas_em_atraso_" . date('Y-m-d') . ".csv";
        
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
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
        $mode = $_POST['mode'] ?? '';

        $filters = [];
        if ($status) $filters['status'] = $status;
        if ($priority) $filters['priority'] = $priority;
        if ($category) $filters['category'] = $category;

        $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);

        if ($export === 'excel') {
            $this->exportOccurrenceToExcel($report, $startDate, $endDate);
            exit;
        }

        $html = $this->renderOccurrenceReportHtml($condominiumId, $report);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/occurrences/print?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
            if ($status) $printUrl .= '&status=' . urlencode($status);
            if ($priority) $printUrl .= '&priority=' . urlencode($priority);
            if ($category) $printUrl .= '&category=' . urlencode($category);
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Relatório de Ocorrências'
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Occurrence report print (GET)
     */
    public function occurrenceReportPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $startDate = $_GET['start_date'] ?? date('Y-01-01');
        $endDate = $_GET['end_date'] ?? date('Y-12-31');
        $status = $_GET['status'] ?? null;
        $priority = $_GET['priority'] ?? null;
        $category = $_GET['category'] ?? null;

        $filters = [];
        if ($status) $filters['status'] = $status;
        if ($priority) $filters['priority'] = $priority;
        if ($category) $filters['category'] = $category;

        $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);
        $html = $this->renderOccurrenceReportHtml($condominiumId, $report);
        
        echo $this->renderPrintTemplate($html, 'Relatório de Ocorrências');
        exit;
    }

    public function occurrenceBySupplierReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $startDate = $_POST['start_date'] ?? date('Y-01-01');
        $endDate = $_POST['end_date'] ?? date('Y-12-31');
        $export = $_POST['export'] ?? 'html';
        $mode = $_POST['mode'] ?? '';

        $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);

        if ($export === 'excel') {
            $this->exportOccurrenceBySupplierToExcel($report, $startDate, $endDate);
            exit;
        }

        $html = $this->renderOccurrenceBySupplierReportHtml($condominiumId, $report, $startDate, $endDate);

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/occurrences-by-supplier/print?start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => 'Relatório de Ocorrências por Fornecedor'
            ]);
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Occurrence by supplier report print (GET)
     */
    public function occurrenceBySupplierReportPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $startDate = $_GET['start_date'] ?? date('Y-01-01');
        $endDate = $_GET['end_date'] ?? date('Y-12-31');
        $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);
        $html = $this->renderOccurrenceBySupplierReportHtml($condominiumId, $report, $startDate, $endDate);
        
        echo $this->renderPrintTemplate($html, 'Relatório de Ocorrências por Fornecedor');
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
            $html .= '<td>' . $this->translatePriority($occ['priority']) . '</td>';
            $html .= '<td>' . $this->translateStatus($occ['status']) . '</td>';
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
                $this->translatePriority($occ['priority']),
                $this->translateStatus($occ['status']),
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

    /**
     * Check if request is AJAX
     */
    protected function isAjaxRequest(): bool
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }


    /**
     * Render print template
     */
    protected function renderPrintTemplate(string $htmlContent, string $title): string
    {
        return $GLOBALS['twig']->render('templates/reportPrint.html.twig', [
            'title' => $title,
            'content' => $htmlContent
        ]);
    }

    /**
     * Balance sheet print (GET)
     */
    public function balanceSheetPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $year = (int)($_GET['year'] ?? date('Y'));
        $report = $this->reportService->generateBalanceSheet($condominiumId, $year);
        $html = $this->renderBalanceSheetHtml($report);
        
        echo $this->renderPrintTemplate($html, 'Balancete ' . $year);
        exit;
    }

    /**
     * Custom report
     */
    public function custom(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Método não permitido', 405);
                return;
            }
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            if ($this->isAjaxRequest()) {
                $this->jsonError('Token de segurança inválido', 403);
            }
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
            exit;
        }

        $reportType = $_POST['report_type'] ?? '';
        $startDate = $_POST['start_date'] ?? date('Y-01-01');
        $endDate = $_POST['end_date'] ?? date('Y-12-31');
        $format = $_POST['format'] ?? 'html';
        $mode = $_POST['mode'] ?? '';

        $html = '';
        $title = '';
        $printUrl = '';

        switch ($reportType) {
            case 'balance':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateBalanceSheet($condominiumId, $year);
                $html = $this->renderBalanceSheetHtml($report);
                $title = 'Balancete ' . $year;
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/balance-sheet/print?year=' . $year;
                break;

            case 'fees':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateFeesReport($condominiumId, $year);
                $html = $this->renderFeesReportHtml($report);
                $title = 'Relatório de Quotas - ' . $year;
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/fees/print?year=' . $year;
                break;

            case 'expenses':
                $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);
                $year = (int)date('Y', strtotime($startDate));
                $html = $this->renderExpensesReportHtml($report, $year);
                $title = 'Relatório de Despesas - ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate));
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/expenses/print?year=' . $year;
                break;

            case 'cash-flow':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateCashFlow($condominiumId, $year);
                $html = $this->renderCashFlowHtml($report, $year);
                $title = 'Fluxo de Caixa - ' . $year;
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/cash-flow/print?year=' . $year;
                break;

            case 'budget-vs-actual':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);
                $html = $this->renderBudgetVsActualHtml($report, $year);
                $title = 'Orçamento vs Realizado - ' . $year;
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/budget-vs-actual/print?year=' . $year;
                break;

            case 'delinquency':
                $report = $this->reportService->generateDelinquencyReport($condominiumId);
                $html = $this->renderDelinquencyHtml($report);
                $title = 'Relatório de Inadimplência';
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/delinquency/print';
                break;

            case 'summary':
                $report = $this->reportService->generateSummaryReport($condominiumId, $startDate, $endDate);
                $html = $this->renderSummaryReportHtml($report);
                $title = 'Resumo Financeiro - ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate));
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/custom/print?report_type=summary&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
                break;

            case 'occurrences':
                $status = $_POST['status'] ?? null;
                $priority = $_POST['priority'] ?? null;
                $category = $_POST['category'] ?? null;
                $filters = [];
                if ($status) $filters['status'] = $status;
                if ($priority) $filters['priority'] = $priority;
                if ($category) $filters['category'] = $category;
                $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);
                $html = $this->renderOccurrenceReportHtml($condominiumId, $report);
                $title = 'Relatório de Ocorrências';
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/custom/print?report_type=occurrences&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
                if ($status) $printUrl .= '&status=' . urlencode($status);
                if ($priority) $printUrl .= '&priority=' . urlencode($priority);
                if ($category) $printUrl .= '&category=' . urlencode($category);
                break;

            case 'occurrences-by-supplier':
                $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);
                $html = $this->renderOccurrenceBySupplierReportHtml($condominiumId, $report, $startDate, $endDate);
                $title = 'Relatório de Ocorrências por Fornecedor';
                $printUrl = BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports/custom/print?report_type=occurrences-by-supplier&start_date=' . urlencode($startDate) . '&end_date=' . urlencode($endDate);
                break;

            default:
                if ($this->isAjaxRequest()) {
                    $this->jsonError('Tipo de relatório inválido', 400);
                    return;
                }
                $_SESSION['error'] = 'Tipo de relatório inválido.';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
                exit;
        }

        if ($format === 'excel') {
            // Handle Excel export based on report type
            switch ($reportType) {
                case 'expenses':
                    $year = (int)date('Y', strtotime($startDate));
                    $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);
                    $this->exportExpensesToExcel($report, $year);
                    exit;
                    
                case 'cash-flow':
                    $year = (int)date('Y', strtotime($startDate));
                    $report = $this->reportService->generateCashFlow($condominiumId, $year);
                    $this->exportCashFlowToExcel($report, $year);
                    exit;
                    
                case 'budget-vs-actual':
                    $year = (int)date('Y', strtotime($startDate));
                    $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);
                    $this->exportBudgetVsActualToExcel($report, $year);
                    exit;
                    
                case 'delinquency':
                    $report = $this->reportService->generateDelinquencyReport($condominiumId);
                    $this->exportDelinquencyToExcel($report);
                    exit;
                    
                case 'occurrences':
                    $status = $_POST['status'] ?? null;
                    $priority = $_POST['priority'] ?? null;
                    $category = $_POST['category'] ?? null;
                    $filters = [];
                    if ($status) $filters['status'] = $status;
                    if ($priority) $filters['priority'] = $priority;
                    if ($category) $filters['category'] = $category;
                    $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);
                    $this->exportOccurrenceToExcel($report, $startDate, $endDate);
                    exit;
                    
                case 'occurrences-by-supplier':
                    $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);
                    $this->exportOccurrenceBySupplierToExcel($report, $startDate, $endDate);
                    exit;
                    
                default:
                    // For other types, return HTML
                    header('Content-Type: text/html; charset=utf-8');
                    echo $html;
                    exit;
            }
        }

        if ($mode === 'ajax' || $this->isAjaxRequest()) {
            $this->jsonSuccess([
                'html' => $html,
                'printUrl' => $printUrl,
                'title' => $title
            ], 'Relatório gerado com sucesso');
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    /**
     * Custom report print (GET)
     */
    public function customPrint(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $reportType = $_GET['report_type'] ?? '';
        $startDate = $_GET['start_date'] ?? date('Y-01-01');
        $endDate = $_GET['end_date'] ?? date('Y-12-31');

        $html = '';
        $title = '';

        switch ($reportType) {
            case 'summary':
                $report = $this->reportService->generateSummaryReport($condominiumId, $startDate, $endDate);
                $html = $this->renderSummaryReportHtml($report);
                $title = 'Resumo Financeiro - ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate));
                break;

            case 'occurrences':
                $status = $_GET['status'] ?? null;
                $priority = $_GET['priority'] ?? null;
                $category = $_GET['category'] ?? null;
                $filters = [];
                if ($status) $filters['status'] = $status;
                if ($priority) $filters['priority'] = $priority;
                if ($category) $filters['category'] = $category;
                $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);
                $html = $this->renderOccurrenceReportHtml($condominiumId, $report);
                $title = 'Relatório de Ocorrências - ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate));
                break;

            case 'occurrences-by-supplier':
                $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);
                $html = $this->renderOccurrenceBySupplierReportHtml($condominiumId, $report, $startDate, $endDate);
                $title = 'Relatório de Ocorrências por Fornecedor - ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate));
                break;

            default:
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/finances/reports');
                exit;
        }

        echo $this->renderPrintTemplate($html, $title);
        exit;
    }

    /**
     * Save report to documents
     */
    public function saveReport(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->jsonError('Método não permitido', 405);
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $this->jsonError('Token de segurança inválido', 403);
        }

        $reportHtml = $_POST['report_html'] ?? '';
        $reportTitle = $_POST['report_title'] ?? 'Relatório';
        $reportType = $_POST['report_type'] ?? '';
        $reportParams = json_decode($_POST['report_params'] ?? '{}', true);
        $saveFormat = $_POST['save_format'] ?? 'html';

        if (empty($reportHtml) && $saveFormat !== 'pdf') {
            $this->jsonError('Conteúdo do relatório não fornecido', 400);
        }

        try {
            $userId = AuthMiddleware::userId();
            
            $fileContent = '';
            $filename = '';
            $mimeType = '';
            $extension = '';
            
            // Generate content based on format
            switch ($saveFormat) {
                case 'pdf':
                    // Generate HTML with SVG charts for PDF
                    $reportHtmlForPdf = $this->generateReportHtmlForPdf($reportType, $condominiumId, $reportParams);
                    if (empty($reportHtmlForPdf)) {
                        // Fallback: remove Chart.js scripts from regular HTML
                        $reportHtmlForPdf = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $reportHtml);
                        $reportHtmlForPdf = preg_replace('/<canvas[^>]*>.*?<\/canvas>/is', '', $reportHtmlForPdf);
                    }
                    $fileContent = $this->generatePdfFromHtml($reportHtmlForPdf);
                    $extension = 'pdf';
                    $mimeType = 'application/pdf';
                    break;
                    
                case 'excel':
                    // Regenerate report data for Excel export
                    $startDate = $reportParams['start_date'] ?? date('Y-01-01');
                    $endDate = $reportParams['end_date'] ?? date('Y-12-31');
                    $fileContent = $this->generateExcelContent($condominiumId, $reportType, $startDate, $endDate, $reportParams);
                    if (empty($fileContent)) {
                        $this->jsonError('Erro ao gerar conteúdo Excel', 500);
                        return;
                    }
                    $extension = 'xlsx';
                    $mimeType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
                    
                case 'html':
                default:
                    $fileContent = $reportHtml;
                    $extension = 'html';
                    $mimeType = 'text/html';
                    break;
            }
            
            // Generate filename
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $reportTitle) . '.' . $extension;
            
            // Save file
            $fileData = $this->fileStorageService->saveGeneratedFile(
                $fileContent,
                $filename,
                $condominiumId,
                'documents',
                $mimeType
            );

            // Create readable description
            $description = $this->generateReadableDescription($reportTitle, $reportType, $reportParams);

            $documentId = $this->documentModel->create([
                'condominium_id' => $condominiumId,
                'fraction_id' => null,
                'folder' => 'Relatórios Financeiros',
                'title' => Security::sanitize($reportTitle),
                'description' => Security::sanitize($description),
                'file_path' => $fileData['file_path'],
                'file_name' => $fileData['file_name'],
                'file_size' => $fileData['file_size'],
                'mime_type' => $fileData['mime_type'],
                'visibility' => 'condominos',
                'document_type' => 'relatorio_financeiro',
                'uploaded_by' => $userId
            ]);

            $this->jsonSuccess([
                'document_id' => $documentId,
                'message' => 'Relatório guardado com sucesso nos documentos'
            ], 'Relatório guardado com sucesso');
        } catch (\Exception $e) {
            $this->jsonError('Erro ao guardar relatório: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Render summary report HTML
     */
    protected function renderSummaryReportHtml(array $report): string
    {
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Resumo Financeiro</title>
            <script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'></script>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .total { font-weight: bold; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
                .chart-container { margin: 30px 0; max-width: 100%; height: 300px; }
                .charts-row { display: flex; gap: 20px; flex-wrap: wrap; }
                .chart-wrapper { flex: 1; min-width: 300px; }
            </style>
        </head>
        <body>
            <h1>Resumo Financeiro</h1>
            <p><strong>Período:</strong> " . date('d/m/Y', strtotime($report['start_date'])) . " a " . date('d/m/Y', strtotime($report['end_date'])) . "</p>
            
            <div class='charts-row'>
                <div class='chart-wrapper'>
                    <div class='chart-container'>
                        <canvas id='revenueExpensesChart'></canvas>
                    </div>
                </div>
                <div class='chart-wrapper'>
                    <div class='chart-container'>
                        <canvas id='feesChart'></canvas>
                    </div>
                </div>
            </div>
            
            <h2>Receitas e Despesas</h2>
            <table>
                <tr>
                    <th>Descrição</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>Total de Receitas</td>
                    <td class='positive'>€" . number_format($report['total_revenue'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Total de Despesas</td>
                    <td class='negative'>€" . number_format($report['total_expenses'], 2, ',', '.') . "</td>
                </tr>
                <tr class='total'>
                    <td>Saldo</td>
                    <td class='" . ($report['balance'] >= 0 ? 'positive' : 'negative') . "'>€" . number_format($report['balance'], 2, ',', '.') . "</td>
                </tr>
            </table>

            <h2>Quotas</h2>
            <table>
                <tr>
                    <th>Descrição</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>Total de Quotas</td>
                    <td>€" . number_format($report['fees']['paid_total'] + $report['fees']['pending_total'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas Pagas</td>
                    <td class='positive'>€" . number_format($report['fees']['paid_total'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas Pendentes</td>
                    <td class='negative'>€" . number_format($report['fees']['pending_total'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas em Atraso</td>
                    <td class='negative'>€" . number_format($report['fees']['overdue_total'], 2, ',', '.') . "</td>
                </tr>
            </table>
            
            <script>
                // Receitas vs Despesas Chart
                const ctx1 = document.getElementById('revenueExpensesChart');
                if (ctx1) {
                    new Chart(ctx1, {
                        type: 'bar',
                        data: {
                            labels: ['Receitas', 'Despesas'],
                            datasets: [{
                                label: 'Valor (€)',
                                data: [" . $report['total_revenue'] . ", " . $report['total_expenses'] . "],
                                backgroundColor: ['rgba(40, 167, 69, 0.7)', 'rgba(220, 53, 69, 0.7)'],
                                borderColor: ['rgba(40, 167, 69, 1)', 'rgba(220, 53, 69, 1)'],
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '€' + value.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                        }
                                    }
                                }
                            },
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return '€' + context.parsed.y.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
                
                // Fees Chart
                const ctx2 = document.getElementById('feesChart');
                if (ctx2) {
                    new Chart(ctx2, {
                        type: 'pie',
                        data: {
                            labels: ['Pagas', 'Pendentes', 'Em Atraso'],
                            datasets: [{
                                data: [" . $report['fees']['paid_total'] . ", " . $report['fees']['pending_total'] . ", " . $report['fees']['overdue_total'] . "],
                                backgroundColor: [
                                    'rgba(40, 167, 69, 0.7)',
                                    'rgba(255, 206, 86, 0.7)',
                                    'rgba(220, 53, 69, 0.7)'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed || 0;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                            return label + ': €' + value.toLocaleString('pt-PT', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' (' + percentage + '%)';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            </script>
        </body>
        </html>";

        return $html;
    }

    /**
     * Generate PDF from HTML content
     */
    /**
     * Generate report HTML with SVG charts for PDF export
     */
    protected function generateReportHtmlForPdf(string $reportType, int $condominiumId, array $params): string
    {
        $startDate = $params['start_date'] ?? date('Y-01-01');
        $endDate = $params['end_date'] ?? date('Y-12-31');
        $year = (int)date('Y', strtotime($startDate));
        
        switch ($reportType) {
            case 'cash-flow':
                $report = $this->reportService->generateCashFlow($condominiumId, $year);
                return $this->renderCashFlowHtmlForPdf($report, $year);
                
            case 'expenses':
                $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);
                return $this->renderExpensesReportHtmlForPdf($report, $year);
                
            case 'budget-vs-actual':
                $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);
                return $this->renderBudgetVsActualHtmlForPdf($report, $year);
                
            case 'summary':
                $report = $this->reportService->generateSummaryReport($condominiumId, $startDate, $endDate);
                return $this->renderSummaryReportHtmlForPdf($report);
                
            case 'fees':
                $month = isset($params['month']) ? (int)$params['month'] : null;
                $report = $this->reportService->generateFeesReport($condominiumId, $year, $month);
                return $this->renderFeesReportHtmlForPdf($report);
                
            default:
                // For other types, generate regular HTML without Chart.js
                // This will be handled by the calling method
                return '';
        }
    }
    
    /**
     * Render cash flow HTML with SVG chart for PDF
     */
    protected function renderCashFlowHtmlForPdf(array $report, int $year): string
    {
        $months = [];
        $revenues = [];
        $expenses = [];
        $nets = [];
        
        foreach ($report['cash_flow'] as $month) {
            $months[] = $month['month_name'];
            $revenues[] = $month['revenue'];
            $expenses[] = $month['expenses'];
            $nets[] = $month['net'];
        }
        
        $chartSvg = $this->generateBarChartSvg($months, [
            ['label' => 'Receitas', 'data' => $revenues],
            ['label' => 'Despesas', 'data' => $expenses],
            ['label' => 'Saldo Líquido', 'data' => $nets]
        ]);
        
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
            
            {$chartSvg}
            
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
                    <td>{$month['month_name']}</td>
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
    
    /**
     * Render expenses report HTML with SVG chart for PDF
     */
    protected function renderExpensesReportHtmlForPdf(array $report, int $year): string
    {
        $categories = [];
        $amounts = [];
        $total = 0;
        
        foreach ($report as $item) {
            $categories[] = $item['category'];
            $amounts[] = $item['total'];
            $total += $item['total'];
        }
        
        $chartSvg = $this->generatePieChartSvg($categories, $amounts);
        
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
            
            {$chartSvg}
            
            <table>
                <tr>
                    <th>Categoria</th>
                    <th>Quantidade</th>
                    <th>Total</th>
                </tr>";

        foreach ($report as $item) {
            $html .= "
                <tr>
                    <td>{$item['category']}</td>
                    <td>{$item['count']}</td>
                    <td>€" . number_format($item['total'], 2, ',', '.') . "</td>
                </tr>";
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
    
    /**
     * Render budget vs actual HTML with SVG chart for PDF
     */
    protected function renderBudgetVsActualHtmlForPdf(array $report, int $year): string
    {
        if (!$report['budget']) {
            return "<html><body><h1>Orçamento não encontrado para o ano {$year}</h1></body></html>";
        }

        $categories = [];
        $budgeted = [];
        $actual = [];
        $topItems = array_slice($report['comparison'], 0, 10);
        
        foreach ($topItems as $item) {
            $categories[] = substr($item['category'], 0, 20);
            $budgeted[] = $item['budgeted'];
            $actual[] = $item['actual'];
        }
        
        $chartSvg = $this->generateBarChartSvg($categories, [
            ['label' => 'Orçado', 'data' => $budgeted],
            ['label' => 'Realizado', 'data' => $actual]
        ]);

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
            
            {$chartSvg}
            
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
    
    /**
     * Render summary report HTML with SVG charts for PDF
     */
    protected function renderSummaryReportHtmlForPdf(array $report): string
    {
        $revenueExpensesChart = $this->generateBarChartSvg(
            ['Receitas', 'Despesas'],
            [['label' => 'Valor', 'data' => [$report['total_revenue'], $report['total_expenses']]]]
        );
        
        $feesChart = $this->generatePieChartSvg(
            ['Pagas', 'Pendentes', 'Em Atraso'],
            [$report['fees']['paid_total'], $report['fees']['pending_total'], $report['fees']['overdue_total']]
        );
        
        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Resumo Financeiro</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 40px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .total { font-weight: bold; }
                .positive { color: #28a745; }
                .negative { color: #dc3545; }
                .charts-row { display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0; }
                .chart-wrapper { flex: 1; min-width: 300px; }
            </style>
        </head>
        <body>
            <h1>Resumo Financeiro</h1>
            <p><strong>Período:</strong> " . date('d/m/Y', strtotime($report['start_date'])) . " a " . date('d/m/Y', strtotime($report['end_date'])) . "</p>
            
            <div class='charts-row'>
                <div class='chart-wrapper'>{$revenueExpensesChart}</div>
                <div class='chart-wrapper'>{$feesChart}</div>
            </div>
            
            <h2>Receitas e Despesas</h2>
            <table>
                <tr>
                    <th>Descrição</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>Total de Receitas</td>
                    <td class='positive'>€" . number_format($report['total_revenue'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Total de Despesas</td>
                    <td class='negative'>€" . number_format($report['total_expenses'], 2, ',', '.') . "</td>
                </tr>
                <tr class='total'>
                    <td>Saldo</td>
                    <td class='" . ($report['balance'] >= 0 ? 'positive' : 'negative') . "'>€" . number_format($report['balance'], 2, ',', '.') . "</td>
                </tr>
            </table>

            <h2>Quotas</h2>
            <table>
                <tr>
                    <th>Descrição</th>
                    <th>Valor</th>
                </tr>
                <tr>
                    <td>Total de Quotas</td>
                    <td>€" . number_format($report['fees']['paid_total'] + $report['fees']['pending_total'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas Pagas</td>
                    <td class='positive'>€" . number_format($report['fees']['paid_total'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas Pendentes</td>
                    <td class='negative'>€" . number_format($report['fees']['pending_total'], 2, ',', '.') . "</td>
                </tr>
                <tr>
                    <td>Quotas em Atraso</td>
                    <td class='negative'>€" . number_format($report['fees']['overdue_total'], 2, ',', '.') . "</td>
                </tr>
            </table>
        </body>
        </html>";

        return $html;
    }
    
    /**
     * Render fees report HTML with SVG chart for PDF
     */
    protected function renderFeesReportHtmlForPdf(array $report): string
    {
        $paidAmount = $report['summary']['paid'] ?? 0;
        $pendingAmount = $report['summary']['pending'] ?? 0;
        $overdueAmount = $report['summary']['overdue'] ?? 0;
        
        $chartSvg = $this->generatePieChartSvg(
            ['Pagas', 'Pendentes', 'Em Atraso'],
            [$paidAmount, $pendingAmount, $overdueAmount]
        );
        
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
            
            {$chartSvg}
            
            <table>
                <tr>
                    <th>Fração</th>
                    <th>Período</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Data(s) de Pagamento</th>
                </tr>";

        foreach ($report['fees'] as $fee) {
            $paymentDates = '';
            if (!empty($fee['payment_dates'])) {
                $dates = explode(', ', $fee['payment_dates']);
                $formattedDates = array_map(function($date) {
                    return date('d/m/Y', strtotime($date));
                }, $dates);
                $paymentDates = implode(', ', $formattedDates);
            } else {
                $paymentDates = '-';
            }
            
            $html .= "
                <tr>
                    <td>{$fee['fraction_identifier']}</td>
                    <td>" . htmlspecialchars(\App\Models\Fee::formatPeriodForDisplay($fee)) . "</td>
                    <td>€" . number_format($fee['amount'], 2, ',', '.') . "</td>
                    <td>" . $this->translateStatus($fee['status']) . "</td>
                    <td>{$paymentDates}</td>
                </tr>";
        }

        $html .= "
            </table>
            <h3>Resumo</h3>
            <p>Total: €" . number_format($report['summary']['total'], 2, ',', '.') . "</p>
            <p>Pagas: €" . number_format($paidAmount, 2, ',', '.') . "</p>
            <p>Pendentes: €" . number_format($pendingAmount, 2, ',', '.') . "</p>
            <p>Em Atraso: €" . number_format($overdueAmount, 2, ',', '.') . "</p>
        </body>
        </html>";

        return $html;
    }

    protected function generatePdfFromHtml(string $htmlContent): string
    {
        // Use DomPDF to generate PDF
        if (!class_exists('\Dompdf\Dompdf')) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
        }
        
        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($htmlContent);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            return $dompdf->output();
        } catch (\Exception $e) {
            // Fallback to HTML if PDF generation fails
            error_log("PDF generation error: " . $e->getMessage());
            return $htmlContent;
        }
    }
    
    /**
     * Generate pie chart SVG
     */
    protected function generatePieChartSvg(array $labels, array $data, int $width = 400, int $height = 400): string
    {
        if (empty($data) || array_sum($data) == 0) {
            return '';
        }
        
        $colors = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#af7aa1', '#ff9d9a', '#9c755f', '#bab0ac'];
        
        // Adjust layout: chart on left, legend on right
        $chartAreaWidth = $width * 0.55; // 55% for chart
        $legendStartX = $width * 0.6; // Legend starts at 60%
        $cx = $chartAreaWidth / 2;
        $cy = $height / 2;
        $r = min($chartAreaWidth, $height) / 3.5; // Smaller radius to leave space
        
        $total = array_sum($data);
        $startAngle = -90;
        $segments = '';
        $legend = '';
        
        // Count valid data items
        $validItems = array_filter($data, function($v) { return $v > 0; });
        $itemCount = count($validItems);
        
        // Center legend vertically
        $legendStartY = ($height / 2) - (($itemCount * 25) / 2) + 15;
        $legendIndex = 0;
        
        for ($i = 0; $i < count($data); $i++) {
            if ($data[$i] <= 0) continue;
            
            $percentage = ($data[$i] / $total) * 100;
            $arcDeg = ($percentage / 100) * 360;
            $endAngle = $startAngle + $arcDeg;
            
            $startRad = deg2rad($startAngle);
            $endRad = deg2rad($endAngle);
            
            $x1 = $cx + $r * cos($startRad);
            $y1 = $cy + $r * sin($startRad);
            $x2 = $cx + $r * cos($endRad);
            $y2 = $cy + $r * sin($endRad);
            
            $largeArc = ($arcDeg > 180) ? 1 : 0;
            $color = $colors[$i % count($colors)];
            
            $segments .= sprintf(
                '<path d="M %s %s L %s %s A %s %s 0 %d 1 %s %s Z" fill="%s" stroke="#fff" stroke-width="2"/>',
                $cx, $cy, round($x1, 2), round($y1, 2), $r, $r, $largeArc, round($x2, 2), round($y2, 2), $color
            );
            
            $label = htmlspecialchars($labels[$i] ?? 'Item ' . ($i + 1));
            $legendY = $legendStartY + ($legendIndex * 25);
            $legend .= sprintf(
                '<rect x="%d" y="%d" width="15" height="15" fill="%s"/><text x="%d" y="%d" font-size="12" font-family="Arial, sans-serif">%s: %s%%</text>',
                $legendStartX, $legendY, $color, $legendStartX + 20, $legendY + 12, $label, number_format($percentage, 1)
            );
            
            $startAngle = $endAngle;
            $legendIndex++;
        }
        
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">%s%s</svg>',
            $width, $height, $width, $height, $segments, $legend
        );
        
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="Gráfico" style="max-width: 100%; height: auto; margin: 20px 0;" />';
    }
    
    /**
     * Generate bar chart SVG
     */
    protected function generateBarChartSvg(array $labels, array $datasets, int $width = 800, int $height = 400): string
    {
        if (empty($labels) || empty($datasets)) {
            return '';
        }
        
        $padding = 60;
        $chartWidth = $width - ($padding * 2);
        $chartHeight = $height - ($padding * 2);
        $barWidth = $chartWidth / (count($labels) * (count($datasets) + 1));
        $maxValue = 0;
        
        foreach ($datasets as $dataset) {
            if (isset($dataset['data'])) {
                $maxValue = max($maxValue, max($dataset['data']));
            }
        }
        
        if ($maxValue == 0) {
            return '';
        }
        
        $scale = $chartHeight / $maxValue;
        $colors = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2'];
        
        $bars = '';
        $xAxisLabels = '';
        $yAxisLabels = '';
        
        // Y-axis labels
        for ($i = 0; $i <= 5; $i++) {
            $value = ($maxValue / 5) * $i;
            $y = $height - $padding - (($value / $maxValue) * $chartHeight);
            $yAxisLabels .= sprintf(
                '<text x="%d" y="%d" font-size="10" font-family="Arial, sans-serif" text-anchor="end">%s</text>',
                $padding - 10, $y + 4, number_format($value, 0, ',', '.')
            );
        }
        
        // Bars and X-axis labels
        for ($i = 0; $i < count($labels); $i++) {
            $x = $padding + ($i * ($chartWidth / count($labels))) + ($barWidth / 2);
            $datasetIndex = 0;
            
            foreach ($datasets as $dataset) {
                if (isset($dataset['data'][$i])) {
                    $value = $dataset['data'][$i];
                    $barHeight = $value * $scale;
                    $barX = $x + ($datasetIndex * $barWidth) - ($barWidth * count($datasets) / 2);
                    $barY = $height - $padding - $barHeight;
                    
                    $color = $colors[$datasetIndex % count($colors)];
                    $bars .= sprintf(
                        '<rect x="%s" y="%s" width="%s" height="%s" fill="%s" stroke="#333" stroke-width="1"/>',
                        $barX, $barY, $barWidth * 0.8, $barHeight, $color
                    );
                    
                    if ($barHeight > 15) {
                        $bars .= sprintf(
                            '<text x="%s" y="%s" font-size="9" font-family="Arial, sans-serif" text-anchor="middle" fill="#333">%s</text>',
                            $barX + ($barWidth * 0.4), $barY - 5, number_format($value, 0, ',', '.')
                        );
                    }
                    
                    $datasetIndex++;
                }
            }
            
            $label = htmlspecialchars(substr($labels[$i], 0, 15));
            $xAxisLabels .= sprintf(
                '<text x="%s" y="%d" font-size="10" font-family="Arial, sans-serif" text-anchor="middle">%s</text>',
                $x, $height - $padding + 20, $label
            );
        }
        
        // Legend - positioned at the top, centered
        $legend = '';
        $legendY = 20;
        $legendStartX = ($width / 2) - ((count($datasets) * 100) / 2);
        foreach ($datasets as $index => $dataset) {
            $label = $dataset['label'] ?? 'Dataset ' . ($index + 1);
            $color = $colors[$index % count($colors)];
            $legend .= sprintf(
                '<rect x="%d" y="%d" width="15" height="15" fill="%s"/><text x="%d" y="%d" font-size="11" font-family="Arial, sans-serif">%s</text>',
                $legendStartX + ($index * 100), $legendY, $color, $legendStartX + ($index * 100) + 20, $legendY + 12, htmlspecialchars($label)
            );
        }
        
        // Axes
        $axes = sprintf(
            '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#333" stroke-width="2"/>',
            $padding, $padding, $padding, $height - $padding
        );
        $axes .= sprintf(
            '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#333" stroke-width="2"/>',
            $padding, $height - $padding, $width - $padding, $height - $padding
        );
        
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d">%s%s%s%s%s</svg>',
            $width, $height, $width, $height, $axes, $bars, $xAxisLabels, $yAxisLabels, $legend
        );
        
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="Gráfico" style="max-width: 100%; height: auto; margin: 20px 0;" />';
    }

    /**
     * Generate Excel (XLSX) content for report using PhpSpreadsheet
     */
    protected function generateExcelContent(int $condominiumId, string $reportType, string $startDate, string $endDate, array $params): string
    {
        // Load PhpSpreadsheet
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            } else {
                throw new \Exception('PhpSpreadsheet não está instalado. Execute: composer require phpoffice/phpspreadsheet');
            }
        }
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Header style
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                ]
            ]
        ];
        
        // Title style
        $titleStyle = [
            'font' => [
                'bold' => true,
                'size' => 14
            ]
        ];
        
        // Total row style
        $totalStyle = [
            'font' => [
                'bold' => true
            ],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E7E6E6']
            ]
        ];
        
        $row = 1;
        
        switch ($reportType) {
            case 'balance':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateBalanceSheet($condominiumId, $year);
                $sheet->setCellValue('A' . $row, 'Balancete');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Ano: ' . $year);
                $row += 2;
                $sheet->setCellValue('A' . $row, 'Descrição');
                $sheet->setCellValue('B' . $row, 'Valor');
                $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($headerStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Orçamento');
                $sheet->setCellValue('B' . $row, number_format($report['total_budget'], 2, ',', '.'));
                $row++;
                $sheet->setCellValue('A' . $row, 'Despesas');
                $sheet->setCellValue('B' . $row, number_format($report['total_expenses'], 2, ',', '.'));
                $row++;
                $sheet->setCellValue('A' . $row, 'Quotas Recebidas');
                $sheet->setCellValue('B' . $row, number_format($report['paid_fees'], 2, ',', '.'));
                $row++;
                $sheet->setCellValue('A' . $row, 'Quotas Pendentes');
                $sheet->setCellValue('B' . $row, number_format($report['pending_fees'], 2, ',', '.'));
                $row++;
                $sheet->setCellValue('A' . $row, 'Saldo');
                $sheet->setCellValue('B' . $row, number_format($report['balance'], 2, ',', '.'));
                $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($totalStyle);
                break;
                
            case 'fees':
                $year = (int)date('Y', strtotime($startDate));
                $month = isset($params['month']) ? (int)$params['month'] : null;
                $report = $this->reportService->generateFeesReport($condominiumId, $year, $month);
                $sheet->setCellValue('A' . $row, 'Relatório de Quotas');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Ano: ' . $year . ($month ? ' / Mês: ' . $month : ''));
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Fração', 'Período', 'Valor', 'Status', 'Data(s) de Pagamento'], $headerStyle);
                foreach ($report['fees'] as $fee) {
                    $paymentDates = '';
                    if (!empty($fee['payment_dates'])) {
                        $dates = explode(', ', $fee['payment_dates']);
                        $formattedDates = array_map(function($date) {
                            return date('d/m/Y', strtotime($date));
                        }, $dates);
                        $paymentDates = implode(', ', $formattedDates);
                    } else {
                        $paymentDates = '-';
                    }
                    $this->addExcelRow($sheet, $row, [
                        $fee['fraction_identifier'],
                        \App\Models\Fee::formatPeriodForDisplay($fee),
                        number_format($fee['amount'], 2, ',', '.'),
                        $this->translateStatus($fee['status']),
                        $paymentDates
                    ]);
                }
                $row++;
                $sheet->setCellValue('A' . $row, 'Resumo');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $this->addExcelRow($sheet, $row, ['Total', number_format($report['summary']['total'], 2, ',', '.')]);
                $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($totalStyle);
                $row++;
                $this->addExcelRow($sheet, $row, ['Pagas', number_format($report['summary']['paid'] ?? 0, 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Pendentes', number_format($report['summary']['pending'] ?? 0, 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Em Atraso', number_format($report['summary']['overdue'] ?? 0, 2, ',', '.')]);
                break;
                
            case 'expenses':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateExpensesByCategory($condominiumId, $startDate, $endDate);
                $sheet->setCellValue('A' . $row, 'Relatório de Despesas por Categoria');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Período: ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate)));
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Categoria', 'Quantidade', 'Total'], $headerStyle);
                $total = 0;
                foreach ($report as $item) {
                    $this->addExcelRow($sheet, $row, [
                        $item['category'],
                        $item['count'],
                        number_format($item['total'], 2, ',', '.')
                    ]);
                    $total += $item['total'];
                }
                $this->addExcelRow($sheet, $row, ['Total', '', number_format($total, 2, ',', '.')]);
                $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($totalStyle);
                break;
                
            case 'cash-flow':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateCashFlow($condominiumId, $year);
                $sheet->setCellValue('A' . $row, 'Relatório de Fluxo de Caixa');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Ano: ' . $year);
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Mês', 'Receitas', 'Despesas', 'Saldo Líquido'], $headerStyle);
                foreach ($report['cash_flow'] as $month) {
                    $this->addExcelRow($sheet, $row, [
                        ucfirst($month['month_name']),
                        number_format($month['revenue'], 2, ',', '.'),
                        number_format($month['expenses'], 2, ',', '.'),
                        number_format($month['net'], 2, ',', '.')
                    ]);
                }
                $this->addExcelRow($sheet, $row, [
                    'Total',
                    number_format($report['total_revenue'], 2, ',', '.'),
                    number_format($report['total_expenses'], 2, ',', '.'),
                    number_format($report['net_total'], 2, ',', '.')
                ]);
                $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($totalStyle);
                break;
                
            case 'budget-vs-actual':
                $year = (int)date('Y', strtotime($startDate));
                $report = $this->reportService->generateBudgetVsActual($condominiumId, $year);
                $sheet->setCellValue('A' . $row, 'Relatório de Orçamento vs Realizado');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Ano: ' . $year);
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Categoria', 'Tipo', 'Orçado', 'Realizado', 'Variação', '%'], $headerStyle);
                foreach ($report['comparison'] as $item) {
                    $this->addExcelRow($sheet, $row, [
                        $item['category'],
                        $item['type'] === 'revenue' ? 'Receita' : 'Despesa',
                        number_format($item['budgeted'], 2, ',', '.'),
                        number_format($item['actual'], 2, ',', '.'),
                        number_format($item['variance'], 2, ',', '.'),
                        number_format($item['variance_percent'], 2, ',', '.')
                    ]);
                }
                $this->addExcelRow($sheet, $row, [
                    'Total',
                    '',
                    number_format($report['total_budgeted'], 2, ',', '.'),
                    number_format($report['total_actual'], 2, ',', '.'),
                    number_format($report['total_variance'], 2, ',', '.'),
                    ''
                ]);
                $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($totalStyle);
                break;
                
            case 'delinquency':
                $report = $this->reportService->generateDelinquencyReport($condominiumId);
                $sheet->setCellValue('A' . $row, 'Relatório de Quotas em Atraso');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Data de geração: ' . date('d/m/Y'));
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Fração', 'Proprietário', 'Email', 'Quotas em Atraso', 'Valor Total', 'Vencimento Mais Antigo'], $headerStyle);
                foreach ($report['delinquents'] as $delinquent) {
                    $this->addExcelRow($sheet, $row, [
                        $delinquent['fraction_identifier'],
                        $delinquent['owner_name'],
                        $delinquent['owner_email'],
                        $delinquent['overdue_count'],
                        number_format($delinquent['total_debt'], 2, ',', '.'),
                        date('d/m/Y', strtotime($delinquent['oldest_due_date']))
                    ]);
                }
                $this->addExcelRow($sheet, $row, [
                    'Total',
                    '',
                    '',
                    '',
                    number_format($report['total_debt'], 2, ',', '.'),
                    ''
                ]);
                $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($totalStyle);
                break;
                
            case 'occurrences':
                $status = $params['status'] ?? null;
                $priority = $params['priority'] ?? null;
                $category = $params['category'] ?? null;
                $filters = [];
                if ($status) $filters['status'] = $status;
                if ($priority) $filters['priority'] = $priority;
                if ($category) $filters['category'] = $category;
                $report = $this->reportService->generateOccurrenceReport($condominiumId, $startDate, $endDate, $filters);
                $sheet->setCellValue('A' . $row, 'Relatório de Ocorrências');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Período: ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate)));
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Título', 'Categoria', 'Prioridade', 'Estado', 'Reportado por', 'Atribuído a', 'Fornecedor', 'Data'], $headerStyle);
                foreach ($report['occurrences'] as $occ) {
                    $this->addExcelRow($sheet, $row, [
                        $occ['title'],
                        $occ['category'] ?? '',
                        $this->translatePriority($occ['priority']),
                        $this->translateStatus($occ['status']),
                        $occ['reported_by_name'] ?? '',
                        $occ['assigned_to_name'] ?? '',
                        $occ['supplier_name'] ?? '',
                        date('d/m/Y', strtotime($occ['created_at']))
                    ]);
                }
                break;
                
            case 'summary':
                $report = $this->reportService->generateSummaryReport($condominiumId, $startDate, $endDate);
                $sheet->setCellValue('A' . $row, 'Resumo Financeiro');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Período: ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate)));
                $row += 2;
                $sheet->setCellValue('A' . $row, 'Receitas e Despesas');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $this->addExcelHeader($sheet, $row, ['Descrição', 'Valor'], $headerStyle);
                $this->addExcelRow($sheet, $row, ['Total de Receitas', number_format($report['total_revenue'], 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Total de Despesas', number_format($report['total_expenses'], 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Saldo', number_format($report['balance'], 2, ',', '.')]);
                $sheet->getStyle('A' . $row . ':B' . $row)->applyFromArray($totalStyle);
                $row += 2;
                $sheet->setCellValue('A' . $row, 'Quotas');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $this->addExcelHeader($sheet, $row, ['Descrição', 'Valor'], $headerStyle);
                $this->addExcelRow($sheet, $row, ['Total de Quotas', number_format(($report['fees']['paid_total'] + $report['fees']['pending_total']), 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Quotas Pagas', number_format($report['fees']['paid_total'], 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Quotas Pendentes', number_format($report['fees']['pending_total'], 2, ',', '.')]);
                $row++;
                $this->addExcelRow($sheet, $row, ['Quotas em Atraso', number_format($report['fees']['overdue_total'], 2, ',', '.')]);
                break;
                
            case 'occurrences-by-supplier':
                $report = $this->reportService->generateOccurrenceBySupplierReport($condominiumId, $startDate, $endDate);
                $sheet->setCellValue('A' . $row, 'Relatório de Ocorrências por Fornecedor');
                $sheet->getStyle('A' . $row)->applyFromArray($titleStyle);
                $row++;
                $sheet->setCellValue('A' . $row, 'Período: ' . date('d/m/Y', strtotime($startDate)) . ' a ' . date('d/m/Y', strtotime($endDate)));
                $row += 2;
                $this->addExcelHeader($sheet, $row, ['Fornecedor', 'Total de Ocorrências', 'Resolvidas', 'Tempo Médio de Resolução (dias)'], $headerStyle);
                foreach ($report as $supplier) {
                    $this->addExcelRow($sheet, $row, [
                        $supplier['name'],
                        $supplier['total_occurrences'],
                        $supplier['completed_count'],
                        $supplier['avg_resolution_days'] ? round($supplier['avg_resolution_days'], 1) : ''
                    ]);
                }
                break;
                
            default:
                throw new \Exception('Tipo de relatório não suporta exportação para Excel');
        }
        
        // Auto-size columns
        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Write to temporary file and return content
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'excel_');
        $writer->save($tempFile);
        
        $content = file_get_contents($tempFile);
        unlink($tempFile);
        
        return $content;
        
        // Read file content
        $content = file_get_contents($tempFile);
        unlink($tempFile);
        
        return $content;
    }
    
    /**
     * Helper function to add header row to sheet
     */
    protected function addExcelHeader($sheet, &$row, array $headers, array $headerStyle)
    {
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $col++;
        }
        $lastCol = chr(ord('A') + count($headers) - 1);
        $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray($headerStyle);
        $row++;
    }
    
    /**
     * Helper function to add data row to sheet
     */
    protected function addExcelRow($sheet, &$row, array $data)
    {
        $col = 'A';
        foreach ($data as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }

    /**
     * Generate readable description for saved document
     */
    protected function generateReadableDescription(string $title, string $reportType, array $params): string
    {
        // Remove technical parameters
        $cleanParams = $params;
        unset($cleanParams['csrf_token'], $cleanParams['mode'], $cleanParams['format'], $cleanParams['export']);
        
        $typeNames = [
            'balance' => 'Balancete',
            'fees' => 'Relatório de Quotas',
            'expenses' => 'Relatório de Despesas',
            'cash-flow' => 'Fluxo de Caixa',
            'budget-vs-actual' => 'Orçamento vs Realizado',
            'delinquency' => 'Relatório de Quotas em Atraso',
            'summary' => 'Resumo Financeiro',
            'occurrences' => 'Relatório de Ocorrências',
            'occurrences-by-supplier' => 'Relatório de Ocorrências por Fornecedor'
        ];
        
        $description = $typeNames[$reportType] ?? 'Relatório Financeiro';
        
        // Add period information
        if (isset($cleanParams['start_date']) && isset($cleanParams['end_date'])) {
            $startDate = date('d/m/Y', strtotime($cleanParams['start_date']));
            $endDate = date('d/m/Y', strtotime($cleanParams['end_date']));
            if ($startDate !== $endDate) {
                $description .= ' - Período: ' . $startDate . ' a ' . $endDate;
            } else {
                $description .= ' - Data: ' . $startDate;
            }
        } elseif (isset($cleanParams['year'])) {
            $description .= ' - Ano: ' . $cleanParams['year'];
            if (isset($cleanParams['month']) && !empty($cleanParams['month'])) {
                $monthNames = [
                    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
                    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
                ];
                $description .= ' - ' . ($monthNames[(int)$cleanParams['month']] ?? 'Mês ' . $cleanParams['month']);
            }
        }
        
        // Add filters for occurrence reports
        if ($reportType === 'occurrences') {
            $filters = [];
            if (!empty($cleanParams['status'])) {
                $statusNames = ['open' => 'Abertas', 'completed' => 'Resolvidas'];
                $filters[] = 'Estado: ' . ($statusNames[$cleanParams['status']] ?? $cleanParams['status']);
            }
            if (!empty($cleanParams['priority'])) {
                $priorityNames = ['urgent' => 'Urgente', 'high' => 'Alta', 'medium' => 'Média', 'low' => 'Baixa'];
                $filters[] = 'Prioridade: ' . ($priorityNames[$cleanParams['priority']] ?? ucfirst($cleanParams['priority']));
            }
            if (!empty($cleanParams['category'])) {
                $filters[] = 'Categoria: ' . $cleanParams['category'];
            }
            if (!empty($filters)) {
                $description .= ' | Filtros: ' . implode(', ', $filters);
            }
        }
        
        return $description;
    }

    /**
     * Translate status to Portuguese
     */
    protected function translateStatus(string $status): string
    {
        $translations = [
            'paid' => 'Pago',
            'pending' => 'Pendente',
            'overdue' => 'Em Atraso',
            'open' => 'Aberta',
            'in_progress' => 'Em Progresso',
            'completed' => 'Resolvida',
            'closed' => 'Fechada',
            'cancelled' => 'Cancelada'
        ];
        
        // Handle status with underscores
        $statusKey = str_replace('_', ' ', strtolower($status));
        if (isset($translations[strtolower($status)])) {
            return $translations[strtolower($status)];
        }
        
        // If not found, capitalize first letter of each word
        return ucwords(str_replace('_', ' ', $status));
    }

    /**
     * Translate priority to Portuguese
     */
    protected function translatePriority(string $priority): string
    {
        $translations = [
            'urgent' => 'Urgente',
            'high' => 'Alta',
            'medium' => 'Média',
            'low' => 'Baixa'
        ];
        
        return $translations[strtolower($priority)] ?? ucfirst($priority);
    }
}





