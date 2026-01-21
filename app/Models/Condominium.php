<?php

namespace App\Models;

use App\Core\Model;

class Condominium extends Model
{
    protected $table = 'condominiums';

    /**
     * Get all condominiums for user
     */
    public function getByUserId(int $userId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM condominiums 
            WHERE user_id = :user_id AND is_active = TRUE
            ORDER BY created_at DESC
        ");

        $stmt->execute([':user_id' => $userId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find condominium by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM condominiums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Create condominium
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if is_demo column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM condominiums LIKE 'is_demo'");
        $hasIsDemo = $stmt->rowCount() > 0;
        
        if ($hasIsDemo) {
            $stmt = $this->db->prepare("
                INSERT INTO condominiums (
                    user_id, name, address, postal_code, city, country,
                    nif, iban, phone, email, type, total_fractions, rules, settings, is_demo, is_active
                )
                VALUES (
                    :user_id, :name, :address, :postal_code, :city, :country,
                    :nif, :iban, :phone, :email, :type, :total_fractions, :rules, :settings, :is_demo, :is_active
                )
            ");
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO condominiums (
                    user_id, name, address, postal_code, city, country,
                    nif, iban, phone, email, type, total_fractions, rules, settings, is_active
                )
                VALUES (
                    :user_id, :name, :address, :postal_code, :city, :country,
                    :nif, :iban, :phone, :email, :type, :total_fractions, :rules, :settings, :is_active
                )
            ");
        }

        $params = [
            ':user_id' => $data['user_id'],
            ':name' => $data['name'],
            ':address' => $data['address'],
            ':postal_code' => $data['postal_code'] ?? null,
            ':city' => $data['city'] ?? null,
            ':country' => $data['country'] ?? 'Portugal',
            ':nif' => $data['nif'] ?? null,
            ':iban' => $data['iban'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':type' => $data['type'] ?? 'habitacional',
            ':total_fractions' => $data['total_fractions'] ?? 0,
            ':rules' => $data['rules'] ?? null,
            ':settings' => json_encode($data['settings'] ?? [])
        ];
        
        if ($hasIsDemo) {
            $params[':is_demo'] = $data['is_demo'] ?? false;
            $params[':is_active'] = $data['is_active'] ?? true;
        } else {
            $params[':is_active'] = $data['is_active'] ?? true;
        }
        
        $stmt->execute($params);

        $condominiumId = (int)$this->db->lastInsertId();
        
        // If is_demo was provided but column doesn't exist, update via SQL
        if (!$hasIsDemo && isset($data['is_demo']) && $data['is_demo']) {
            $this->db->exec("UPDATE condominiums SET is_demo = TRUE WHERE id = {$condominiumId}");
        }
        
        return $condominiumId;
    }

    /**
     * Update condominium
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];
        $nullFields = [];

        foreach ($data as $key => $value) {
            if ($key === 'settings' && is_array($value)) {
                $fields[] = "settings = :settings";
                $params[':settings'] = json_encode($value);
            } elseif ($value === null) {
                // Handle NULL values explicitly - use SQL NULL directly
                $fields[] = "$key = NULL";
                $nullFields[] = $key;
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE condominiums SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        return $stmt->execute($params);
    }

    /**
     * Delete condominium (soft delete)
     */
    public function delete(int $id): bool
    {
        return $this->update($id, ['is_active' => false]);
    }

    /**
     * Get condominium with fractions count
     */
    public function getWithStats(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT c.*, 
                   COUNT(f.id) as fractions_count,
                   COUNT(CASE WHEN f.is_active = TRUE THEN 1 END) as active_fractions_count
            FROM condominiums c
            LEFT JOIN fractions f ON f.condominium_id = c.id
            WHERE c.id = :id
            GROUP BY c.id
        ");

        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Get document template ID for condominium
     * @param int $id Condominium ID
     * @return int Template ID (1-9), default 1
     */
    public function getDocumentTemplate(int $id): ?int
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT document_template FROM condominiums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        if ($result && isset($result['document_template']) && $result['document_template'] !== null) {
            $templateId = (int)$result['document_template'];
            // Validate template ID is between 1-9
            if ($templateId >= 1 && $templateId <= 9) {
                return $templateId;
            }
        }
        
        return null; // Default template (null means use system default, no custom CSS)
    }

    /**
     * Get logo path for condominium
     * @param int $id Condominium ID
     * @return string|null Logo path or null if not set
     */
    public function getLogoPath(int $id): ?string
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT logo_path FROM condominiums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        
        if ($result && isset($result['logo_path']) && !empty($result['logo_path'])) {
            return $result['logo_path'];
        }
        
        return null;
    }
}





