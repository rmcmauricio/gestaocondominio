<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Condominium;
use App\Models\CondominiumUser;
use App\Models\Fraction;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;

class CondominiumController extends Controller
{
    protected $condominiumModel;
    protected $subscriptionModel;
    protected $subscriptionService;

    public function __construct()
    {
        parent::__construct();
        $this->condominiumModel = new Condominium();
        $this->subscriptionModel = new Subscription();
        $this->subscriptionService = new SubscriptionService();
    }

    public function index()
    {
        // Redirect to dashboard - dashboard now shows condominiums list
        AuthMiddleware::require();
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    /**
     * Check if current user is demo user
     */
    protected function isDemoUser(): bool
    {
        $user = AuthMiddleware::user();
        return $user && isset($user['is_demo']) && $user['is_demo'] == true;
    }

    public function create()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        $userId = AuthMiddleware::userId();
        
        // Block demo user from creating additional condominiums
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode criar condomínios adicionais.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
        
        // Check subscription limits
        if (!$this->subscriptionModel->canCreateCondominium($userId)) {
            $_SESSION['error'] = 'Limite de condomínios atingido. Faça upgrade do seu plano.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/create.html.twig',
            'page' => ['titulo' => 'Criar Condomínio'],
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store()
    {
        AuthMiddleware::require();
        RoleMiddleware::requireAnyRole(['admin', 'super_admin']);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/create');
            exit;
        }

        $userId = AuthMiddleware::userId();
        
        // Block demo user from creating additional condominiums
        if ($this->isDemoUser()) {
            $_SESSION['error'] = 'A conta demo não pode criar condomínios adicionais.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
        
        if (!$this->subscriptionModel->canCreateCondominium($userId)) {
            $_SESSION['error'] = 'Limite de condomínios atingido.';
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        try {
            $condominiumId = $this->condominiumModel->create([
                'user_id' => $userId,
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'postal_code' => Security::sanitize($_POST['postal_code'] ?? ''),
                'city' => Security::sanitize($_POST['city'] ?? ''),
                'country' => Security::sanitize($_POST['country'] ?? 'Portugal'),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'iban' => Security::sanitize($_POST['iban'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'habitacional'),
                'total_fractions' => (int)($_POST['total_fractions'] ?? 0),
                'rules' => Security::sanitize($_POST['rules'] ?? '')
            ]);

            // Create entry in condominium_users with admin role (without fraction - admin entry)
            $condominiumUserModel = new CondominiumUser();
            $condominiumUserModel->associate([
                'condominium_id' => $condominiumId,
                'user_id' => $userId,
                'fraction_id' => null, // Admin entry without fraction
                'role' => 'admin',
                'can_view_finances' => true,
                'can_vote' => true,
                'is_primary' => true,
                'started_at' => date('Y-m-d')
            ]);

            // Create default fraction for admin and associate as proprietario
            $fractionModel = new Fraction();
            $fractionId = $fractionModel->create([
                'condominium_id' => $condominiumId,
                'identifier' => 'Admin',
                'permillage' => 100, // Default permillage
                'floor' => null,
                'typology' => null,
                'area' => null,
                'notes' => 'Fração padrão do administrador'
            ]);

            // Associate admin as proprietario of this fraction
            $condominiumUserModel->associate([
                'condominium_id' => $condominiumId,
                'user_id' => $userId,
                'fraction_id' => $fractionId,
                'role' => 'proprietario',
                'can_view_finances' => true,
                'can_vote' => true,
                'is_primary' => true,
                'started_at' => date('Y-m-d')
            ]);

            $_SESSION['success'] = 'Condomínio criado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar condomínio: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/create');
            exit;
        }
    }

    public function show(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        // Update session with current condominium ID
        $_SESSION['current_condominium_id'] = $id;
        
        // Check user's role and set view_mode if not already set
        $userId = AuthMiddleware::userId();
        $viewModeKey = "condominium_{$id}_view_mode";
        
        // Only set view_mode if not already set
        if (!isset($_SESSION[$viewModeKey])) {
            global $db;
            $hasAdminRole = false;
            $hasCondominoRole = false;
            
            // Check if user is owner
            $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
            $stmt->execute([
                ':condominium_id' => $id,
                ':user_id' => $userId
            ]);
            if ($stmt->fetch()) {
                $hasAdminRole = true;
            }
            
            // Check condominium_users table
            $stmt = $db->prepare("
                SELECT role, fraction_id
                FROM condominium_users 
                WHERE user_id = :user_id 
                AND condominium_id = :condominium_id
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $stmt->execute([
                ':user_id' => $userId,
                ':condominium_id' => $id
            ]);
            $results = $stmt->fetchAll();
            
            foreach ($results as $result) {
                if ($result['role'] === 'admin') {
                    $hasAdminRole = true;
                }
                if ($result['fraction_id'] !== null) {
                    $hasCondominoRole = true;
                }
            }
            
            // Set view_mode based on user's roles
            if ($hasCondominoRole && !$hasAdminRole) {
                // User is only condomino, set view mode to condomino
                $_SESSION[$viewModeKey] = 'condomino';
            } elseif ($hasAdminRole && !$hasCondominoRole) {
                // User is only admin, set view mode to admin
                $_SESSION[$viewModeKey] = 'admin';
            }
            // If user has both roles, don't set view_mode (will default to admin)
        }

        $condominium = $this->condominiumModel->getWithStats($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        global $db;
        
        // Get bank accounts information
        $bankAccountModel = new \App\Models\BankAccount();
        
        // Ensure cash account exists
        $cashAccount = $bankAccountModel->getCashAccount($id);
        if (!$cashAccount) {
            $bankAccountModel->createCashAccount($id);
        }
        
        $bankAccountsRaw = $bankAccountModel->getActiveAccounts($id);
        $totalBankBalance = 0;
        $currentAccountBalance = 0;
        $bankAccounts = [];
        $mainAccountIban = null; // IBAN da conta principal
        
        foreach ($bankAccountsRaw as $accountRaw) {
            $bankAccountModel->updateBalance($accountRaw['id']);
            $account = $bankAccountModel->findById($accountRaw['id']);
            if ($account) {
                $balance = (float)($account['current_balance'] ?? 0);
                $totalBankBalance += $balance;
                
                // Check if it's a current account (conta à ordem)
                if ($account['account_type'] === 'bank' && 
                    (stripos($account['name'], 'ordem') !== false || 
                     stripos($account['name'], 'corrente') !== false ||
                     stripos($account['name'], 'principal') !== false)) {
                    $currentAccountBalance += $balance;
                    // Store IBAN of the first/main current account found
                    if ($mainAccountIban === null && !empty($account['iban'])) {
                        $mainAccountIban = $account['iban'];
                    }
                }
                
                $bankAccounts[] = $account;
            }
        }
        
        // If no IBAN found in current account, try to get from first bank account
        if ($mainAccountIban === null) {
            foreach ($bankAccounts as $account) {
                if ($account['account_type'] === 'bank' && !empty($account['iban'])) {
                    $mainAccountIban = $account['iban'];
                    break;
                }
            }
        }
        
        // Get condominium users (condóminos)
        $stmt = $db->prepare("
            SELECT 
                cu.id,
                cu.fraction_id,
                cu.role,
                cu.is_primary,
                u.id as user_id,
                u.name,
                u.email,
                u.phone,
                f.identifier as fraction_identifier
            FROM condominium_users cu
            INNER JOIN users u ON u.id = cu.user_id
            LEFT JOIN fractions f ON f.id = cu.fraction_id
            WHERE cu.condominium_id = :condominium_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            ORDER BY cu.is_primary DESC, f.identifier ASC, u.name ASC
        ");
        $stmt->execute([':condominium_id' => $id]);
        $condominiumUsers = $stmt->fetchAll() ?: [];
        
        // Get additional statistics
        $stats = [
            'total_residents' => count($condominiumUsers),
            'total_bank_balance' => $totalBankBalance,
            'current_account_balance' => $currentAccountBalance,
            'total_bank_accounts' => count($bankAccounts)
        ];
        
        // Count overdue fees
        $stmt = $db->prepare("
            SELECT COUNT(*) as count,
                   COALESCE(SUM(f.amount - COALESCE((
                       SELECT SUM(fp.amount) 
                       FROM fee_payments fp 
                       WHERE fp.fee_id = f.id
                   ), 0)), 0) as total_amount
            FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'pending'
            AND f.due_date < CURDATE()
            AND COALESCE(f.is_historical, 0) = 0
        ");
        $stmt->execute([':condominium_id' => $id]);
        $overdueResult = $stmt->fetch();
        $stats['overdue_fees'] = $overdueResult['count'] ?? 0;
        $stats['overdue_fees_amount'] = (float)($overdueResult['total_amount'] ?? 0);
        
        // Count open occurrences
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM occurrences 
            WHERE condominium_id = :condominium_id
            AND status IN ('open', 'in_analysis', 'assigned')
        ");
        $stmt->execute([':condominium_id' => $id]);
        $occurrenceResult = $stmt->fetch();
        $stats['open_occurrences'] = $occurrenceResult['count'] ?? 0;
        
        // Count pending fees
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(f.amount - COALESCE((
                SELECT SUM(fp.amount) 
                FROM fee_payments fp 
                WHERE fp.fee_id = f.id
            ), 0)), 0) as total
            FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'pending'
            AND COALESCE(f.is_historical, 0) = 0
        ");
        $stmt->execute([':condominium_id' => $id]);
        $pendingResult = $stmt->fetch();
        $stats['pending_fees_amount'] = (float)($pendingResult['total'] ?? 0);
        
        // Count paid fees
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(fp.amount), 0) as total
            FROM fee_payments fp
            INNER JOIN fees f ON f.id = fp.fee_id
            WHERE f.condominium_id = :condominium_id
        ");
        $stmt->execute([':condominium_id' => $id]);
        $paidResult = $stmt->fetch();
        $stats['paid_fees_amount'] = (float)($paidResult['total'] ?? 0);

        // Get fees map data
        $feeModel = new \App\Models\Fee();
        $fractionModel = new \App\Models\Fraction();
        
        // Get available years
        $availableYears = $feeModel->getAvailableYears($id);
        if (empty($availableYears)) {
            $availableYears = [date('Y')];
        }
        
        // Get selected year (default to current year or most recent year with fees)
        $selectedYear = $_GET['fees_year'] ?? $availableYears[0];
        $selectedYear = (int)$selectedYear;
        
        // Get fractions for the condominium
        $fractions = $fractionModel->getByCondominiumId($id);
        
        // Get fees map for selected year
        $feesMap = $feeModel->getFeesMapByYear($id, $selectedYear);
        
        // Month names in Portuguese
        $monthNames = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
            5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
            9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
        ];

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/show.html.twig',
            'page' => ['titulo' => $condominium['name']],
            'condominium' => $condominium,
            'bank_accounts' => $bankAccounts,
            'condominium_users' => $condominiumUsers,
            'stats' => $stats,
            'main_account_iban' => $mainAccountIban,
            'fees_map' => $feesMap,
            'fractions' => $fractions,
            'available_years' => $availableYears,
            'selected_fees_year' => $selectedYear,
            'fees_map_form_action' => BASE_URL . 'condominiums/' . $id,
            'month_names' => $monthNames
        ];

        // Update session with current condominium ID
        $_SESSION['current_condominium_id'] = $id;
        
        // Re-merge global data to ensure sidebar uses correct condominium
        $this->data = $this->mergeGlobalData($this->data);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function edit(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->findById($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        // Get current template and logo
        $currentTemplate = $this->condominiumModel->getDocumentTemplate($id);
        $logoPath = $this->condominiumModel->getLogoPath($id);
        $logoUrl = null;
        if ($logoPath) {
            $fileStorageService = new \App\Services\FileStorageService();
            $logoUrl = $fileStorageService->getFileUrl($logoPath);
        }

        // Check for preview template parameter
        $previewTemplate = null;
        $hasPreviewParam = isset($_GET['preview_template']);
        
        if ($hasPreviewParam) {
            $previewValue = $_GET['preview_template'];
            if ($previewValue === '' || $previewValue === '0' || $previewValue === null) {
                // Empty string, '0', or null means default template (preview of default)
                $previewTemplate = null;
            } else {
                $previewTemplateId = (int)$previewValue;
                // Validate template ID is between 1-17
                if ($previewTemplateId >= 1 && $previewTemplateId <= 17) {
                    $previewTemplate = $previewTemplateId;
                }
            }
        }
        
        // Template options with descriptions
        $templateOptions = [
            null => ['name' => 'Padrão', 'description' => 'Template padrão do sistema'],
            1 => ['name' => 'Clássico', 'description' => 'Estilo tradicional, cores neutras'],
            2 => ['name' => 'Moderno', 'description' => 'Design limpo, cores azuis modernas'],
            3 => ['name' => 'Elegante (Dark Mode)', 'description' => 'Estilo sofisticado, tema escuro elegante'],
            4 => ['name' => 'Minimalista', 'description' => 'Design simples, muito espaço em branco'],
            5 => ['name' => 'Corporativo', 'description' => 'Estilo empresarial, cores formais'],
            6 => ['name' => 'Colorido', 'description' => 'Design vibrante, cores chamativas'],
            7 => ['name' => 'Profissional (Dark Mode)', 'description' => 'Estilo conservador, tema escuro profissional'],
            8 => ['name' => 'Acogedor (Laranja Pastel)', 'description' => 'Estilo intermediário, fundo laranja pastel acolhedor'],
            9 => ['name' => 'Natureza Verde', 'description' => 'Estilo natural com cores verdes e acentos amarelos'],
            10 => ['name' => 'Azul Profissional', 'description' => 'Estilo profissional com paleta azul moderna'],
            11 => ['name' => 'Moderno Cinza Azulado', 'description' => 'Estilo moderno com tons de cinza e azul suave'],
            12 => ['name' => 'Vibrante Dourado Laranja', 'description' => 'Estilo vibrante com tons de dourado, laranja e verde-limão'],
            13 => ['name' => 'Suave Verde Azulado', 'description' => 'Estilo suave com tons de verde e azul claros'],
            14 => ['name' => 'Verde Azulado Alternativo', 'description' => 'Estilo alternativo com foco em azul claro e verde suave'],
            15 => ['name' => 'Quente Terroso', 'description' => 'Estilo quente com tons terrosos de dourado, bronze e cobre'],
            16 => ['name' => 'Escuro Elegante', 'description' => 'Estilo elegante com tons escuros e acentos vermelhos'],
            17 => ['name' => 'Contraste Clássico', 'description' => 'Estilo clássico com alto contraste entre preto, branco e tons terrosos']
        ];
        
        // Use preview template for rendering if set, otherwise use current template
        // If preview_template is explicitly set (even if null), use it; otherwise use current
        if ($hasPreviewParam) {
            // Preview is active - use previewTemplate (can be null for default template preview)
            $templateIdForRendering = $previewTemplate;
        } else {
            // No preview - use current template from database
            $templateIdForRendering = $currentTemplate;
        }
        
        // Build data array - IMPORTANT: template_id must be set for preview to work
        $this->data += [
            'viewName' => 'pages/condominiums/edit.html.twig',
            'page' => ['titulo' => 'Editar Condomínio'],
            'condominium' => $condominium,
            'current_template' => $currentTemplate,
            'preview_template' => $hasPreviewParam ? $previewTemplate : false, // false means no preview, null means preview of default
            'logo_url' => $logoUrl,
            'template_options' => $templateOptions,
            'csrf_token' => Security::generateCSRFToken(),
        ];
        
        // CRITICAL: Set template_id AFTER building data array to ensure it's not overwritten
        // This MUST be set for preview to work
        if ($hasPreviewParam) {
            // Preview is active - set template_id to preview value (can be null)
            $this->data['template_id'] = $previewTemplate;
        } else {
            // No preview - set template_id to current template from database
            $this->data['template_id'] = $currentTemplate;
        }

        // Merge global data to ensure template_id is properly processed
        $mergedData = $this->mergeGlobalData($this->data);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $mergedData);
    }

    public function update(int $id)
    {
        AuthMiddleware::require();
        
        // Only admin can update condominium information
        $user = AuthMiddleware::user();
        
        // Check if user is admin or super_admin
        if (!RoleMiddleware::hasAnyRole(['admin', 'super_admin'])) {
            $_SESSION['error'] = 'Apenas administradores podem editar informações do condomínio.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }
        
        // Check if user owns this condominium (or is super_admin)
        if ($user['role'] !== 'super_admin') {
            RoleMiddleware::requireCondominiumAccess($id);
            
            // Verify that user is the owner/admin of this condominium
            $condominium = $this->condominiumModel->findById($id);
            if (!$condominium || $condominium['user_id'] != $user['id']) {
                $_SESSION['error'] = 'Apenas o administrador do condomínio pode editar as informações.';
                header('Location: ' . BASE_URL . 'condominiums/' . $id);
                exit;
            }
        } else {
            // Super admin can update any condominium
            RoleMiddleware::requireCondominiumAccess($id);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }

        try {
            $updateData = [
                'name' => Security::sanitize($_POST['name'] ?? ''),
                'address' => Security::sanitize($_POST['address'] ?? ''),
                'postal_code' => Security::sanitize($_POST['postal_code'] ?? ''),
                'city' => Security::sanitize($_POST['city'] ?? ''),
                'country' => Security::sanitize($_POST['country'] ?? 'Portugal'),
                'nif' => Security::sanitize($_POST['nif'] ?? ''),
                'iban' => Security::sanitize($_POST['iban'] ?? ''),
                'phone' => Security::sanitize($_POST['phone'] ?? ''),
                'email' => Security::sanitize($_POST['email'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'habitacional'),
                'total_fractions' => (int)($_POST['total_fractions'] ?? 0),
                'rules' => Security::sanitize($_POST['rules'] ?? '')
            ];

            // Process template selection - ALWAYS include this field in update
            $templateValue = $_POST['document_template'] ?? '';
            if ($templateValue === '' || $templateValue === null || $templateValue === '0') {
                // Empty, null, or '0' means default template (no custom CSS)
                $updateData['document_template'] = null;
            } else {
                $templateId = (int)$templateValue;
                if ($templateId >= 1 && $templateId <= 17) {
                    $updateData['document_template'] = $templateId;
                } else {
                    // Invalid template ID, set to null (default)
                    $updateData['document_template'] = null;
                }
            }

            // Process logo upload
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $fileStorageService = new \App\Services\FileStorageService();
                try {
                    // Delete old logo if exists
                    $oldLogoPath = $this->condominiumModel->getLogoPath($id);
                    if ($oldLogoPath) {
                        try {
                            $fileStorageService->delete($oldLogoPath);
                        } catch (\Exception $e) {
                            // Log error but continue with new upload
                            error_log("Error deleting old logo: " . $e->getMessage());
                        }
                    }
                    
                    $logoData = $fileStorageService->uploadLogo($_FILES['logo'], $id);
                    $updateData['logo_path'] = $logoData['file_path'];
                } catch (\Exception $e) {
                    $_SESSION['error'] = 'Erro ao fazer upload do logotipo: ' . $e->getMessage();
                    header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
                    exit;
                }
            }

            $this->condominiumModel->update($id, $updateData);

            $_SESSION['success'] = 'Condomínio atualizado com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar condomínio: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }
    }

    public function delete(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        // Prevent deleting demo condominium
        \App\Middleware\DemoProtectionMiddleware::preventDemoCondominiumDelete($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        if ($this->condominiumModel->delete($id)) {
            $_SESSION['success'] = 'Condomínio removido com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao remover condomínio.';
        }

        header('Location: ' . BASE_URL . 'condominiums');
        exit;
    }

    /**
     * Show customization page
     */
    public function customize(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->findById($id);
        
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('condominiums');
        
        // Get current template and logo
        $currentTemplate = $this->condominiumModel->getDocumentTemplate($id);
        $logoPath = $this->condominiumModel->getLogoPath($id);
        $logoUrl = null;
        if ($logoPath) {
            $fileStorageService = new \App\Services\FileStorageService();
            $logoUrl = $fileStorageService->getFileUrl($logoPath);
        }

        // Template options with descriptions
        $templateOptions = [
            null => ['name' => 'Padrão', 'description' => 'Template padrão do sistema'],
            1 => ['name' => 'Clássico', 'description' => 'Estilo tradicional, cores neutras'],
            2 => ['name' => 'Moderno', 'description' => 'Design limpo, cores azuis modernas'],
            3 => ['name' => 'Elegante (Dark Mode)', 'description' => 'Estilo sofisticado, tema escuro elegante'],
            4 => ['name' => 'Minimalista', 'description' => 'Design simples, muito espaço em branco'],
            5 => ['name' => 'Corporativo', 'description' => 'Estilo empresarial, cores formais'],
            6 => ['name' => 'Colorido', 'description' => 'Design vibrante, cores chamativas'],
            7 => ['name' => 'Profissional (Dark Mode)', 'description' => 'Estilo conservador, tema escuro profissional'],
            8 => ['name' => 'Acogedor (Laranja Pastel)', 'description' => 'Estilo intermediário, fundo laranja pastel acolhedor'],
            9 => ['name' => 'Natureza Verde', 'description' => 'Estilo natural com cores verdes e acentos amarelos'],
            10 => ['name' => 'Azul Profissional', 'description' => 'Estilo profissional com paleta azul moderna'],
            11 => ['name' => 'Moderno Cinza Azulado', 'description' => 'Estilo moderno com tons de cinza e azul suave'],
            12 => ['name' => 'Vibrante Dourado Laranja', 'description' => 'Estilo vibrante com tons de dourado, laranja e verde-limão'],
            13 => ['name' => 'Suave Verde Azulado', 'description' => 'Estilo suave com tons de verde e azul claros'],
            14 => ['name' => 'Verde Azulado Alternativo', 'description' => 'Estilo alternativo com foco em azul claro e verde suave'],
            15 => ['name' => 'Quente Terroso', 'description' => 'Estilo quente com tons terrosos de dourado, bronze e cobre'],
            16 => ['name' => 'Escuro Elegante', 'description' => 'Estilo elegante com tons escuros e acentos vermelhos'],
            17 => ['name' => 'Contraste Clássico', 'description' => 'Estilo clássico com alto contraste entre preto, branco e tons terrosos']
        ];
        
        // Build data array
        $this->data += [
            'viewName' => 'pages/condominiums/customize.html.twig',
            'page' => ['titulo' => 'Personalizar Condomínio'],
            'condominium' => $condominium,
            'current_template' => $currentTemplate,
            'logo_url' => $logoUrl,
            'template_options' => $templateOptions,
            'csrf_token' => Security::generateCSRFToken(),
        ];
        
        // Set template_id for rendering
        $this->data['template_id'] = $currentTemplate;

        // Merge global data
        $mergedData = $this->mergeGlobalData($this->data);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $mergedData);
    }

    /**
     * Update template via AJAX
     */
    public function updateTemplate(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        header('Content-Type: application/json');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método não permitido']);
            exit;
        }

        $user = AuthMiddleware::user();
        
        // Check permissions
        if (!RoleMiddleware::hasAnyRole(['admin', 'super_admin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Apenas administradores podem alterar o template.']);
            exit;
        }
        
        if ($user['role'] !== 'super_admin') {
            RoleMiddleware::requireCondominiumAccess($id);
            $condominium = $this->condominiumModel->findById($id);
            if (!$condominium || $condominium['user_id'] != $user['id']) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Apenas o administrador do condomínio pode alterar o template.']);
                exit;
            }
        }

        $data = json_decode(file_get_contents('php://input'), true);
        $csrfToken = $data['csrf_token'] ?? '';
        
        if (!Security::verifyCSRFToken($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token de segurança inválido.']);
            exit;
        }

        $templateValue = $data['template_id'] ?? '';
        $updateData = [];
        
        if ($templateValue === '' || $templateValue === null || $templateValue === '0') {
            $updateData['document_template'] = null;
        } else {
            $templateId = (int)$templateValue;
            if ($templateId >= 1 && $templateId <= 17) {
                $updateData['document_template'] = $templateId;
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID de template inválido.']);
                exit;
            }
        }

        try {
            $this->condominiumModel->update($id, $updateData);
            $this->jsonSuccess([], 'Template atualizado com sucesso!');
        } catch (\Exception $e) {
            $this->jsonError($e, 500, 'TEMPLATE_UPDATE_ERROR');
        }
        exit;
    }

    /**
     * Upload logo from customize page
     */
    public function uploadLogo(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $user = AuthMiddleware::user();
        
        // Check permissions
        if (!RoleMiddleware::hasAnyRole(['admin', 'super_admin'])) {
            $_SESSION['error'] = 'Apenas administradores podem fazer upload do logotipo.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
            exit;
        }
        
        if ($user['role'] !== 'super_admin') {
            RoleMiddleware::requireCondominiumAccess($id);
            $condominium = $this->condominiumModel->findById($id);
            if (!$condominium || $condominium['user_id'] != $user['id']) {
                $_SESSION['error'] = 'Apenas o administrador do condomínio pode fazer upload do logotipo.';
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
                exit;
            }
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
            exit;
        }

        try {
            if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $fileStorageService = new \App\Services\FileStorageService();
                
                // Delete old logo if exists
                $oldLogoPath = $this->condominiumModel->getLogoPath($id);
                if ($oldLogoPath) {
                    try {
                        $fileStorageService->delete($oldLogoPath);
                    } catch (\Exception $e) {
                        error_log("Error deleting old logo: " . $e->getMessage());
                    }
                }
                
                $logoData = $fileStorageService->uploadLogo($_FILES['logo'], $id);
                $this->condominiumModel->update($id, ['logo_path' => $logoData['file_path']]);

                $_SESSION['success'] = 'Logotipo atualizado com sucesso!';
            } else {
                $_SESSION['error'] = 'Erro ao fazer upload do logotipo.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao fazer upload do logotipo: ' . $e->getMessage();
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
        exit;
    }

    /**
     * Remove logo from condominium
     */
    public function removeLogo(int $id)
    {
        AuthMiddleware::require();
        
        // Only admin can remove logo
        $user = AuthMiddleware::user();
        
        // Check if user is admin or super_admin
        if (!RoleMiddleware::hasAnyRole(['admin', 'super_admin'])) {
            $_SESSION['error'] = 'Apenas administradores podem remover o logotipo.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }
        
        // Check if user owns this condominium (or is super_admin)
        if ($user['role'] !== 'super_admin') {
            RoleMiddleware::requireCondominiumAccess($id);
            
            // Verify that user is the owner/admin of this condominium
            $condominium = $this->condominiumModel->findById($id);
            if (!$condominium || $condominium['user_id'] != $user['id']) {
                $_SESSION['error'] = 'Apenas o administrador do condomínio pode remover o logotipo.';
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
                exit;
            }
        } else {
            // Super admin can remove logo from any condominium
            RoleMiddleware::requireCondominiumAccess($id);
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            exit;
        }

        try {
            // Get current logo path
            $logoPath = $this->condominiumModel->getLogoPath($id);
            
            // Delete logo file if exists
            if ($logoPath) {
                $fileStorageService = new \App\Services\FileStorageService();
                try {
                    $fileStorageService->delete($logoPath);
                } catch (\Exception $e) {
                    // Log error but continue with database update
                    error_log("Error deleting logo file: " . $e->getMessage());
                }
            }
            
            // Update database to remove logo_path
            $this->condominiumModel->update($id, ['logo_path' => null]);

            $_SESSION['success'] = 'Logotipo removido com sucesso!';
            // Check if request came from customize page
            $fromCustomize = isset($_POST['from_customize']) || (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/customize') !== false);
            if ($fromCustomize) {
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
            } else {
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            }
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao remover logotipo: ' . $e->getMessage();
            $fromCustomize = isset($_POST['from_customize']) || (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], '/customize') !== false);
            if ($fromCustomize) {
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/customize');
            } else {
                header('Location: ' . BASE_URL . 'condominiums/' . $id . '/edit');
            }
            exit;
        }
    }

    /**
     * Switch to a different condominium
     */
    public function switch(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        $condominium = $this->condominiumModel->findById($id);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        // Set current condominium in session
        $_SESSION['current_condominium_id'] = $id;

        // Check user's role in this condominium
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $id);
        
        // Check if user has both admin and condomino roles
        global $db;
        $hasAdminRole = false;
        $hasCondominoRole = false;
        
        // Check if user is owner
        $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
        $stmt->execute([
            ':condominium_id' => $id,
            ':user_id' => $userId
        ]);
        if ($stmt->fetch()) {
            $hasAdminRole = true;
        }
        
        // Check condominium_users table
        $stmt = $db->prepare("
            SELECT role, fraction_id
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $id
        ]);
        $results = $stmt->fetchAll();
        
        foreach ($results as $result) {
            if ($result['role'] === 'admin') {
                $hasAdminRole = true;
            }
            if ($result['fraction_id'] !== null) {
                $hasCondominoRole = true;
            }
        }
        
        // If user is ONLY condomino (not admin), automatically set view mode to condomino
        $viewModeKey = "condominium_{$id}_view_mode";
        if ($hasCondominoRole && !$hasAdminRole) {
            // User is only condomino, set view mode to condomino
            $_SESSION[$viewModeKey] = 'condomino';
        } elseif ($hasAdminRole && !$hasCondominoRole) {
            // User is only admin, set view mode to admin
            $_SESSION[$viewModeKey] = 'admin';
        }
        // If user has both roles, keep current view mode (or default to admin)

        // Redirect to condominium overview
        header('Location: ' . BASE_URL . 'condominiums/' . $id);
        exit;
    }

    /**
     * Set condominium as default for user
     */
    public function setDefault(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $userModel = new User();
        
        if ($userModel->setDefaultCondominium($userId, $id)) {
            $_SESSION['current_condominium_id'] = $id;
            $_SESSION['success'] = 'Condomínio definido como padrão!';
        } else {
            $_SESSION['error'] = 'Erro ao definir condomínio padrão.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $id);
        exit;
    }

    public function assignAdmin(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);
        
        // Check if user is admin in this condominium
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $id);
        
        if ($userRole !== 'admin') {
            $_SESSION['error'] = 'Apenas administradores podem designar outros administradores.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $condominium = $this->condominiumModel->findById($id);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }

        // Get all users associated with this condominium
        global $db;
        $stmt = $db->prepare("
            SELECT cu.*, u.name, u.email, f.identifier as fraction_identifier
            FROM condominium_users cu
            INNER JOIN users u ON u.id = cu.user_id
            LEFT JOIN fractions f ON f.id = cu.fraction_id
            WHERE cu.condominium_id = :condominium_id
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            ORDER BY u.name
        ");
        $stmt->execute([':condominium_id' => $id]);
        $condominiumUsers = $stmt->fetchAll() ?: [];

        // Get all users who are currently admins
        global $db;
        $stmt = $db->prepare("
            SELECT cu.user_id, u.name, u.email, cu.role
            FROM condominium_users cu
            INNER JOIN users u ON u.id = cu.user_id
            WHERE cu.condominium_id = :condominium_id
            AND cu.role = 'admin'
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
        ");
        $stmt->execute([':condominium_id' => $id]);
        $currentAdmins = $stmt->fetchAll() ?: [];

        // Also include the owner if not already in the list
        $ownerId = $condominium['user_id'];
        $ownerInList = false;
        foreach ($currentAdmins as $admin) {
            if ($admin['user_id'] == $ownerId) {
                $ownerInList = true;
                break;
            }
        }
        if (!$ownerInList) {
            $userModel = new User();
            $owner = $userModel->findById($ownerId);
            if ($owner) {
                $currentAdmins[] = [
                    'user_id' => $ownerId,
                    'name' => $owner['name'],
                    'email' => $owner['email'],
                    'role' => 'admin'
                ];
            }
        }

        $this->loadPageTranslations('condominiums');
        
        $this->data += [
            'viewName' => 'pages/condominiums/assign-admin.html.twig',
            'page' => ['titulo' => 'Designar Administradores'],
            'condominium' => $condominium,
            'condominium_users' => $condominiumUsers,
            'current_admins' => $currentAdmins,
            'csrf_token' => Security::generateCSRFToken()
        ];

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function processAssignAdmin(int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($id);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/assign-admin');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/assign-admin');
            exit;
        }

        // Check if user is admin in this condominium
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $id);
        
        if ($userRole !== 'admin') {
            $_SESSION['error'] = 'Apenas administradores podem designar outros administradores.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id);
            exit;
        }

        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if (!$targetUserId) {
            $_SESSION['error'] = 'Utilizador não especificado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $id . '/assign-admin');
            exit;
        }

        $condominiumUserModel = new CondominiumUser();
        if ($condominiumUserModel->assignAdmin($id, $targetUserId, $userId)) {
            $_SESSION['success'] = 'Administrador designado com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao designar administrador.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $id . '/assign-admin');
        exit;
    }

    /**
     * Switch view mode between admin and condomino for a condominium
     */
    public function switchViewMode(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $userId = AuthMiddleware::userId();
        $viewModeKey = "condominium_{$condominiumId}_view_mode";
        
        // Get current view mode
        $currentMode = $_SESSION[$viewModeKey] ?? null;
        
        // Check if user has both admin and condomino roles
        global $db;
        $hasAdminRole = false;
        $hasCondominoRole = false;
        
        // Check if user is owner
        $stmt = $db->prepare("SELECT id FROM condominiums WHERE id = :condominium_id AND user_id = :user_id");
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        if ($stmt->fetch()) {
            $hasAdminRole = true;
        }
        
        // Check condominium_users table
        $stmt = $db->prepare("
            SELECT role, fraction_id
            FROM condominium_users 
            WHERE user_id = :user_id 
            AND condominium_id = :condominium_id
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        $results = $stmt->fetchAll();
        
        foreach ($results as $result) {
            if ($result['role'] === 'admin') {
                $hasAdminRole = true;
            }
            if ($result['fraction_id'] !== null) {
                $hasCondominoRole = true;
            }
        }
        
        // Only allow switching if user has both roles
        if (!$hasAdminRole || !$hasCondominoRole) {
            $_SESSION['error'] = 'Só pode alternar entre admin e condómino se tiver ambos os papéis neste condomínio.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId);
            exit;
        }
        
        // Toggle view mode: if currently admin (or null/default), switch to condomino; if condomino, switch to admin
        // Use ONLY the session value, not the effective role (which would create a circular dependency)
        $newMode = null;
        if ($currentMode === 'condomino') {
            // Currently viewing as condomino -> switch to admin
            $_SESSION[$viewModeKey] = 'admin';
            $_SESSION['success'] = 'Modo alterado para Administrador.';
            $newMode = 'admin';
        } else {
            // Currently viewing as admin (or null/default) -> switch to condomino
            $_SESSION[$viewModeKey] = 'condomino';
            $_SESSION['success'] = 'Modo alterado para Condómino.';
            $newMode = 'condomino';
        }
        
        // Log audit: view mode change is an important security action
        $auditService = new \App\Services\AuditService();
        $auditService->log([
            'action' => 'view_mode_changed',
            'model' => 'condominium',
            'model_id' => $condominiumId,
            'description' => "Modo de visualização alterado de '{$currentMode}' para '{$newMode}' no condomínio ID {$condominiumId}"
        ]);
        
        // Redirect back to the same page the user was on (or condominium overview if no referer)
        $redirectUrl = $_SERVER['HTTP_REFERER'] ?? BASE_URL . 'condominiums/' . $condominiumId;
        
        // Ensure redirect is within the same condominium context
        // If referer is from a different condominium, redirect to overview
        if (strpos($redirectUrl, BASE_URL . 'condominiums/' . $condominiumId) === false) {
            $redirectUrl = BASE_URL . 'condominiums/' . $condominiumId;
        }
        
        header('Location: ' . $redirectUrl);
        exit;
    }
}





