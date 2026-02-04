<?php

namespace App\Models;

use App\Core\Model;

class StandaloneVoteResponse extends Model
{
    protected $table = 'standalone_vote_responses';

    /**
     * Get responses by vote
     */
    public function getByVote(int $voteId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT svr.*, 
                   vo.option_label,
                   f.identifier as fraction_identifier,
                   f.permillage as fraction_permillage,
                   u.name as user_name
            FROM standalone_vote_responses svr
            INNER JOIN vote_options vo ON vo.id = svr.vote_option_id
            INNER JOIN fractions f ON f.id = svr.fraction_id
            LEFT JOIN users u ON u.id = svr.user_id
            WHERE svr.standalone_vote_id = :vote_id
            ORDER BY svr.created_at DESC
        ");

        $stmt->execute([':vote_id' => $voteId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get response by fraction
     */
    public function getByFraction(int $voteId, int $fractionId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT svr.*, vo.option_label
            FROM standalone_vote_responses svr
            INNER JOIN vote_options vo ON vo.id = svr.vote_option_id
            WHERE svr.standalone_vote_id = :vote_id AND svr.fraction_id = :fraction_id
            LIMIT 1
        ");

        $stmt->execute([
            ':vote_id' => $voteId,
            ':fraction_id' => $fractionId
        ]);
        $response = $stmt->fetch();

        return $response ?: null;
    }

    /**
     * Create or update response (upsert)
     */
    public function createOrUpdate(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Get fraction permillage for weighted calculation
        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($data['fraction_id']);
        $permillage = $fraction['permillage'] ?? 0;

        // Check if response already exists
        $existing = $this->getByFraction($data['standalone_vote_id'], $data['fraction_id']);

        if ($existing) {
            // Update existing response
            $stmt = $this->db->prepare("
                UPDATE standalone_vote_responses
                SET vote_option_id = :vote_option_id,
                    user_id = :user_id,
                    weighted_value = :weighted_value,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");

            $stmt->execute([
                ':id' => $existing['id'],
                ':vote_option_id' => $data['vote_option_id'],
                ':user_id' => $data['user_id'] ?? null,
                ':weighted_value' => $permillage,
                ':notes' => $data['notes'] ?? null
            ]);

            return $existing['id'];
        } else {
            // Create new response
            $stmt = $this->db->prepare("
                INSERT INTO standalone_vote_responses (
                    standalone_vote_id, fraction_id, user_id, vote_option_id, weighted_value, notes
                )
                VALUES (
                    :standalone_vote_id, :fraction_id, :user_id, :vote_option_id, :weighted_value, :notes
                )
            ");

            $stmt->execute([
                ':standalone_vote_id' => $data['standalone_vote_id'],
                ':fraction_id' => $data['fraction_id'],
                ':user_id' => $data['user_id'] ?? null,
                ':vote_option_id' => $data['vote_option_id'],
                ':weighted_value' => $permillage,
                ':notes' => $data['notes'] ?? null
            ]);

            return (int)$this->db->lastInsertId();
        }
    }

    /**
     * Get aggregated results
     */
    public function getResults(int $voteId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT 
                vo.id as option_id,
                vo.option_label,
                COUNT(svr.id) as vote_count,
                SUM(svr.weighted_value) as weighted_total,
                AVG(svr.weighted_value) as weighted_avg
            FROM vote_options vo
            INNER JOIN standalone_votes sv ON sv.id = :vote_id
            LEFT JOIN standalone_vote_responses svr ON svr.standalone_vote_id = :vote_id AND svr.vote_option_id = vo.id
            WHERE vo.condominium_id = sv.condominium_id AND vo.is_active = TRUE
            GROUP BY vo.id, vo.option_label
            ORDER BY vo.order_index ASC
        ");

        $stmt->execute([':vote_id' => $voteId]);
        return $stmt->fetchAll() ?: [];
    }
}
