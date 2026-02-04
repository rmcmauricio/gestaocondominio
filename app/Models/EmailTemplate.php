<?php

namespace App\Models;

use App\Core\Model;

class EmailTemplate extends Model
{
    protected $table = 'email_templates';

    /**
     * Find template by key
     */
    public function findByKey(string $key): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE template_key = :key AND is_active = TRUE LIMIT 1");
        $stmt->execute([':key' => $key]);
        $template = $stmt->fetch();

        if ($template && $template['available_fields']) {
            $template['available_fields'] = json_decode($template['available_fields'], true) ?: [];
        }

        return $template ?: null;
    }

    /**
     * Get base layout template
     */
    public function getBaseLayout(): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE is_base_layout = TRUE AND is_active = TRUE LIMIT 1");
        $stmt->execute();
        $template = $stmt->fetch();

        if ($template && $template['available_fields']) {
            $template['available_fields'] = json_decode($template['available_fields'], true) ?: [];
        }

        return $template ?: null;
    }

    /**
     * Get all templates (excluding base layout)
     */
    public function getAll(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT * FROM {$this->table} 
            WHERE is_base_layout = FALSE 
            ORDER BY name ASC
        ");

        $templates = $stmt->fetchAll() ?: [];
        
        foreach ($templates as &$template) {
            if ($template['available_fields']) {
                $template['available_fields'] = json_decode($template['available_fields'], true) ?: [];
            }
        }
        unset($template);

        return $templates;
    }

    /**
     * Get all templates including base layout
     */
    public function getAllIncludingBase(): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->query("
            SELECT * FROM {$this->table} 
            ORDER BY is_base_layout DESC, name ASC
        ");

        $templates = $stmt->fetchAll() ?: [];
        
        foreach ($templates as &$template) {
            if ($template['available_fields']) {
                $template['available_fields'] = json_decode($template['available_fields'], true) ?: [];
            }
        }
        unset($template);

        return $templates;
    }

    /**
     * Find template by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $template = $stmt->fetch();

        if ($template && $template['available_fields']) {
            $template['available_fields'] = json_decode($template['available_fields'], true) ?: [];
        }

        return $template ?: null;
    }

    /**
     * Update template
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $allowedFields = ['name', 'description', 'subject', 'html_body', 'text_body', 'available_fields', 'is_active'];
        $updates = [];
        $params = [':id' => $id];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'available_fields' && is_array($data[$field])) {
                    $updates[] = "{$field} = :{$field}";
                    $params[":{$field}"] = json_encode($data[$field]);
                } else {
                    $updates[] = "{$field} = :{$field}";
                    $params[":{$field}"] = $data[$field];
                }
            }
        }

        if (empty($updates)) {
            return false;
        }

        $sql = "UPDATE {$this->table} SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        
        return $stmt->execute($params);
    }

    /**
     * Create template
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $availableFields = isset($data['available_fields']) && is_array($data['available_fields']) 
            ? json_encode($data['available_fields']) 
            : null;

        $stmt = $this->db->prepare("
            INSERT INTO {$this->table} (
                template_key, name, description, subject, html_body, text_body, 
                available_fields, is_base_layout, is_active
            )
            VALUES (
                :template_key, :name, :description, :subject, :html_body, :text_body,
                :available_fields, :is_base_layout, :is_active
            )
        ");

        $stmt->execute([
            ':template_key' => $data['template_key'],
            ':name' => $data['name'],
            ':description' => $data['description'] ?? null,
            ':subject' => $data['subject'] ?? null,
            ':html_body' => $data['html_body'],
            ':text_body' => $data['text_body'] ?? null,
            ':available_fields' => $availableFields,
            ':is_base_layout' => isset($data['is_base_layout']) ? ($data['is_base_layout'] ? 1 : 0) : 0,
            ':is_active' => isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Get available fields for a template key
     */
    public function getAvailableFields(string $key): array
    {
        $template = $this->findByKey($key);
        
        if (!$template || empty($template['available_fields'])) {
            return [];
        }

        return is_array($template['available_fields']) 
            ? $template['available_fields'] 
            : (json_decode($template['available_fields'], true) ?: []);
    }

    /**
     * Check if template exists by key
     */
    public function existsByKey(string $key): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE template_key = :key");
        $stmt->execute([':key' => $key]);
        
        return $stmt->fetchColumn() > 0;
    }
}
