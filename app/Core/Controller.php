<?php

namespace App\Core;

use App\Core\Page;
use App\Core\Utils;
use App\Core\TemplateEngine;

/**
 * @property \App\Core\Page $page
 * @property \App\Core\Utils $utils
 */
class Controller
{
    protected Page $page;
    protected Utils $utils;
    protected array $data;

    public function __construct()
    {
        $this->utils = new Utils;
        $this->page = new Page;
        $this->page->setPage('default');
        $this->data = $this->mergeGlobalData([]);

        // Do not keep 'page' from default merge so that each controller can set its own title via $this->data['page']
        unset($this->data['page']);

        // Load global translations for all pages
        $this->data['global_t'] = $this->loadGlobalTranslations();
    }

    public function renderCustomTemplate(string $templatePath, $context, array $data = []): void
    {
        $engine = new TemplateEngine();
        
        // Ensure global translations are loaded
        if (!isset($this->data['global_t'])) {
            $this->data['global_t'] = $this->loadGlobalTranslations();
        }
        
        $data = $this->mergeGlobalData($data);
        
        // Ensure page translations are included (from loadPageTranslations or controller)
        if (isset($this->data['_page_t'])) {
            $data['page']['t'] = $this->data['_page_t'];
        } elseif (isset($this->data['page']['t'])) {
            $data['page']['t'] = $this->data['page']['t'];
        }
        
        echo $engine->renderFile($templatePath, $context, $data);
    }

    public function loadPage(string $viewName, array $data = []): void
    {
        $pathHtml = __DIR__ . '/../Views/pages/' . $viewName . '.html';
        $pathPhp  = __DIR__ . '/../Views/pages/' . $viewName . '.php';

        if (file_exists($pathHtml)) {
            $engine = new TemplateEngine();
            echo $engine->renderFile($pathHtml, $this, $data);
        } elseif (file_exists($pathPhp)) {
            extract($data);
            require $pathPhp;
        } else {
            throw new \Exception("Página {$viewName} não encontrada.");
        }
    }

    public function loadPageInTemplate(string $viewName, array $data = []): string
    {
        $pathHtml = __DIR__ . '/../Views/pages/' . $viewName . '.html';
        $pathPhp  = __DIR__ . '/../Views/pages/' . $viewName . '.php';

        if (file_exists($pathHtml)) {
            $engine = new TemplateEngine();
            return $engine->renderFile($pathHtml, $this, $data);
        } elseif (file_exists($pathPhp)) {
            extract($data);
            ob_start();
            require $pathPhp;
            return ob_get_clean();
        } else {
            return "<!-- Página '{$viewName}' não encontrada -->";
        }
    }

    public function loadTemplate(string $template, string $viewName, array $data = []): string
    {
        $pathHtml = __DIR__ . '/../Views/templates/' . $template . '.html';
        $pathPhp  = __DIR__ . '/../Views/templates/' . $template . '.php';

        if (file_exists($pathHtml)) {
            $engine = new TemplateEngine();
            return $engine->renderFile($pathHtml, $this, $data);
        } elseif (file_exists($pathPhp)) {
            extract($data);
            ob_start();
            require $pathPhp;
            return ob_get_clean();
        } else {
            return "Template {$template} não encontrado.";
        }
    }

    public function loadBlockInTemplate(string $viewName, array $data = []): string
    {
        $pathHtml = __DIR__ . '/../Views/blocks/' . $viewName . '.html';
        $pathPhp  = __DIR__ . '/../Views/blocks/' . $viewName . '.php';

        if (file_exists($pathHtml)) {
            $engine = new TemplateEngine();
            return $engine->renderFile($pathHtml, $this, $data);
        } elseif (file_exists($pathPhp)) {
            extract($data);
            ob_start();
            require $pathPhp;
            return ob_get_clean();
        } else {
            return "<!-- Bloco '{$viewName}' não encontrado -->";
        }
    }

