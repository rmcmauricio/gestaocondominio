<?php

class AddDirectDebitToPayments
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add 'direct_debit' to payment_method ENUM
        $this->db->exec("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('card', 'multibanco', 'mbway', 'sepa', 'transfer', 'direct_debit') NOT NULL");
    }

    public function down(): void
    {
        // Remove 'direct_debit' from payment_method ENUM
        $this->db->exec("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('card', 'multibanco', 'mbway', 'sepa', 'transfer') NOT NULL");
    }
}
