<?php

namespace App\Models;

use App\Core\Model;

class PaymentMethodSettings extends Model
{
    protected $table = 'payment_methods_settings';

    /**
     * Find payment method by key
     */
    public function findByMethodKey(string $methodKey): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM payment_methods_settings WHERE method_key = :method_key LIMIT 1");
        $stmt->execute([':method_key' => $methodKey]);
        $method = $stmt->fetch();

        return $method ?: null;
    }

    /**
     * Check if payment method is enabled
     */
    public function isEnabled(string $methodKey): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT enabled FROM payment_methods_settings WHERE method_key = :method_key LIMIT 1");
        $stmt->execute([':method_key' => $methodKey]);
        $result = $stmt->fetch();

        return $result && (bool)$result['enabled'];
    }

    /**
     * Enable payment method
     */
    public function enable(string $methodKey): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE payment_methods_settings SET enabled = 1 WHERE method_key = :method_key");
        return $stmt->execute([':method_key' => $methodKey]);
    }

    /**
     * Disable payment method
     */
    public function disable(string $methodKey): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("UPDATE payment_methods_settings SET enabled = 0 WHERE method_key = :method_key");
        return $stmt->execute([':method_key' => $methodKey]);
    }

    /**
     * Get all payment methods with their status
     */
    public function getAll(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("SELECT * FROM payment_methods_settings ORDER BY method_key");
        $methods = $stmt->fetchAll();

        return $methods ?: [];
    }

    /**
     * Toggle payment method enabled status
     */
    public function toggle(string $methodKey): bool
    {
        if (!$this->db) {
            return false;
        }

        $method = $this->findByMethodKey($methodKey);
        if (!$method) {
            return false;
        }

        $newStatus = !(bool)$method['enabled'];
        $stmt = $this->db->prepare("UPDATE payment_methods_settings SET enabled = :enabled WHERE method_key = :method_key");
        return $stmt->execute([
            ':enabled' => $newStatus ? 1 : 0,
            ':method_key' => $methodKey
        ]);
    }
}
