<?php

namespace App\Middleware;

use App\Models\User;
use App\Models\Condominium;

class DemoProtectionMiddleware
{
    /**
     * Check if current user is demo user
     */
    public static function isDemoUser(?int $userId = null): bool
    {
        if (!$userId) {
            $user = AuthMiddleware::user();
            $userId = $user['id'] ?? null;
        }

        if (!$userId) {
            return false;
        }

        global $db;
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("SELECT is_demo FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        return $user && (bool)$user['is_demo'];
    }

    /**
     * Check if condominium is demo
     */
    public static function isDemoCondominium(int $condominiumId): bool
    {
        global $db;
        if (!$db) {
            return false;
        }

        $stmt = $db->prepare("SELECT is_demo FROM condominiums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $condominiumId]);
        $condominium = $stmt->fetch();

        return $condominium && (bool)$condominium['is_demo'];
    }

    /**
     * Prevent editing demo user
     */
    public static function preventDemoUserEdit(int $userId): void
    {
        if (self::isDemoUser($userId)) {
            $_SESSION['error'] = 'Não é possível editar o utilizador demo. Este é uma conta de demonstração.';
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    /**
     * Prevent deleting demo condominium
     */
    public static function preventDemoCondominiumDelete(int $condominiumId): void
    {
        if (self::isDemoCondominium($condominiumId)) {
            $_SESSION['error'] = 'Não é possível eliminar o condomínio demo. Este é um condomínio de demonstração.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }
    }

    /**
     * Get demo banner message
     */
    public static function getDemoBannerMessage(): ?string
    {
        $user = AuthMiddleware::user();
        if (!$user) {
            return null;
        }

        if (self::isDemoUser($user['id'])) {
            return 'Esta é uma conta de demonstração. Todas as alterações serão repostas automaticamente.';
        }

        // Check if user is accessing a demo condominium
        // Try to get condominium_id from various sources
        $condominiumId = null;
        
        // From GET parameter
        if (isset($_GET['condominium_id'])) {
            $condominiumId = (int)$_GET['condominium_id'];
        }
        
        // From POST parameter
        if (!$condominiumId && isset($_POST['condominium_id'])) {
            $condominiumId = (int)$_POST['condominium_id'];
        }
        
        // From URL path (e.g., /condominiums/123/...)
        if (!$condominiumId && isset($_SERVER['REQUEST_URI'])) {
            if (preg_match('/condominiums\/(\d+)/', $_SERVER['REQUEST_URI'], $matches)) {
                $condominiumId = (int)$matches[1];
            }
        }
        
        if ($condominiumId && self::isDemoCondominium($condominiumId)) {
            return 'Está a visualizar um condomínio de demonstração. Todas as alterações serão repostas automaticamente.';
        }

        return null;
    }
}
