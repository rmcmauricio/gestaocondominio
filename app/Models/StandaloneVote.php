<?php

namespace App\Models;

use App\Core\Model;

class StandaloneVote extends Model
{
    protected $table = 'standalone_votes';

    /**
     * Get votes by condominium
     */
    public function getByCondominium(int $condominiumId, array $filters = []): array
    {
        if (!$this->db) {
            return [];
        }

        $where = ["sv.condominium_id = :condominium_id"];
        $params = [':condominium_id' => $condominiumId];

        if (isset($filters['status'])) {
            $where[] = "sv.status = :status";
            $params[':status'] = $filters['status'];
        }

        $orderBy = $filters['order_by'] ?? 'created_at DESC';
        // Ensure order_by column is qualified with table alias
        if (strpos($orderBy, '.') === false) {
            $orderBy = 'sv.' . $orderBy;
        }

        $sql = "
            SELECT sv.*, u.name as created_by_name
            FROM standalone_votes sv
            LEFT JOIN users u ON u.id = sv.created_by
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $orderBy
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $votes = $stmt->fetchAll() ?: [];
        
        // Parse allowed_options JSON
        foreach ($votes as &$vote) {
            if (!empty($vote['allowed_options'])) {
                $vote['allowed_options'] = json_decode($vote['allowed_options'], true) ?: [];
            } else {
                $vote['allowed_options'] = [];
            }
        }
        
        return $votes;
    }

    /**
     * Get open votes by condominium (for dashboard)
     */
    public function getOpenByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT sv.*, u.name as created_by_name,
                   (SELECT COUNT(*) FROM standalone_vote_responses WHERE standalone_vote_id = sv.id) as vote_count
            FROM standalone_votes sv
            LEFT JOIN users u ON u.id = sv.created_by
            WHERE sv.condominium_id = :condominium_id AND sv.status = 'open'
            ORDER BY sv.voting_started_at DESC
        ");

        $stmt->execute([':condominium_id' => $condominiumId]);
        $votes = $stmt->fetchAll() ?: [];
        
        // Parse allowed_options JSON
        foreach ($votes as &$vote) {
            if (!empty($vote['allowed_options'])) {
                $vote['allowed_options'] = json_decode($vote['allowed_options'], true) ?: [];
            } else {
                $vote['allowed_options'] = [];
            }
        }
        
