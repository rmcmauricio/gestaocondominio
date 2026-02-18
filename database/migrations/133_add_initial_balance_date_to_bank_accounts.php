<?php

class AddInitialBalanceDateToBankAccounts
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("
            ALTER TABLE bank_accounts
            ADD COLUMN initial_balance_date DATE NULL AFTER initial_balance
        ");
    }

    public function down(): void
    {
        $this->db->exec("
            ALTER TABLE bank_accounts
            DROP COLUMN initial_balance_date
        ");
    }
}
