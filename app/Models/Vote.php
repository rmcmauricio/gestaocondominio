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
                   f.permillage as fraction_millage
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

        // Check if topic_id column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
        $hasTopicId = $stmt->rowCount() > 0;

        if ($hasTopicId) {
            $stmt = $this->db->prepare("
            SELECT v.*, u.name as user_name, f.identifier as fraction_identifier,
                   f.permillage as fraction_millage
            FROM assembly_votes v
            INNER JOIN users u ON u.id = v.user_id
            INNER JOIN fractions f ON f.id = v.fraction_id
            WHERE v.topic_id = :topic_id
            ORDER BY v.created_at DESC
        ");
            $stmt->execute([':topic_id' => $topicId]);
        } else {
            // Fallback: use vote_item pattern
            $stmt = $this->db->prepare("
            SELECT v.*, u.name as user_name, f.identifier as fraction_identifier,
                   f.permillage as fraction_millage
            FROM assembly_votes v
            INNER JOIN users u ON u.id = v.user_id
            INNER JOIN fractions f ON f.id = v.fraction_id
            WHERE v.vote_item = :vote_item
            ORDER BY v.created_at DESC
        ");
            $stmt->execute([':vote_item' => 'topic_' . $topicId]);
        }

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

        // Get fraction permillage for weighted calculation
        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($data['fraction_id']);
        $millage = $fraction['permillage'] ?? 0;

        // Note: Removed check for existing vote to allow updates via upsert method

        // Calculate weighted value based on millage
        $weightedValue = $millage;

        // Check if topic_id column exists, if not use vote_item for backward compatibility
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
        $hasTopicId = $stmt->rowCount() > 0;

        if ($hasTopicId) {
            // Check if vote_item column exists and fill it for backward compatibility
            $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'vote_item'");
            $hasVoteItem = $stmt->rowCount() > 0;
            
            $voteItem = null;
            if ($hasVoteItem && isset($data['topic_id'])) {
                $voteItem = 'topic_' . $data['topic_id'];
            }
            
            if ($hasVoteItem && $voteItem) {
                $stmt = $this->db->prepare("
                    INSERT INTO assembly_votes (
                        assembly_id, topic_id, fraction_id, user_id, vote_option, notes, weighted_value, vote_item
                    )
                    VALUES (
                        :assembly_id, :topic_id, :fraction_id, :user_id, :vote_option, :notes, :weighted_value, :vote_item
                    )
                ");

                $stmt->execute([
                    ':assembly_id' => $data['assembly_id'],
                    ':topic_id' => $data['topic_id'] ?? null,
                    ':fraction_id' => $data['fraction_id'],
                    ':user_id' => $data['user_id'],
                    ':vote_option' => $data['vote_option'],
                    ':notes' => $data['notes'] ?? null,
                    ':weighted_value' => $weightedValue,
                    ':vote_item' => $voteItem
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO assembly_votes (
                        assembly_id, topic_id, fraction_id, user_id, vote_option, notes, weighted_value
                    )
                    VALUES (
                        :assembly_id, :topic_id, :fraction_id, :user_id, :vote_option, :notes, :weighted_value
                    )
                ");

                $stmt->execute([
                    ':assembly_id' => $data['assembly_id'],
                    ':topic_id' => $data['topic_id'] ?? null,
                    ':fraction_id' => $data['fraction_id'],
                    ':user_id' => $data['user_id'],
                    ':vote_option' => $data['vote_option'],
                    ':notes' => $data['notes'] ?? null,
                    ':weighted_value' => $weightedValue
                ]);
            }
        } else {
            // Fallback to old structure
            $voteItem = $data['topic_id'] ? 'topic_' . $data['topic_id'] : ($data['vote_item'] ?? '');
            $voteValue = $this->mapVoteOptionToValue($data['vote_option'] ?? 'yes');
            
            $stmt = $this->db->prepare("
                INSERT INTO assembly_votes (
                    assembly_id, fraction_id, user_id, vote_item, vote_value, weighted_value
                )
                VALUES (
                    :assembly_id, :fraction_id, :user_id, :vote_item, :vote_value, :weighted_value
                )
            ");

            $stmt->execute([
                ':assembly_id' => $data['assembly_id'],
                ':fraction_id' => $data['fraction_id'],
                ':user_id' => $data['user_id'],
                ':vote_item' => $voteItem,
                ':vote_value' => $voteValue,
                ':weighted_value' => $weightedValue
            ]);
        }

        return (int)$this->db->lastInsertId();
    }

    /**
     * Map vote option to enum value
     */
    protected function mapVoteOptionToValue(string $option): string
    {
        $option = strtolower($option);
        if (in_array($option, ['sim', 'yes', 'aprovar', 'approve'])) {
            return 'yes';
        } elseif (in_array($option, ['não', 'no', 'rejeitar', 'reject'])) {
            return 'no';
        }
        return 'abstain';
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

        // Parse topic options
        $topic['options'] = json_decode($topic['options'] ?? '[]', true);

        // Check if topic_id column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
        $hasTopicId = $stmt->rowCount() > 0;

        if ($hasTopicId) {
            // Get votes with permillage using topic_id
            $stmt = $this->db->prepare("
                SELECT v.vote_option, SUM(f.permillage) as total_millage, 
                       SUM(v.weighted_value) as total_weighted_value,
                       COUNT(*) as vote_count
                FROM assembly_votes v
                INNER JOIN fractions f ON f.id = v.fraction_id
                WHERE v.topic_id = :topic_id
                GROUP BY v.vote_option
            ");
            $stmt->execute([':topic_id' => $topicId]);
        } else {
            // Fallback: use vote_item pattern
            $stmt = $this->db->prepare("
                SELECT v.vote_value as vote_option, SUM(f.permillage) as total_millage,
                       SUM(v.weighted_value) as total_weighted_value,
                       COUNT(*) as vote_count
                FROM assembly_votes v
                INNER JOIN fractions f ON f.id = v.fraction_id
                WHERE v.vote_item = :vote_item
                GROUP BY v.vote_value
            ");
            $stmt->execute([':vote_item' => 'topic_' . $topicId]);
        }

        $votes = $stmt->fetchAll();

        // Get total millage for the condominium
        $assembly = $this->db->prepare("SELECT condominium_id FROM assemblies WHERE id = (SELECT assembly_id FROM assembly_vote_topics WHERE id = :topic_id LIMIT 1)");
        $assembly->execute([':topic_id' => $topicId]);
        $assemblyData = $assembly->fetch();
        
        $totalCondominiumMillage = 0;
        if ($assemblyData) {
            $stmt = $this->db->prepare("SELECT SUM(permillage) as total FROM fractions WHERE condominium_id = :condominium_id AND is_active = TRUE");
            $stmt->execute([':condominium_id' => $assemblyData['condominium_id']]);
            $result = $stmt->fetch();
            $totalCondominiumMillage = (float)($result['total'] ?? 0);
        }

        $results = [
            'topic' => $topic,
            'options' => [],
            'total_millage' => 0,
            'total_weighted_value' => 0,
            'total_votes' => 0,
            'total_condominium_millage' => $totalCondominiumMillage
        ];

        foreach ($votes as $vote) {
            $option = $vote['vote_option'];
            $millage = (float)$vote['total_millage'];
            $weighted = (float)($vote['total_weighted_value'] ?? $millage);
            
            $results['options'][$option] = [
                'millage' => $millage,
                'weighted_value' => $weighted,
                'count' => (int)$vote['vote_count']
            ];
            $results['total_millage'] += $millage;
            $results['total_weighted_value'] += $weighted;
            $results['total_votes'] += (int)$vote['vote_count'];
        }

        // Calculate percentages based on millage
        foreach ($results['options'] as $option => $data) {
            $results['options'][$option]['percentage_by_millage'] = $results['total_millage'] > 0 
                ? round(($data['millage'] / $results['total_millage']) * 100, 2)
                : 0;
            
            $results['options'][$option]['percentage_by_condominium'] = $totalCondominiumMillage > 0
                ? round(($data['millage'] / $totalCondominiumMillage) * 100, 2)
                : 0;
            
            $results['options'][$option]['percentage_by_votes'] = $results['total_votes'] > 0
                ? round(($data['count'] / $results['total_votes']) * 100, 2)
                : 0;
        }

        return $results;
    }

    /**
     * Get vote by topic and fraction
     */
    public function getVoteByTopicAndFraction(int $topicId, int $fractionId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
        $hasTopicId = $stmt->rowCount() > 0;

        if ($hasTopicId) {
            $stmt = $this->db->prepare("
                SELECT v.*, u.name as user_name, f.identifier as fraction_identifier,
                       f.permillage as fraction_millage
                FROM assembly_votes v
                INNER JOIN users u ON u.id = v.user_id
                INNER JOIN fractions f ON f.id = v.fraction_id
                WHERE v.topic_id = :topic_id AND v.fraction_id = :fraction_id
                LIMIT 1
            ");
            $stmt->execute([':topic_id' => $topicId, ':fraction_id' => $fractionId]);
        } else {
            $stmt = $this->db->prepare("
                SELECT v.*, u.name as user_name, f.identifier as fraction_identifier,
                       f.permillage as fraction_millage
                FROM assembly_votes v
                INNER JOIN users u ON u.id = v.user_id
                INNER JOIN fractions f ON f.id = v.fraction_id
                WHERE v.vote_item = :vote_item AND v.fraction_id = :fraction_id
                LIMIT 1
            ");
            $stmt->execute([':vote_item' => 'topic_' . $topicId, ':fraction_id' => $fractionId]);
        }

        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Update vote
     */
    public function update(int $voteId, array $data): bool
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        // Get fraction permillage for weighted calculation
        $fractionModel = new Fraction();
        $fraction = $fractionModel->findById($data['fraction_id']);
        $millage = $fraction['permillage'] ?? 0;
        $weightedValue = $millage;

        // Check if vote_option column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'vote_option'");
        $hasVoteOption = $stmt->rowCount() > 0;

        if ($hasVoteOption) {
            // Check if notes column exists
            $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'notes'");
            $hasNotes = $stmt->rowCount() > 0;

            // Check if updated_at column exists
            $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'updated_at'");
            $hasUpdatedAt = $stmt->rowCount() > 0;

            if ($hasNotes) {
                if ($hasUpdatedAt) {
                    $stmt = $this->db->prepare("
                        UPDATE assembly_votes 
                        SET vote_option = :vote_option, 
                            weighted_value = :weighted_value,
                            notes = :notes,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                } else {
                    $stmt = $this->db->prepare("
                        UPDATE assembly_votes 
                        SET vote_option = :vote_option, 
                            weighted_value = :weighted_value,
                            notes = :notes
                        WHERE id = :id
                    ");
                }
                $stmt->execute([
                    ':id' => $voteId,
                    ':vote_option' => $data['vote_option'],
                    ':weighted_value' => $weightedValue,
                    ':notes' => $data['notes'] ?? null
                ]);
            } else {
                if ($hasUpdatedAt) {
                    $stmt = $this->db->prepare("
                        UPDATE assembly_votes 
                        SET vote_option = :vote_option, 
                            weighted_value = :weighted_value,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ");
                } else {
                    $stmt = $this->db->prepare("
                        UPDATE assembly_votes 
                        SET vote_option = :vote_option, 
                            weighted_value = :weighted_value
                        WHERE id = :id
                    ");
                }
                $stmt->execute([
                    ':id' => $voteId,
                    ':vote_option' => $data['vote_option'],
                    ':weighted_value' => $weightedValue
                ]);
            }
        } else {
            // Fallback to old structure
            $voteValue = $this->mapVoteOptionToValue($data['vote_option'] ?? 'yes');
            
            // Check if updated_at column exists
            $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'updated_at'");
            $hasUpdatedAt = $stmt->rowCount() > 0;
            
            if ($hasUpdatedAt) {
                $stmt = $this->db->prepare("
                    UPDATE assembly_votes 
                    SET vote_value = :vote_value, 
                        weighted_value = :weighted_value,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
            } else {
                $stmt = $this->db->prepare("
                    UPDATE assembly_votes 
                    SET vote_value = :vote_value, 
                        weighted_value = :weighted_value
                    WHERE id = :id
                ");
            }
            $stmt->execute([
                ':id' => $voteId,
                ':vote_value' => $voteValue,
                ':weighted_value' => $weightedValue
            ]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Create or update vote (upsert)
     */
    public function upsert(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        if (!isset($data['topic_id']) || !isset($data['fraction_id'])) {
            throw new \Exception("topic_id and fraction_id are required");
        }

        // Check if vote already exists
        $existing = $this->getVoteByTopicAndFraction($data['topic_id'], $data['fraction_id']);
        
        if ($existing) {
            // Update existing vote
            $this->update($existing['id'], $data);
            return $existing['id'];
        } else {
            // Create new vote
            return $this->create($data);
        }
    }

    /**
     * Check if fraction already voted on topic
     */
    public function hasVoted(int $topicId, int $fractionId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
        $hasTopicId = $stmt->rowCount() > 0;

        if ($hasTopicId) {
            $stmt = $this->db->prepare("SELECT id FROM assembly_votes WHERE topic_id = :topic_id AND fraction_id = :fraction_id LIMIT 1");
            $stmt->execute([':topic_id' => $topicId, ':fraction_id' => $fractionId]);
        } else {
            $stmt = $this->db->prepare("SELECT id FROM assembly_votes WHERE vote_item = :vote_item AND fraction_id = :fraction_id LIMIT 1");
            $stmt->execute([':vote_item' => 'topic_' . $topicId, ':fraction_id' => $fractionId]);
        }

        return $stmt->fetch() !== false;
    }
}