    protected function mergeGlobalData(array $data): array
    {
        $currentLang = $_SESSION['lang'] ?? 'pt';
        
        // Check for demo banner message
        $demoBannerMessage = null;
        $unreadNotificationsCount = 0;
        $unreadMessagesCount = 0;
        $isDemoUser = false;
        $demoProfile = null;
        $condominium = null;
        $userCondominiums = [];
        $currentCondominiumRole = null;
        
        if (!empty($_SESSION['user'])) {
            $demoBannerMessage = \App\Middleware\DemoProtectionMiddleware::getDemoBannerMessage();
            
            // Check if we're in demo mode - either current user is demo OR demo_profile is set
            $currentUserIsDemo = \App\Middleware\DemoProtectionMiddleware::isDemoUser($_SESSION['user']['id'] ?? null);
            $demoProfile = $_SESSION['demo_profile'] ?? ($currentUserIsDemo ? 'admin' : null);
            
            // If demo_profile is set, we're in demo mode regardless of current user
            $isDemoUser = $currentUserIsDemo || isset($_SESSION['demo_profile']);
            
            // Determine current condominium first (needed for filtering counts)
            $userId = $_SESSION['user']['id'] ?? null;
            $userRole = $_SESSION['user']['role'] ?? 'condomino';
            
            if ($userId) {
                // Priority: URL parameter (from $data['condominium']) > Session > Default
                // If condominium is passed in data (from URL), it takes priority
                $currentCondominiumId = null;
                
                if (isset($data['condominium']) && isset($data['condominium']['id'])) {
                    // URL parameter has highest priority - user explicitly selected a condominium
                    $currentCondominiumId = $data['condominium']['id'];
                    // Always update session when condominium comes from URL
                    $_SESSION['current_condominium_id'] = $currentCondominiumId;
                } else {
                    // If not in URL, check session
                    $currentCondominiumId = $_SESSION['current_condominium_id'] ?? null;
                    
                    // If still not set, get user's default condominium
                    if (!$currentCondominiumId) {
                        $userModel = new \App\Models\User();
                        $currentCondominiumId = $userModel->getDefaultCondominiumId($userId);
                        // Update session with default condominium
                        if ($currentCondominiumId) {
                            $_SESSION['current_condominium_id'] = $currentCondominiumId;
                        }
                    }
                }
                
                // Get all user condominiums for dropdown
                if ($userRole === 'super_admin') {
                    // For superadmin, get all condominiums where user is admin or condomino
                    $condominiumUserModel = new \App\Models\CondominiumUser();
                    $condominiumsByRole = $condominiumUserModel->getUserCondominiumsWithRoles($userId);
                    // Combine admin and condomino condominiums
                    $mergedCondominiums = array_merge(
                        $condominiumsByRole['admin'] ?? [],
                        $condominiumsByRole['condomino'] ?? []
                    );
                    // Remove duplicates by ID (same condominium might appear in both lists)
                    $userCondominiums = [];
                    $seenIds = [];
                    foreach ($mergedCondominiums as $condo) {
                        $condoId = $condo['id'] ?? null;
                        if ($condoId && !in_array($condoId, $seenIds)) {
                            $seenIds[] = $condoId;
                            $userCondominiums[] = $condo;
                        }
                    }
                } elseif ($userRole === 'admin') {
                    // For admin, get all condominiums where user is admin (owner or assigned)
                    $condominiumUserModel = new \App\Models\CondominiumUser();
                    $condominiumsByRole = $condominiumUserModel->getUserCondominiumsWithRoles($userId);
                    // Combine admin and condomino condominiums for dropdown
                    $mergedCondominiums = array_merge(
                        $condominiumsByRole['admin'] ?? [],
                        $condominiumsByRole['condomino'] ?? []
                    );
                    // Remove duplicates by ID (same condominium might appear in both lists)
                    $userCondominiums = [];
                    $seenIds = [];
                    foreach ($mergedCondominiums as $condo) {
                        $condoId = $condo['id'] ?? null;
                        if ($condoId && !in_array($condoId, $seenIds)) {
                            $seenIds[] = $condoId;
                            $userCondominiums[] = $condo;
                        }
                    }
                } else {
                    $condominiumUserModel = new \App\Models\CondominiumUser();
                    $userCondominiumsList = $condominiumUserModel->getUserCondominiums($userId);
                    $condominiumModel = new \App\Models\Condominium();
                    $userCondominiums = [];
                    foreach ($userCondominiumsList as $uc) {
                        $condo = $condominiumModel->findById($uc['condominium_id']);
                        if ($condo && !in_array($condo['id'], array_column($userCondominiums, 'id'))) {
                            $userCondominiums[] = $condo;
                        }
                    }
                }
                
                // If still not set, get first available condominium
                if (!$currentCondominiumId && !empty($userCondominiums)) {
                    $currentCondominiumId = $userCondominiums[0]['id'];
                    $_SESSION['current_condominium_id'] = $currentCondominiumId;
                    
                    // If user has only one condominium, set it as default automatically
                    if (count($userCondominiums) === 1) {
                        $userModel = new \App\Models\User();
                        $userModel->setDefaultCondominium($userId, $currentCondominiumId);
                    }
                }
                
                // Get current condominium details for sidebar
                if ($currentCondominiumId) {
                    $condominiumModel = new \App\Models\Condominium();
                    // Use the determined condominium ID (from URL, session, or default)
                    $condominium = $condominiumModel->findById($currentCondominiumId);
                    // Ensure session is always set with the current condominium
                    $_SESSION['current_condominium_id'] = $currentCondominiumId;
                    
                    // Get user's role in this condominium
                    $currentCondominiumRole = \App\Middleware\RoleMiddleware::getUserRoleInCondominium($userId, $currentCondominiumId);
                } elseif (isset($data['condominium'])) {
                    // Fallback: if no ID determined but we have condominium in data, use it
                    $condominium = $data['condominium'];
                    $_SESSION['current_condominium_id'] = $condominium['id'];
                    
                    // Get user's role in this condominium
                    $currentCondominiumRole = \App\Middleware\RoleMiddleware::getUserRoleInCondominium($userId, $condominium['id']);
                }
                
                // IMPORTANT: If data has a condominium with different ID, it means user explicitly selected it
                // Override the determined condominium with the one from data (URL parameter)
                if (isset($data['condominium']) && isset($data['condominium']['id'])) {
                    $dataCondominiumId = $data['condominium']['id'];
                    // If different from what we determined, use the one from data (URL has priority)
                    if (!$currentCondominiumId || $currentCondominiumId != $dataCondominiumId) {
                        $condominiumModel = new \App\Models\Condominium();
                        $condominium = $condominiumModel->findById($dataCondominiumId);
                        $_SESSION['current_condominium_id'] = $dataCondominiumId;
                    }
                }
            }
            
            // Get unread notifications and messages count (filtered by current condominium if set)
            global $db;
            $unreadMessagesCount = 0;
            $unreadNotificationsCount = 0;
            $systemNotificationsCount = 0;
            
            if ($db) {
                $userId = $_SESSION['user']['id'] ?? null;
                if ($userId) {
                    $currentCondominiumId = $_SESSION['current_condominium_id'] ?? null;
                    
                    try {
                        // Get notifications filtered by current condominium if set
                        $notificationService = new \App\Services\NotificationService();
                        if ($currentCondominiumId) {
                            // Get notifications only for current condominium
                            $notifications = $notificationService->getUserNotifications($userId, 1000);
                            $notifications = array_filter($notifications, function($n) use ($currentCondominiumId) {
                                return isset($n['condominium_id']) && $n['condominium_id'] == $currentCondominiumId;
                            });
                        } else {
                            // If no condominium selected, get all notifications
                            $notifications = $notificationService->getUserNotifications($userId, 1000);
                        }
                        
                        $systemNotificationsCount = count(array_filter($notifications, function($n) {
                            return !$n['is_read'];
                        }));
                    } catch (\Exception $e) {
                        // Silently fail if notifications table doesn't exist or other error
                        $systemNotificationsCount = 0;
                    }
                    
                    // Get unread messages count for current condominium only
                    try {
                        if ($currentCondominiumId) {
                            // Count unread messages only for current condominium
                            $messageModel = new \App\Models\Message();
                            $unreadMessagesCount = $messageModel->getUnreadCount($currentCondominiumId, $userId);
                        } else {
                            // If no condominium selected, count messages from all condominiums user has access to
                            $userRole = $_SESSION['user']['role'] ?? 'condomino';
                            if ($userRole === 'admin' || $userRole === 'super_admin') {
                                $condominiumModel = new \App\Models\Condominium();
                                $userCondominiums = $condominiumModel->getByUserId($userId);
                            } else {
                                $condominiumUserModel = new \App\Models\CondominiumUser();
                                $userCondominiumsList = $condominiumUserModel->getUserCondominiums($userId);
                                $condominiumModel = new \App\Models\Condominium();
                                $userCondominiums = [];
                                foreach ($userCondominiumsList as $uc) {
                                    $condo = $condominiumModel->findById($uc['condominium_id']);
                                    if ($condo) {
                                        $userCondominiums[] = $condo;
                                    }
                                }
                            }
                            
                            $messageModel = new \App\Models\Message();
                            foreach ($userCondominiums as $condo) {
                                $unreadMessagesCount += $messageModel->getUnreadCount($condo['id'], $userId);
                            }
                        }
                    } catch (\Exception $e) {
                        // Silently fail if messages table doesn't exist or other error
                        $unreadMessagesCount = 0;
                    }
                    
                    // Unified count includes both notifications and messages
                    $unreadNotificationsCount = $systemNotificationsCount + $unreadMessagesCount;
                }
            }
        }
        
        // Final check: if data has condominium, it means user explicitly selected it from URL
        // This should override any session-based condominium
        if (isset($data['condominium']) && isset($data['condominium']['id'])) {
            $dataCondominiumId = $data['condominium']['id'];
            // Always use condominium from data (URL parameter) - it has highest priority
            $condominiumModel = new \App\Models\Condominium();
            $condominium = $condominiumModel->findById($dataCondominiumId);
            $_SESSION['current_condominium_id'] = $dataCondominiumId;
            
            // Get user's role in this condominium
            if (isset($userId)) {
                $currentCondominiumRole = \App\Middleware\RoleMiddleware::getUserRoleInCondominium($userId, $dataCondominiumId);
            }
        }
        
        // Initialize current_condominium_role if not set
        if (!isset($currentCondominiumRole) && isset($currentCondominiumId) && isset($userId)) {
            $currentCondominiumRole = \App\Middleware\RoleMiddleware::getUserRoleInCondominium($userId, $currentCondominiumId);
        }
        
        // Check if user can switch between admin/condomino view
        $canSwitchViewMode = false;
        if (isset($currentCondominiumId) && isset($userId)) {
            $canSwitchViewMode = \App\Middleware\RoleMiddleware::hasBothRolesInCondominium($userId, $currentCondominiumId);
        }
        
        // CRITICAL: Check for preview template_id FIRST, before any database queries
        // This takes ABSOLUTE priority over database template
        $hasPreviewTemplateId = array_key_exists('template_id', $data);
        $previewTemplateId = $hasPreviewTemplateId ? $data['template_id'] : null;
        
        // Get template and logo for current condominium
        $templateId = null; // Default template (null means use system default, no custom CSS)
        $logoUrl = null;
        $condominiumForTemplate = $condominium ?? ($data['condominium'] ?? null);
        
        // Always load logo from condominium if available
        if ($condominiumForTemplate && isset($condominiumForTemplate['id'])) {
            $condominiumModel = new \App\Models\Condominium();
            
            // Only get template from database if NOT doing a preview
            // If hasPreviewTemplateId is true, we will use the preview template instead
            if (!$hasPreviewTemplateId) {
                $templateId = $condominiumModel->getDocumentTemplate($condominiumForTemplate['id']);
                // Only set template ID if it's valid (1-17), otherwise keep as null (default)
                if ($templateId !== null && ($templateId < 1 || $templateId > 17)) {
                    $templateId = null;
                }
            }
            
            // Always load logo
            $logoPath = $condominiumModel->getLogoPath($condominiumForTemplate['id']);
            if ($logoPath) {
                $fileStorageService = new \App\Services\FileStorageService();
                $logoUrl = $fileStorageService->getFileUrl($logoPath);
            }
        }
        
        // Use preview template if available, otherwise use database template
        $finalTemplateId = $hasPreviewTemplateId ? $previewTemplateId : $templateId;
        
        // Prepare base merged data
        $baseMergedData = [
            't' => new \App\Core\Translator($currentLang),
            'user' => $_SESSION['user'] ?? null,
            'session' => array_merge($_SESSION ?? [], ['lang' => $currentLang]),
            'BASE_URL' => BASE_URL,
            'VERSION' => defined('VERSION') ? VERSION : '1.0.0',
            'APP_ENV' => APP_ENV,
            'current_lang' => $currentLang,
            'demo_banner_message' => $demoBannerMessage,
            'unread_notifications_count' => $unreadNotificationsCount,
            'unread_messages_count' => $unreadMessagesCount,
            'current_condominium_role' => $currentCondominiumRole ?? null,
            'can_switch_view_mode' => $canSwitchViewMode,
            'is_demo_user' => $isDemoUser,
            'demo_profile' => $demoProfile,
            'condominium' => $condominiumForTemplate,
            'user_condominiums' => $userCondominiums,
            'template_id' => $finalTemplateId, // Use preview if available, otherwise database template
            'logo_url' => $logoUrl,
            'csrf_token' => \App\Core\Security::generateCSRFToken(),
        ];
        
        // Merge with data - data comes LAST so it overrides baseMergedData
        $mergedData = array_merge($baseMergedData, $data);
        
        // CRITICAL: If template_id was explicitly set in data (preview), FORCE it to be used
        // This ensures preview always wins, even if something else tried to override it
        if ($hasPreviewTemplateId) {
            // Preview is active - use the preview template_id (can be null for default template preview)
            $mergedData['template_id'] = $previewTemplateId;
        }
        
        // Ensure condominium from data is used if present (URL parameter always wins)
        if (isset($mergedData['condominium']) && isset($mergedData['condominium']['id'])) {
            $_SESSION['current_condominium_id'] = $mergedData['condominium']['id'];
        }

        // Add optional constants only if they are defined
        if (defined('WEBSOCKET_URL')) {
            $mergedData['WEBSOCKET_URL'] = WEBSOCKET_URL;
        }
        if (defined('WEBSOCKET_AUTH_KEY')) {
            $mergedData['WEBSOCKET_AUTH_KEY'] = WEBSOCKET_AUTH_KEY;
        }
        if (defined('RECAPTCHA_SITE_KEY')) {
            $mergedData['RECAPTCHA_SITE_KEY'] = RECAPTCHA_SITE_KEY;
        }
        if (defined('RECAPTCHA_SECRET')) {
            $mergedData['RECAPTCHA_SECRET'] = RECAPTCHA_SECRET;
        }

        // Add global translations
        if (isset($this->data['global_t'])) {
            $mergedData['global_t'] = $this->data['global_t'];
        }

        // Add page translations if they exist (from loadPageTranslations via _page_t or from controller's page['t'])
        if (isset($this->data['_page_t'])) {
            $mergedData['page']['t'] = $this->data['_page_t'];
        } elseif (isset($this->data['page']['t'])) {
            $mergedData['page']['t'] = $this->data['page']['t'];
        }

        // Ensure page meta (titulo, description, keywords) is always set for mainTemplate <title> and meta tags
        $mergedData['page'] = $mergedData['page'] ?? [];
        if (empty($mergedData['page']['titulo']) && isset($this->page->titulo)) {
            $mergedData['page']['titulo'] = $this->page->titulo;
        }
        if (!isset($mergedData['page']['description']) || $mergedData['page']['description'] === '') {
            $mergedData['page']['description'] = $this->page->description ?? '';
        }
        if (!isset($mergedData['page']['keywords']) || $mergedData['page']['keywords'] === '') {
            $mergedData['page']['keywords'] = $this->page->keywords ?? '';
        }

        return $mergedData;
    }

