<?php

namespace App\Models;

use App\Core\Model;

class Vote extends Model
{
    protected $table = 'assembly_votes';

    /**
     * Get votes by assembly
     */
    public function getByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT v.*, u.name as user_name, f.identifier as fraction_identifier,
                   f.millage as fraction_millage
            FROM assembly_votes v
            INNER JOIN users u ON u.id = v.user_id
            INNER JOIN fractions f ON f.id = v.fraction_id
            WHERE v.assembly_id = :assembly_id
            ORDER BY v.created_at DESC
        ");

        $stmt->execute([':assembly_id' => $assemblyId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Get votes by topic
     */
    public function getByTopic(int $topicId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT v.*, u.name as user_name, f.identifier as fraction_identifier,
                   f.millage as fraction_millage
            FROM assembly_votes v
            INNER JOIN users u ON u.id = v.user_id
            INNER JOIN fractions f ON f.id = v.fraction_id
            WHERE v.topic_id = :topic_id
            ORDER BY v.created_at DESC
        ");

        $stmt->execute([':topic_id' => $topicId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create vote
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Check if user already voted on this topic
        if (isset($data['topic_id'])) {
            $stmt = $this->db->prepare("
                SELECT id FROM assembly_votes 
                WHERE topic_id = :topic_id AND fraction_id = :fraction_id
            ");
            $stmt->execute([
                ':topic_id' => $data['topic_id'],
                ':fraction_id' => $data['fraction_id']
            ]);

            if ($stmt->fetch()) {
                throw new \Exception("Já votou neste tópico");
            }
        }

        $stmt = $this->db->prepare("
            INSERT INTO assembly_votes (
                assembly_id, topic_id, fraction_id, user_id, vote_option, notes
            )
            VALUES (
                :assembly_id, :topic_id, :fraction_id, :user_id, :vote_option, :notes
            )
        ");

        $stmt->execute([
            ':assembly_id' => $data['assembly_id'],
            ':topic_id' => $data['topic_id'] ?? null,
            ':fraction_id' => $data['fraction_id'],
            ':user_id' => $data['user_id'],
            ':vote_option' => $data['vote_option'],
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Calculate vote results
     */
    public function calculateResults(int $topicId): array
    {
        if (!$this->db) {
            return [];
        }

        // Get topic info
        $stmt = $this->db->prepare("SELECT * FROM assembly_vote_topics WHERE id = :id");
        $stmt->execute([':id' => $topicId]);
        $topic = $stmt->fetch();

        if (!$topic) {
            return [];
        }

        // Get votes with millage
        $stmt = $this->db->prepare("
            SELECT v.vote_option, SUM(f.millage) as total_millage, COUNT(*) as vote_count
            FROM assembly_votes v
            INNER JOIN fractions f ON f.id = v.fraction_id
            WHERE v.topic_id = :topic_id
            GROUP BY v.vote_option
        ");
        $stmt->execute([':topic_id' => $topicId]);
        $votes = $stmt->fetchAll();

        $results = [
            'topic' => $topic,
            'options' => [],
            'total_millage' => 0,
            'total_votes' => 0
        ];

        foreach ($votes as $vote) {
            $results['options'][$vote['vote_option']] = [
                'millage' => (float)$vote['total_millage'],
                'count' => (int)$vote['vote_count']
            ];
            $results['total_millage'] += (float)$vote['total_millage'];
            $results['total_votes'] += (int)$vote['vote_count'];
        }

        // Calculate percentages
        foreach ($results['options'] as $option => $data) {
            $results['options'][$option]['percentage'] = $results['total_millage'] > 0 
                ? round(($data['millage'] / $results['total_millage']) * 100, 2)
                : 0;
        }

        return $results;
    }
}





