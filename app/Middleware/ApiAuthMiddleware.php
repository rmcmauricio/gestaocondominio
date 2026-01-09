<?php

namespace App\Middleware;

use App\Models\User;

class ApiAuthMiddleware
{
    /**
     * Authenticate API request using API key
     */
    public static function handle(): ?array
    {
        // Get API key from header or query parameter
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;

        if (!$apiKey) {
            self::sendError('API key required', 401);
            return null;
        }

        global $db;
        if (!$db) {
            self::sendError('Database connection unavailable', 500);
            return null;
        }

        // Find user by API key
        $stmt = $db->prepare("
            SELECT id, email, name, role, status, api_key_created_at, api_key_last_used_at
            FROM users 
            WHERE api_key = :api_key 
            AND status = 'active'
            LIMIT 1
        ");

        $stmt->execute([':api_key' => $apiKey]);
        $user = $stmt->fetch();

        if (!$user) {
            self::sendError('Invalid API key', 401);
            return null;
        }

        // Check if user has BUSINESS plan
        $subscriptionModel = new \App\Models\Subscription();
        $subscription = $subscriptionModel->getActiveSubscription($user['id']);

        if (!$subscription) {
            self::sendError('No active subscription', 403);
            return null;
        }

        $planModel = new \App\Models\Plan();
        $plan = $planModel->findById($subscription['plan_id']);

        if (!$plan || $plan['slug'] !== 'business') {
            self::sendError('API access requires BUSINESS plan', 403);
            return null;
        }

        // Update last used timestamp
        $updateStmt = $db->prepare("
            UPDATE users 
            SET api_key_last_used_at = NOW() 
            WHERE id = :id
        ");
        $updateStmt->execute([':id' => $user['id']]);

        return $user;
    }

    /**
     * Require API authentication
     */
    public static function require(): array
    {
        $user = self::handle();
        if (!$user) {
            exit; // Error already sent
        }
        return $user;
    }

    /**
     * Send JSON error response
     */
    protected static function sendError(string $message, int $code = 400): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => $message,
            'code' => $code
        ]);
        exit;
    }
}