    /**
     * Render mainTemplate with merged global data. Ensures page meta (titulo, description, keywords) is always available.
     */
    protected function renderMainTemplate(): void
    {
        $merged = $this->mergeGlobalData($this->data);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $merged);
    }
    
    /**
     * Update session condominium when condominium is set in data
     * This should be called after setting condominium in $this->data
     */
    protected function updateSessionCondominium(): void
    {
        if (isset($this->data['condominium']) && isset($this->data['condominium']['id'])) {
            $_SESSION['current_condominium_id'] = $this->data['condominium']['id'];
        }
    }

    protected function loadPageTranslations(string $pageName): void
    {
        $lang = $_SESSION['lang'] ?? 'pt';
        $pageTranslations = [];
        $pageFile = __DIR__ . "/../Metafiles/{$lang}/{$pageName}.json";
        
        if (file_exists($pageFile)) {
            $pageData = json_decode(file_get_contents($pageFile), true);
            if (isset($pageData['t'])) {
                $pageTranslations = $pageData['t'];
            }
        }
        
        // Store in _page_t so we don't create $this->data['page'] here - otherwise controller's
        // $this->data += [ 'page' => ['titulo' => '...'] ] would be ignored (key 'page' already exists)
        $this->data['_page_t'] = $pageTranslations;
    }

    protected function loadGlobalTranslations(): array
    {
        $lang = $_SESSION['lang'] ?? 'pt';
        $globalTranslations = [];
        $globalFile = __DIR__ . "/../Metafiles/{$lang}/global.json";
        if (file_exists($globalFile)) {
            $globalData = json_decode(file_get_contents($globalFile), true);
            if (isset($globalData['t'])) {
                $globalTranslations = $globalData['t'];
            }
        }
        return $globalTranslations;
    }

    protected function requireLogin(): void
    {
        if (empty($_SESSION['user'])) {
            header('Location: ' . BASE_URL . 'login');
            exit;
        }
    }

    protected function renderTwig(string $templatePath, array $data = [])
    {
        global $twig;
        echo $twig->render($templatePath, $data);
    }

    /**
     * Send secure JSON error response
     * Prevents exposure of sensitive information in production
     * 
     * @param \Exception|string $error Error message or exception
     * @param int $code HTTP status code
     * @param string|null $errorCode Optional error code for client reference
     */
    protected function jsonError($error, int $code = 400, ?string $errorCode = null): void
    {
        // Clear any previous output to prevent corruption of JSON response
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Clear any output that might have been sent
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        
        $isProduction = defined('APP_ENV') && APP_ENV === 'production';
        
        // In production, return generic messages; in development, include details
        if ($isProduction) {
            $message = 'An error occurred. Please try again later.';
            if ($errorCode) {
                $message .= ' Error code: ' . $errorCode;
            }
        } else {
            // In development, include full error details
            if ($error instanceof \Exception) {
                $message = $error->getMessage();
            } else {
                $message = $error;
            }
        }
        
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code
        ];
        
        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }
        
        // Log full error details regardless of environment
        if ($error instanceof \Exception) {
            error_log(sprintf(
                'JSON Error [%s]: %s in %s:%d - %s',
                $errorCode ?? 'UNKNOWN',
                $error->getMessage(),
                $error->getFile(),
                $error->getLine(),
                $error->getTraceAsString()
            ));
        } else {
            error_log(sprintf('JSON Error [%s]: %s', $errorCode ?? 'UNKNOWN', $message));
        }
        
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Send secure JSON success response
     * 
     * @param array $data Response data
     * @param string|null $message Optional success message
     * @param int $code HTTP status code
     */
    protected function jsonSuccess(array $data = [], ?string $message = null, int $code = 200): void
    {
        // Clear any previous output to prevent corruption of JSON response
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Clear any output that might have been sent
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }
        
        $response = [
            'success' => true,
            'data' => $data
        ];
        
        if ($message) {
            $response['message'] = $message;
        }
        
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
