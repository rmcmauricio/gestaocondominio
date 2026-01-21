<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Receipt;
use App\Models\Condominium;
use App\Models\Fraction;
use App\Models\CondominiumUser;

class ReceiptController extends Controller
{
    protected $receiptModel;
    protected $condominiumModel;

    public function __construct()
    {
        parent::__construct();
        $this->receiptModel = new Receipt();
        $this->condominiumModel = new Condominium();
    }

    /**
     * List receipts for a condominium (admin view)
     */
    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        $condominiumId = (int)($_GET['condominium_id'] ?? $_SESSION['current_condominium_id'] ?? 0);
        if ($condominiumId) {
            RoleMiddleware::requireAdminInCondominium($condominiumId);
        } else {
            RoleMiddleware::requireAdmin();
        }

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        // Get filters
        $fractionId = isset($_GET['fraction_id']) && $_GET['fraction_id'] !== '' ? (int)$_GET['fraction_id'] : null;
        $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;

        $filters = [];
        if ($fractionId) {
            $filters['fraction_id'] = $fractionId;
        }
        if ($year) {
            $filters['year'] = $year;
        }
        // Only show final receipts
        $filters['receipt_type'] = 'final';

        $receipts = $this->receiptModel->getByCondominium($condominiumId, $filters);

        // Get fractions for filter
        $fractionModel = new Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        // Get available years from database (not just from filtered receipts)
        $years = $this->receiptModel->getAvailableYears($condominiumId);
        if (empty($years)) {
            $years = [date('Y')];
        }