        return $votes;
    }

    /**
     * Get recent results (last N closed votes)
     */
    public function getRecentResults(int $condominiumId, int $limit = 3): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT sv.*, u.name as created_by_name
            FROM standalone_votes sv
            LEFT JOIN users u ON u.id = sv.created_by
            WHERE sv.condominium_id = :condominium_id AND sv.status = 'closed'
            ORDER BY sv.voting_ended_at DESC
            LIMIT :limit
        ");

        $stmt->bindValue(':condominium_id', $condominiumId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $votes = $stmt->fetchAll() ?: [];
        
        // Parse allowed_options JSON
        foreach ($votes as &$vote) {
            if (!empty($vote['allowed_options'])) {
                $vote['allowed_options'] = json_decode($vote['allowed_options'], true) ?: [];
            } else {
                $vote['allowed_options'] = [];
            }
        }
        
        return $votes;
    }

    /**
     * Create vote
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $allowedOptions = null;
        if (isset($data['allowed_options'])) {
            if (is_array($data['allowed_options'])) {
                $allowedOptions = json_encode($data['allowed_options']);
            } else {
                $allowedOptions = $data['allowed_options'];
            }
        }

        // Check if allowed_options column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM standalone_votes LIKE 'allowed_options'");
        $hasAllowedOptions = $stmt->rowCount() > 0;

        if ($hasAllowedOptions && $allowedOptions !== null) {
            $stmt = $this->db->prepare("
                INSERT INTO standalone_votes (
                    condominium_id, title, description, allowed_options, status, created_by
                )
                VALUES (
                    :condominium_id, :title, :description, :allowed_options, :status, :created_by
                )
            ");
            $stmt->execute([
                ':condominium_id' => $data['condominium_id'],
                ':title' => $data['title'],
                ':description' => $data['description'] ?? null,
                ':allowed_options' => $allowedOptions,
                ':status' => $data['status'] ?? 'draft',
                ':created_by' => $data['created_by']
            ]);
        } else {
            // Fallback if column doesn't exist yet or allowed_options not provided
            $stmt = $this->db->prepare("
                INSERT INTO standalone_votes (
                    condominium_id, title, description, status, created_by
                )
                VALUES (
                    :condominium_id, :title, :description, :status, :created_by
                )
            ");
            $stmt->execute([
                ':condominium_id' => $data['condominium_id'],
                ':title' => $data['title'],
                ':description' => $data['description'] ?? null,
                ':status' => $data['status'] ?? 'draft',
                ':created_by' => $data['created_by']
            ]);
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update vote
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        foreach ($data as $key => $value) {
            if ($key !== 'id') {
                if ($key === 'allowed_options' && is_array($value)) {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = json_encode($value);
                } elseif ($key === 'allowed_options' && is_string($value)) {
                    // Already JSON string
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                } else {
                    $fields[] = "$key = :$key";
                    $params[":$key"] = $value;
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE standalone_votes SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Start voting
     */
    public function startVoting(int $id): bool
    {
        return $this->update($id, [
            'status' => 'open',
            'voting_started_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Close voting
     */
    public function closeVoting(int $id): bool
    {
        return $this->update($id, [
            'status' => 'closed',
            'voting_ended_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Get vote results
     */
    public function getResults(int $id): array
    {
        if (!$this->db) {
            return [];
        }

        $vote = $this->findById($id);
        if (!$vote) {
            return [];
        }

        $allowedOptions = $vote['allowed_options'] ?? [];
        
        if (empty($allowedOptions)) {
            // If no allowed options specified, return all options (backward compatibility)
            $stmt = $this->db->prepare("
                SELECT 
                    vo.id as option_id,
                    vo.option_label,
                    COUNT(svr.id) as vote_count,
                    SUM(svr.weighted_value) as weighted_total,
                    GROUP_CONCAT(DISTINCT f.identifier ORDER BY f.identifier SEPARATOR ', ') as fractions_voted
                FROM vote_options vo
                LEFT JOIN standalone_vote_responses svr ON svr.standalone_vote_id = :vote_id AND svr.vote_option_id = vo.id
                LEFT JOIN fractions f ON f.id = svr.fraction_id
                WHERE vo.condominium_id = :condominium_id AND vo.is_active = TRUE
                GROUP BY vo.id, vo.option_label
                ORDER BY vo.order_index ASC
            ");
            $stmt->execute([
                ':vote_id' => $id,
                ':condominium_id' => $vote['condominium_id']
            ]);
        } else {
            // Only return results for allowed options
            $placeholders = implode(',', array_fill(0, count($allowedOptions), '?'));
            $stmt = $this->db->prepare("
                SELECT 
                    vo.id as option_id,
                    vo.option_label,
                    COUNT(svr.id) as vote_count,
                    SUM(svr.weighted_value) as weighted_total,
                    GROUP_CONCAT(DISTINCT f.identifier ORDER BY f.identifier SEPARATOR ', ') as fractions_voted
                FROM vote_options vo
                LEFT JOIN standalone_vote_responses svr ON svr.standalone_vote_id = ? AND svr.vote_option_id = vo.id
                LEFT JOIN fractions f ON f.id = svr.fraction_id
                WHERE vo.condominium_id = ? AND vo.is_active = TRUE AND vo.id IN ($placeholders)
                GROUP BY vo.id, vo.option_label
                ORDER BY vo.order_index ASC
            ");
            $params = array_merge([$id, $vote['condominium_id']], $allowedOptions);
            $stmt->execute($params);
        }
        
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Find vote by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT sv.*, u.name as created_by_name
            FROM standalone_votes sv
            LEFT JOIN users u ON u.id = sv.created_by
            WHERE sv.id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $vote = $stmt->fetch();

        if ($vote && !empty($vote['allowed_options'])) {
            $vote['allowed_options'] = json_decode($vote['allowed_options'], true) ?: [];
        } elseif ($vote) {
            $vote['allowed_options'] = [];
        }

        return $vote ?: null;
    }
}
