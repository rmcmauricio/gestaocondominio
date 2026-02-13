<?php

namespace Addons\HelpChatbot\Models;

class HelpArticle
{
    protected $db;

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Search help_articles by keywords (LIKE).
     */
    public function search(string $query, int $limit = 10): array
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
            $conditions[] = "(title LIKE :q{$i} OR body_text LIKE :q{$i})";
            $params[":q{$i}"] = '%' . $t . '%';
        }
        $sql = "SELECT id, section_key, title, body_text, url_path FROM help_articles WHERE " .
            implode(' AND ', $conditions) . " LIMIT " . (int) $limit;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
