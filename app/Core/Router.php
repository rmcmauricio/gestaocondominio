<?php

namespace App\Core;

use App\Core\Controller;

class Router extends Controller
{
    private array $routes = [];
    public Utils $utils;
    private $notFound;

    public function get(string $path, string $callback): void
    {
        $this->addRoute('GET', $path, $callback);
    }

    public function post(string $path, string $callback): void
    {
        $this->addRoute('POST', $path, $callback);
    }

    private function addRoute(string $method, string $path, string $callback): void
    {
        $this->routes[] = compact('method', 'path', 'callback');
    }

    public function setNotFound(callable $callback): void
    {
        $this->notFound = $callback;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }

    private function renderError(string $message, int $code = 500): void
    {
        http_response_code($code);

    $this->data += [
        'message' => $message,
        'code' => $code,
        'viewName' => 'notFound',
        'page' => [
            'titulo' => 'Erro ' . $code,
            'description' => '',
            'keywords' => ''
        ]
    ];
    echo $GLOBALS['twig']->render('templates/errorTemplate.html.twig', $this->data);
    }

    public function dispatch(?string $uri = null, ?string $method = null): void
    {
        $originalUri = $uri ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = $originalUri;
        $method = $method ?? $_SERVER['REQUEST_METHOD'];

        // Remove BASE_PATH se definido explicitamente
        if (defined('BASE_PATH') && !empty(BASE_PATH)) {
            $basePath = trim(BASE_PATH, '/');
            if (!empty($basePath)) {
                if (strpos($uri, '/' . $basePath . '/') === 0) {
                    $uri = substr($uri, strlen('/' . $basePath));
                } elseif ($uri === '/' . $basePath) {
                    $uri = '/';
                }
            }
        } 
        // Auto-detect subdirectory if BASE_PATH is not set
        else {
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            
            if (!empty($scriptName)) {
                // Get subdirectory from script name (e.g., /MVC/index.php -> /MVC)
                $subfolder = str_replace('\\', '/', dirname($scriptName));
                $subfolder = trim($subfolder, '/');
                
                // Remove subdirectory from URI if present
                if (!empty($subfolder)) {
                    if (strpos($uri, '/' . $subfolder . '/') === 0) {
                        $uri = substr($uri, strlen('/' . $subfolder));
                    } elseif ($uri === '/' . $subfolder) {
                        $uri = '/';
                    }
                }
            }
        }

        // Normalize URI
        $uri = trim($uri, '/');
        if ($uri === '' || $uri === false) {
            $uri = '/';
        } else {
            $uri = '/' . $uri;
        }

        foreach ($this->routes as $route) {
            $routePath = $route['path'];
            
            // Ensure route path starts with /
            if ($routePath !== '/' && $routePath[0] !== '/') {
                $routePath = '/' . $routePath;
            }
            
            // Convert route path to regex pattern
            // Special handling for {path} parameter - allow slashes for storage routes
            if (strpos($routePath, '/storage/{path}') !== false) {
                $pattern = str_replace('/storage/{path}', '/storage/(.+)', $routePath);
            } else {
                $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $routePath);
            }
            $pattern = str_replace('/', '\/', $pattern);
            $pattern = '/^' . $pattern . '$/';

            if ($method === $route['method'] && preg_match($pattern, $uri, $matches)) {
                array_shift($matches); // remove o full match

                [$controllerClass, $action] = explode('@', $route['callback']);

                if (!class_exists($controllerClass)) {
                    $this->renderError("Classe $controllerClass não encontrada", 500);
                    return;
                }

                $controller = new $controllerClass();

                if (!method_exists($controller, $action)) {
                    $this->renderError("Erro: Método $action não existe em $controllerClass", 500);
                    return;
                }

                // Check subscription middleware before calling controller
                \App\Middleware\SubscriptionMiddleware::handle();

                call_user_func_array([$controller, $action], $matches);
                return;
            }
        }

         $this->renderError("Página não encontrada", 404);

    }
}