        $this->data += [
            'condominium' => $condominium,
            'receipts' => $receipts,
            'fractions' => $fractions,
            'selected_fraction_id' => $fractionId,
            'selected_year' => $year ?? date('Y'),
            'available_years' => $years,
            'is_admin' => true,
            'viewName' => 'pages/receipts/index.html.twig'
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * List my receipts (condomino view)
     */
    public function myReceipts()
    {
        AuthMiddleware::require();

        $userId = AuthMiddleware::userId();
        if (!$userId) {
            $_SESSION['error'] = 'Não autenticado.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }

        // Get filters
        $condominiumId = isset($_GET['condominium_id']) && $_GET['condominium_id'] !== '' ? (int)$_GET['condominium_id'] : null;
        $year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;

        $filters = [];
        if ($condominiumId) {
            $filters['condominium_id'] = $condominiumId;
        }
        if ($year) {
            $filters['year'] = $year;
        }
        // Only show final receipts
        $filters['receipt_type'] = 'final';

        $receipts = $this->receiptModel->getByUser($userId, $filters);

        // Get condominiums for filter - get all user condominiums, not just from receipts
        $condominiumUserModel = new CondominiumUser();
        $userCondominiums = $condominiumUserModel->getUserCondominiums($userId);
        
        $condominiums = [];
        foreach ($userCondominiums as $uc) {
            if (!isset($condominiums[$uc['condominium_id']])) {
                $condominium = $this->condominiumModel->findById($uc['condominium_id']);
                if ($condominium) {
                    $condominiums[$uc['condominium_id']] = $condominium;
                }
            }
        }
        
        // If user is admin, also get condominiums they own
        $user = AuthMiddleware::user();
        // Note: This is a global check for receipts page, not per-condominium
        if (RoleMiddleware::isAdmin() || ($user['role'] ?? '') === 'super_admin') {
            $adminCondominiums = $this->condominiumModel->getByUserId($userId);
            foreach ($adminCondominiums as $condominium) {
                if (!isset($condominiums[$condominium['id']])) {
                    $condominiums[$condominium['id']] = $condominium;
                }
            }
        }

        // Get available years from database (not just from filtered receipts)
        $years = $this->receiptModel->getAvailableYearsByUser($userId);
        if (empty($years)) {
            $years = [date('Y')];
        }

        $this->data += [
            'receipts' => $receipts,
            'condominiums' => $condominiums,
            'selected_condominium_id' => $condominiumId,
            'selected_year' => $year ?? date('Y'),
            'available_years' => $years,
            'is_admin' => false,
            'viewName' => 'pages/receipts/index.html.twig'
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Show receipt details
     */
    public function show(int $condominiumId, int $receiptId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $receipt = $this->receiptModel->findById($receiptId);
        if (!$receipt || $receipt['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Recibo não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/receipts');
            exit;
        }

        // Check permissions: condomino can only see their own receipts
        $userId = AuthMiddleware::userId();
        $user = AuthMiddleware::user();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        if (!$isAdmin && $user['role'] !== 'super_admin') {
            // Check if user owns the fraction
            global $db;
            $stmt = $db->prepare("
                SELECT id FROM condominium_users 
                WHERE user_id = :user_id 
                AND condominium_id = :condominium_id 
                AND fraction_id = :fraction_id
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId,
                ':fraction_id' => $receipt['fraction_id']
            ]);
            
            if (!$stmt->fetch()) {
                $_SESSION['error'] = 'Não tem permissão para ver este recibo.';
                header('Location: ' . BASE_URL . 'receipts');
                exit;
            }
        }

        $condominium = $this->condominiumModel->findById($condominiumId);
        
        $this->data += [
            'receipt' => $receipt,
            'condominium' => $condominium,
            'viewName' => 'pages/receipts/show.html.twig'
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    /**
     * Download receipt PDF
     */
    public function download(int $condominiumId, int $receiptId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $receipt = $this->receiptModel->findById($receiptId);
        if (!$receipt || $receipt['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Recibo não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/receipts');
            exit;
        }

        // Check permissions: condomino can only download their own receipts
        $userId = AuthMiddleware::userId();
        $user = AuthMiddleware::user();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        if (!$isAdmin && $user['role'] !== 'super_admin') {
            // Check if user owns the fraction
            global $db;
            $stmt = $db->prepare("
                SELECT id FROM condominium_users 
                WHERE user_id = :user_id 
                AND condominium_id = :condominium_id 
                AND fraction_id = :fraction_id
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $condominiumId,
                ':fraction_id' => $receipt['fraction_id']
            ]);
            
            if (!$stmt->fetch()) {
                $_SESSION['error'] = 'Não tem permissão para descarregar este recibo.';
                header('Location: ' . BASE_URL . 'receipts');
                exit;
            }
        }

        // Handle both old path (receipts/) and new path (condominiums/{id}/receipts/)
        $filePath = $receipt['file_path'];
        if (strpos($filePath, 'condominiums/') !== 0) {
            // Old path format - try old location first, then construct new path
            $oldPath = __DIR__ . '/../../storage/documents/' . $filePath;
            if (file_exists($oldPath)) {
                $filePath = $oldPath;
            } else {
                // Construct new path from receipt data
                $filePath = __DIR__ . '/../../storage/condominiums/' . $condominiumId . '/receipts/' . basename($receipt['file_path']);
            }
        } else {
            // New path format
            $filePath = __DIR__ . '/../../storage/' . $receipt['file_path'];
        }
        
        if (!file_exists($filePath)) {
            $_SESSION['error'] = 'Ficheiro do recibo não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/receipts/' . $receiptId);
            exit;
        }

        $fileName = $receipt['file_name'] ?: 'recibo_' . $receipt['receipt_number'] . '.pdf';
        
        // Check if it's a view request (from iframe) or download request
        $isView = isset($_GET['view']) && $_GET['view'] === '1';
        $disposition = $isView ? 'inline' : 'attachment';
        
        // Headers para permitir visualização em iframes e mobile
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600, must-revalidate');
        header('Pragma: public');
        
        // Headers para permitir iframe e melhorar compatibilidade mobile
        if ($isView) {
            // Permitir que seja visualizado em iframe da mesma origem
            header('X-Frame-Options: SAMEORIGIN');
            // Permitir acesso cross-origin se necessário
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET');
        }
        
        readfile($filePath);
        exit;
    }
}
