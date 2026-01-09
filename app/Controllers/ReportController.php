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

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderExpensesReportHtml($report, $year);
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
}





