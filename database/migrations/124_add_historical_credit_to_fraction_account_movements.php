<?php

/**
 * Add source_type 'historical_credit' to fraction_account_movements
 * so that credits from previous years can be registered and applied to quota liquidation.
 */
class AddHistoricalCreditToFractionAccountMovements
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("ALTER TABLE fraction_account_movements MODIFY COLUMN source_type ENUM('quota_payment', 'space_reservation', 'other', 'quota_application', 'historical_credit') NOT NULL");
    }

    public function down(): void
    {
        // Remove historical_credit movements would be needed before reverting; for safety we only allow up
        $this->db->exec("ALTER TABLE fraction_account_movements MODIFY COLUMN source_type ENUM('quota_payment', 'space_reservation', 'other', 'quota_application') NOT NULL");
    }
}
