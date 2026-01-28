<?php

namespace App\Models;

use App\Core\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';

    /**
     * Get suppliers by condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT * FROM suppliers 
            WHERE condominium_id = :condominium_id OR condominium_id IS NULL
            AND is_active = TRUE
            ORDER BY name ASC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create supplier
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO suppliers (
                condominium_id, name, nif, address, phone, email, website, area, notes
            )
            VALUES (
                :condominium_id, :name, :nif, :address, :phone, :email, :website, :area, :notes
            )
        ");

        $stmt->execute([
            ':condominium_id' => $data['condominium_id'] ?? null,
            ':name' => $data['name'],
            ':nif' => $data['nif'] ?? null,
            ':address' => $data['address'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':website' => $data['website'] ?? null,
            ':area' => $data['area'] ?? null,
            ':notes' => $data['notes'] ?? null
        ]);

        $supplierId = (int)$this->db->lastInsertId();
        
        // Log audit
        $this->auditCreate($supplierId, $data);
        
        return $supplierId;
    }

    /**
     * Find supplier by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM suppliers WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update supplier
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            $fields[] = "$key = :$key";
            $params[":$key"] = $value;
        }

        if (empty($fields)) {
            return false;
        }

        // Get old data for audit
        $oldData = $this->findById($id);

        $sql = "UPDATE suppliers SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);

        $result = $stmt->execute($params);
        
        // Log audit
        if ($result) {
            $this->auditUpdate($id, $data, $oldData);
        }
        
        return $result;
    }

    /**
     * Delete supplier (soft delete)
     */
    public function delete(int $id): bool
    {
        // Get old data for audit before deletion
        $oldData = $this->findById($id);
        
        $result = $this->update($id, ['is_active' => false]);
        
        // Log audit (soft delete is treated as update, but log as delete for clarity)
        if ($result && $oldData) {
            $this->auditDelete($id, $oldData);
        }
        
        return $result;
    }
}





