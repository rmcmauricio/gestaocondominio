<?php

namespace Addons\HelpChatbot\Models;

class HelpFaq
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Search FAQ by keywords (LIKE).
     */
    public function search(string $query, int $limit = 15): array
    {
        if (!$this->db || trim($query) === '') {
            return [];
        }
        $terms = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($terms)) {
            return [];
        }
        $conditions = [];
        $params = [];
        foreach ($terms as $i => $t) {
            $conditions[] = "(question LIKE :q{$i} OR answer LIKE :q{$i} OR keywords LIKE :q{$i})";
            $params[":q{$i}"] = '%' . $t . '%';
        }
        $sql = "SELECT id, question, answer, keywords, sort_order FROM help_faq WHERE " .
            implode(' AND ', $conditions) . " ORDER BY sort_order ASC, id ASC LIMIT " . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function allOrdered(): array
    {
        if (!$this->db) {
            return [];
        }
        $stmt = $this->db->query("SELECT id, question, answer, keywords, sort_order FROM help_faq ORDER BY sort_order ASC, id ASC");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
