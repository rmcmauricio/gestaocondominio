<?php

namespace App\Middleware;

use App\Services\SubscriptionService;

class SubscriptionMiddleware
{
    /**
     * Allowed routes when trial expires (relative to BASE_URL)
     */
    protected static $allowedRoutes = [
        'subscription',
        'payments',
        'profile',
        'logout',
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'auth/google',
        'auth/google/callback',
        'auth/select-account-type',
        'auth/select-plan',
        'demo',
        'demo/access',
        'about',
        'faq',
        'termos',
        'privacidade',
        'cookies',
        'lang',
        'help',
        'storage',
        ''
    ];

    /**
     * Check if route is allowed when trial expires
     */
    protected static function isAllowedRoute(string $route): bool
    {
        // Normalize route - remove leading/trailing slashes
        $route = trim($route, '/');
        
        // Check exact match first
        if (in_array($route, self::$allowedRoutes)) {
            return true;
        }

        // Check if route starts with allowed prefix
        foreach (self::$allowedRoutes as $allowed) {
            if (empty($allowed)) {
                // Empty string means root/homepage
                if ($route === '' || $route === false) {
                    return true;
                }
                continue;
            }
            
            // Exact match
            if ($route === $allowed) {
                return true;
            }
            
            // Check if route starts with allowed prefix followed by /
            if (strpos($route, $allowed . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle subscription check
     */
    public static function handle(): bool
    {
        // Get current route FIRST to check if it's allowed
        // This prevents redirect loops
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $parsedUrl = parse_url($requestUri);
        $route = $parsedUrl['path'] ?? '';
        
        // Remove BASE_PATH if defined (same logic as Router)
        if (defined('BASE_PATH') && !empty(BASE_PATH)) {
            $basePath = trim(BASE_PATH, '/');
            if (!empty($basePath)) {
                if (strpos($route, '/' . $basePath . '/') === 0) {
                    $route = substr($route, strlen('/' . $basePath));
                } elseif ($route === '/' . $basePath) {
                    $route = '/';
                }
            }
        } else {
            // Auto-detect subdirectory from script name (same as Router)
            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
            if (!empty($scriptName)) {
                $subfolder = str_replace('\\', '/', dirname($scriptName));
                $subfolder = trim($subfolder, '/');
                if (!empty($subfolder) && $subfolder !== '.') {
                    if (strpos($route, '/' . $subfolder . '/') === 0) {
                        $route = substr($route, strlen('/' . $subfolder));
                    } elseif ($route === '/' . $subfolder) {
                        $route = '/';
                    }
                }
            }
        }
        
        // Normalize route - remove leading/trailing slashes
        $route = trim($route, '/');
        
        // Only check for authenticated users
        if (!AuthMiddleware::handle()) {
            // If route is allowed for guests (login, register, etc.), allow it
            if (self::isAllowedRoute($route)) {
                return true;
            }
            return true; // Let AuthMiddleware handle unauthenticated users
        }

        $user = AuthMiddleware::user();
        if (!$user) {
            return true;
        }

        // Get user role safely
        $userRole = $user['role'] ?? null;
        
        // Only check subscription for admin users
        // Regular users (condomino) don't need subscription
        if ($userRole !== 'admin' && $userRole !== 'super_admin') {
            return true;
        }

        // Super admin always has access
        if ($userRole === 'super_admin') {
            return true;
        }

        $subscriptionService = new SubscriptionService();
        
        // Check if user has active subscription
        if ($subscriptionService->hasActiveSubscription($user['id'])) {
            return true;
        }

        // Get subscription to check status
        $subscriptionModel = new \App\Models\Subscription();
        $subscription = $subscriptionModel->getActiveSubscription($user['id']);
        $hasTrial = $subscription && $subscription['status'] === 'trial';
        $trialExpired = $hasTrial && $subscriptionService->isTrialExpired($user['id']);
        $noSubscription = !$subscription;

        // If trial is still active, allow access
        if ($hasTrial && !$trialExpired) {
            return true;
        }

        // Check if current route is allowed BEFORE blocking
        // This prevents redirect loops
        if (self::isAllowedRoute($route)) {
            return true; // Allow access to subscription, payment, profile pages, etc.
        }

        // If trial expired or no subscription, block access except allowed routes
        if ($trialExpired || $noSubscription) {
            // Block access - redirect to subscription page
            if ($trialExpired) {
                $_SESSION['error'] = 'O seu período experimental expirou. Por favor, escolha um plano para continuar a utilizar o serviço.';
            } else {
                $_SESSION['error'] = 'É necessário ter uma subscrição ativa para aceder a esta área. Por favor, escolha um plano.';
            }
            header('Location: ' . BASE_URL . 'subscription');
            exit;
        }

        return true;
    }

    /**
     * Require valid subscription or active trial
     */
    public static function require(): void
    {
        self::handle();
    }
}
