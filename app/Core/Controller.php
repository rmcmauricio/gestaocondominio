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
        
        // Ensure page translations are included
        if (isset($this->data['page']['t'])) {
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
        $isDemoUser = false;
        $demoProfile = null;
        $condominium = null;
        $userCondominiums = [];
        
        if (!empty($_SESSION['user'])) {
            $demoBannerMessage = \App\Middleware\DemoProtectionMiddleware::getDemoBannerMessage();
            
            // Check if we're in demo mode - either current user is demo OR demo_profile is set
            $currentUserIsDemo = \App\Middleware\DemoProtectionMiddleware::isDemoUser($_SESSION['user']['id'] ?? null);
            $demoProfile = $_SESSION['demo_profile'] ?? ($currentUserIsDemo ? 'admin' : null);
            
            // If demo_profile is set, we're in demo mode regardless of current user
            $isDemoUser = $currentUserIsDemo || isset($_SESSION['demo_profile']);
            
            // Get unread notifications count
            global $db;
            if ($db) {
                $userId = $_SESSION['user']['id'] ?? null;
                if ($userId) {
                    try {
                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = FALSE");
                        $stmt->execute([':user_id' => $userId]);
                        $result = $stmt->fetch();
                        $unreadNotificationsCount = (int)($result['count'] ?? 0);
                    } catch (\Exception $e) {
                        // Silently fail if notifications table doesn't exist or other error
                        $unreadNotificationsCount = 0;
                    }
                }
            }

            // Determine current condominium
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
                        if ($condo && !in_array($condo['id'], array_column($userCondominiums, 'id'))) {
                            $userCondominiums[] = $condo;
                        }
                    }
                }
                
                // If still not set, get first available condominium
                if (!$currentCondominiumId && !empty($userCondominiums)) {
                    $currentCondominiumId = $userCondominiums[0]['id'];
                    
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
                } elseif (isset($data['condominium'])) {
                    // Fallback: if no ID determined but we have condominium in data, use it
                    $condominium = $data['condominium'];
                    $_SESSION['current_condominium_id'] = $condominium['id'];
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
        }
        
        // Final check: if data has condominium, it means user explicitly selected it from URL
        // This should override any session-based condominium
        if (isset($data['condominium']) && isset($data['condominium']['id'])) {
            $dataCondominiumId = $data['condominium']['id'];
            // Always use condominium from data (URL parameter) - it has highest priority
            $condominiumModel = new \App\Models\Condominium();
            $condominium = $condominiumModel->findById($dataCondominiumId);
            $_SESSION['current_condominium_id'] = $dataCondominiumId;
        }
        
        $mergedData = array_merge([
            't' => new \App\Core\Translator($currentLang),
            'user' => $_SESSION['user'] ?? null,
            'session' => array_merge($_SESSION ?? [], ['lang' => $currentLang]),
            'BASE_URL' => BASE_URL,
            'APP_ENV' => APP_ENV,
            'current_lang' => $currentLang,
            'demo_banner_message' => $demoBannerMessage,
            'unread_notifications_count' => $unreadNotificationsCount,
            'is_demo_user' => $isDemoUser,
            'demo_profile' => $demoProfile,
            'condominium' => $condominium ?? ($data['condominium'] ?? null),
            'user_condominiums' => $userCondominiums,
        ], $data);
        
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

        // Add page translations if they exist
        if (isset($this->data['page']['t'])) {
            $mergedData['page']['t'] = $this->data['page']['t'];
        }

        return $mergedData;
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
        
        // Ensure page array is initialized
        if (!isset($this->data['page'])) {
            $this->data['page'] = [];
        }
        
        $this->data['page']['t'] = $pageTranslations;
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
}
