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
        if (!empty($_SESSION['user'])) {
            $demoBannerMessage = \App\Middleware\DemoProtectionMiddleware::getDemoBannerMessage();
        }
        
        $mergedData = array_merge([
            't' => new \App\Core\Translator($currentLang),
            'user' => $_SESSION['user'] ?? null,
            'session' => array_merge($_SESSION ?? [], ['lang' => $currentLang]),
            'BASE_URL' => BASE_URL,
            'APP_ENV' => APP_ENV,
            'current_lang' => $currentLang,
            'demo_banner_message' => $demoBannerMessage,
        ], $data);

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
