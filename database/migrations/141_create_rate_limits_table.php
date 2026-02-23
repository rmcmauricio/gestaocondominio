<?php

class CreateRateLimitsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS rate_limits (
            endpoint VARCHAR(50) NOT NULL,
            identifier VARCHAR(100) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_ends_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (endpoint, identifier),
            INDEX idx_window_ends_at (window_ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->db->exec($sql);
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS rate_limits");
    }
}
