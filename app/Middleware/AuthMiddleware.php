<?php

namespace App\Middleware;

class AuthMiddleware
{
    /**
     * Check if user is authenticated
     */
    public static function handle(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
            return false;
        }

        return true;
    }

    /**
     * Require authentication - redirect if not authenticated
     */
    public static function require(): void
    {
        if (!self::handle()) {
            $_SESSION['login_error'] = 'Por favor, faça login para continuar.';
            header('Location: ' . BASE_URL . 'login');
            exit;
        }
    }

    /**
     * Get current authenticated user
     */
    public static function user(): ?array
    {
        if (!self::handle()) {
            return null;
        }

        return $_SESSION['user'] ?? null;
    }

    /**
     * Get current user ID
     */
    public static function userId(): ?int
    {
        $user = self::user();
        return $user['id'] ?? null;
    }

    /**
     * Check if user is guest (not authenticated)
     */
    public static function guest(): bool
    {
        return !self::handle();
    }

    /**
     * Require guest - redirect if authenticated
     */
    public static function requireGuest(): void
    {
        if (self::handle()) {
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }
}





