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
        // Remove BASE_URL if present
        $route = str_replace(BASE_URL, '', $route);
        $route = trim($route, '/');
        
        // Check exact match
        if (in_array($route, self::$allowedRoutes)) {
            return true;
        }

        // Check if route starts with allowed prefix
        foreach (self::$allowedRoutes as $allowed) {
            if ($allowed && (strpos($route, $allowed . '/') === 0 || $route === $allowed)) {
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
        // Only check for authenticated users
        if (!AuthMiddleware::handle()) {
            return true; // Let AuthMiddleware handle unauthenticated users
        }

        $user = AuthMiddleware::user();
        if (!$user) {
            return true;
        }

        // Only check subscription for admin users
        // Regular users (condomino) don't need subscription
        if ($user['role'] !== 'admin' && $user['role'] !== 'super_admin') {
            return true;
        }

        // Super admin always has access
        if ($user['role'] === 'super_admin') {
            return true;
        }

        $subscriptionService = new SubscriptionService();
        
        // Check if user has active subscription
        if ($subscriptionService->hasActiveSubscription($user['id'])) {
            return true;
        }

        // Get current route
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $parsedUrl = parse_url($requestUri);
        $route = $parsedUrl['path'] ?? '';

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

        // If trial expired or no subscription, block access except allowed routes
        if ($trialExpired || $noSubscription) {
            // Allow access to subscription, payment, and profile pages
            if (self::isAllowedRoute($route)) {
                return true;
            }

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
