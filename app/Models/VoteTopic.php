<?php

namespace App\Models;

use App\Core\Model;

class VoteTopic extends Model
{
    protected $table = 'assembly_vote_topics';

    /**
     * Get topics by assembly
     */
    public function getByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT t.*, u.name as created_by_name,
                   (SELECT COUNT(*) FROM assembly_votes WHERE topic_id = t.id) as vote_count
            FROM assembly_vote_topics t
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.assembly_id = :assembly_id
            ORDER BY t.order_index ASC, t.created_at ASC
        ");

        $stmt->execute([':assembly_id' => $assemblyId]);
        $topics = $stmt->fetchAll() ?: [];

        // Parse JSON options
        foreach ($topics as &$topic) {
            $topic['options'] = json_decode($topic['options'] ?? '[]', true);
        }

        return $topics;
    }

    /**
     * Get active topics by assembly
     */
    public function getActiveByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT t.*, u.name as created_by_name
            FROM assembly_vote_topics t
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.assembly_id = :assembly_id AND t.is_active = TRUE
            ORDER BY t.order_index ASC, t.created_at ASC
        ");

        $stmt->execute([':assembly_id' => $assemblyId]);
        $topics = $stmt->fetchAll() ?: [];

        // Parse JSON options
        foreach ($topics as &$topic) {
            $topic['options'] = json_decode($topic['options'] ?? '[]', true);
        }

        return $topics;
    }

    /**
     * Create topic
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO assembly_vote_topics (
                assembly_id, title, description, options, order_index, created_by
            )
            VALUES (
                :assembly_id, :title, :description, :options, :order_index, :created_by
            )
        ");

        $options = $data['options'] ?? [];
        if (is_array($options)) {
            $options = json_encode($options);
        }

        $stmt->execute([
            ':assembly_id' => $data['assembly_id'],
            ':title' => $data['title'],
            ':description' => $data['description'] ?? null,
            ':options' => $options,
            ':order_index' => $data['order_index'] ?? 0,
            ':created_by' => $data['created_by']
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update topic
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if ($key === 'options' && is_array($value)) {
                $fields[] = "options = :options";
                $params[':options'] = json_encode($value);
            } elseif ($key === 'is_active' || $key === 'isActive') {
                // Convert boolean to integer for is_active column
                $fields[] = "is_active = :is_active";
                $params[':is_active'] = is_bool($value) ? ($value ? 1 : 0) : (int)$value;
            } else {
                $fields[] = "$key = :$key";
                $params[":$key"] = $value;
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE assembly_vote_topics SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete topic
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("DELETE FROM assembly_vote_topics WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Find topic by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM assembly_vote_topics WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $topic = $stmt->fetch();

        if ($topic && !empty($topic['options'])) {
            $topic['options'] = json_decode($topic['options'], true);
        }

        return $topic ?: null;
    }

    /**
     * Start voting for topic
     */
    public function startVoting(int $id): bool
    {
        return $this->update($id, [
            'voting_started_at' => date('Y-m-d H:i:s'),
            'is_active' => true
        ]);
    }

    /**
     * End voting for topic
     */
    public function endVoting(int $id): bool
    {
        return $this->update($id, [
            'voting_ended_at' => date('Y-m-d H:i:s'),
            'is_active' => false
        ]);
    }

    /**
     * Get options for condominium (from vote_options table)
     */
    public function getOptionsForCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $voteOptionModel = new VoteOption();
        $options = $voteOptionModel->getByCondominium($condominiumId);
        
        // Return just the labels as array for backward compatibility
        return array_map(function($option) {
            return $option['option_label'];
        }, $options);
    }
}
