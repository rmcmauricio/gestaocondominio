<?php

class ConvocationRegisteredLetter
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_convocation_recipients LIKE 'registered_letter_number'");
        if ($stmt->rowCount() === 0) {
            $this->db->exec("ALTER TABLE assembly_convocation_recipients ADD COLUMN registered_letter_number VARCHAR(100) NULL COMMENT 'Número da carta registada (envio por correio)' AFTER sent_at");
        }
        $stmt = $this->db->query("SHOW COLUMNS FROM assembly_convocation_recipients LIKE 'sent_at'");
        if ($stmt->rowCount() > 0) {
            $this->db->exec("ALTER TABLE assembly_convocation_recipients MODIFY COLUMN sent_at TIMESTAMP NULL DEFAULT NULL COMMENT 'Data/hora envio por email (NULL se só enviado por correio)'");
        }
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE assembly_convocation_recipients DROP COLUMN IF EXISTS registered_letter_number");
        $this->db->exec("ALTER TABLE assembly_convocation_recipients MODIFY COLUMN sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
}
