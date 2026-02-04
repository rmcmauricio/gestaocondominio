<?php

namespace App\Models;

use App\Core\Model;

class AssemblyAgendaPoint extends Model
{
    protected $table = 'assembly_agenda_points';

    /**
     * Get agenda points by assembly, ordered by order_index.
     */
    public function getByAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT ap.id, ap.assembly_id, ap.order_index, ap.title, ap.body, ap.created_at, ap.updated_at
            FROM assembly_agenda_points ap
            WHERE ap.assembly_id = :assembly_id
            ORDER BY ap.order_index ASC, ap.id ASC
        ");
        $stmt->execute([':assembly_id' => $assemblyId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Create an agenda point (without vote topics; use setVoteTopicsForPoint after).
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }
        $stmt = $this->db->prepare("
            INSERT INTO assembly_agenda_points (assembly_id, order_index, title, body)
            VALUES (:assembly_id, :order_index, :title, :body)
        ");
        $stmt->execute([
            ':assembly_id' => $data['assembly_id'],
            ':order_index' => $data['order_index'] ?? 0,
            ':title' => $data['title'],
            ':body' => $data['body'] ?? null
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an agenda point.
     */
    public function update(int $id, array $data): bool
    {
        if (!$this->db) {
            return false;
        }
        $fields = [];
        $params = [':id' => $id];
        $allowed = ['order_index', 'title', 'body'];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "{$k} = :{$k}";
                $params[":{$k}"] = $data[$k];
            }
        }
        if (empty($fields)) {
            return false;
        }
        $sql = "UPDATE assembly_agenda_points SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Delete an agenda point (pivot rows CASCADE).
     */
    public function delete(int $id): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM assembly_agenda_points WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Delete all agenda points for an assembly.
     */
    public function deleteByAssembly(int $assemblyId): bool
    {
        if (!$this->db) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM assembly_agenda_points WHERE assembly_id = :assembly_id");
        return $stmt->execute([':assembly_id' => $assemblyId]);
    }

    /**
     * Set vote topic IDs for a point (replaces existing). order_index 0,1,2...
     */
    public function setVoteTopicsForPoint(int $agendaPointId, array $voteTopicIds): void
    {
        if (!$this->db) {
            return;
        }
        $this->db->prepare("DELETE FROM assembly_agenda_point_vote_topics WHERE agenda_point_id = :aid")
            ->execute([':aid' => $agendaPointId]);
        $ins = $this->db->prepare("
            INSERT INTO assembly_agenda_point_vote_topics (agenda_point_id, vote_topic_id, order_index) VALUES (:aid, :tid, :idx)
        ");
        foreach (array_values($voteTopicIds) as $idx => $tid) {
            $tid = (int) $tid;
            if ($tid <= 0) continue;
            $ins->execute([':aid' => $agendaPointId, ':tid' => $tid, ':idx' => $idx]);
        }
    }

    /**
     * Get vote topic IDs for a point, ordered by order_index.
     */
    public function getVoteTopicIdsForPoint(int $agendaPointId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT vote_topic_id FROM assembly_agenda_point_vote_topics
            WHERE agenda_point_id = :aid ORDER BY order_index ASC
        ");
        $stmt->execute([':aid' => $agendaPointId]);
        return array_column($stmt->fetchAll() ?: [], 'vote_topic_id');
    }

    /**
     * Get [agenda_point_id => [vote_topic_id, ...]] for an assembly.
     */
    public function getPointVoteTopicIdsForAssembly(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT v.agenda_point_id, v.vote_topic_id
            FROM assembly_agenda_point_vote_topics v
            INNER JOIN assembly_agenda_points ap ON ap.id = v.agenda_point_id
            WHERE ap.assembly_id = :aid
            ORDER BY v.agenda_point_id, v.order_index
        ");
        $stmt->execute([':aid' => $assemblyId]);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['agenda_point_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = [];
            }
            $out[$pid][] = (int) $r['vote_topic_id'];
        }
        return $out;
    }

    /**
     * Get all vote_topic_ids used in any agenda point of this assembly (from pivot).
     */
    public function getTopicIdsInUse(int $assemblyId): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->prepare("
            SELECT v.vote_topic_id FROM assembly_agenda_point_vote_topics v
            INNER JOIN assembly_agenda_points ap ON ap.id = v.agenda_point_id
            WHERE ap.assembly_id = :aid
        ");
        $stmt->execute([':aid' => $assemblyId]);
        $rows = $stmt->fetchAll() ?: [];
        return array_values(array_unique(array_column($rows, 'vote_topic_id')));
    }

    /**
     * Add a vote topic to a point. Returns false if already exists or topic in another point.
     */
    public function addVoteTopicToPoint(int $agendaPointId, int $voteTopicId, int $assemblyId): bool
    {
        if (!$this->db) return false;
        $point = $this->findById($agendaPointId);
        if (!$point || (int)$point['assembly_id'] !== $assemblyId) return false;
        $used = $this->getTopicIdsInUse($assemblyId);
        $mine = $this->getVoteTopicIdsForPoint($agendaPointId);
        if (in_array($voteTopicId, $mine, true)) return false;
        $usedByOthers = array_diff($used, $mine);
        if (in_array($voteTopicId, $usedByOthers, true)) return false;
        $max = $this->db->prepare("SELECT COALESCE(MAX(order_index),-1)+1 AS n FROM assembly_agenda_point_vote_topics WHERE agenda_point_id = ?");
        $max->execute([$agendaPointId]);
        $idx = (int) ($max->fetch()['n'] ?? 0);
        $ins = $this->db->prepare("INSERT INTO assembly_agenda_point_vote_topics (agenda_point_id, vote_topic_id, order_index) VALUES (?, ?, ?)");
        return $ins->execute([$agendaPointId, $voteTopicId, $idx]);
    }

    /**
     * Remove a vote topic from a point.
     */
    public function removeVoteTopicFromPoint(int $agendaPointId, int $voteTopicId): bool
    {
        if (!$this->db) return false;
        $stmt = $this->db->prepare("DELETE FROM assembly_agenda_point_vote_topics WHERE agenda_point_id = :aid AND vote_topic_id = :tid");
        return $stmt->execute([':aid' => $agendaPointId, ':tid' => $voteTopicId]);
    }

    /**
     * Reorder agenda points by setting order_index from the ordered list of IDs.
     */
    public function reorder(int $assemblyId, array $orderedIds): void
    {
        if (!$this->db || empty($orderedIds)) {
            return;
        }
        $idx = 0;
        foreach ($orderedIds as $id) {
            $id = (int) $id;
            if ($id <= 0) continue;
            $stmt = $this->db->prepare("
                UPDATE assembly_agenda_points SET order_index = :idx WHERE id = :id AND assembly_id = :assembly_id
            ");
            $stmt->execute([':idx' => $idx, ':id' => $id, ':assembly_id' => $assemblyId]);
            $idx++;
        }
    }

    /**
     * Find by ID.
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT * FROM assembly_agenda_points WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
