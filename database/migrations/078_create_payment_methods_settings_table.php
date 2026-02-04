<?php

class CreatePaymentMethodsSettingsTable
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS payment_methods_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                method_key VARCHAR(50) UNIQUE NOT NULL,
                enabled BOOLEAN DEFAULT TRUE,
                config_data JSON NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_method_key (method_key),
                INDEX idx_enabled (enabled)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS payment_methods_settings");
    }
}
