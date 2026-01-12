<?php

class UpdateAssemblyVotesMakeVoteItemNullable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Make vote_item nullable since we're using topic_id now
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes WHERE Field = 'vote_item'");
        $column = $stmt->fetch();
        
        if ($column && strpos($column['Null'], 'NO') !== false) {
            // Check if topic_id column exists
            $stmt = $this->db->query("SHOW COLUMNS FROM assembly_votes LIKE 'topic_id'");
            $hasTopicId = $stmt->rowCount() > 0;
            
            if ($hasTopicId) {
                // Make vote_item nullable
                $this->db->exec("ALTER TABLE assembly_votes MODIFY COLUMN vote_item VARCHAR(255) NULL");
            }
        }
    }

    public function down(): void
    {
        // Make vote_item NOT NULL again
        $this->db->exec("ALTER TABLE assembly_votes MODIFY COLUMN vote_item VARCHAR(255) NOT NULL");
    }
}
