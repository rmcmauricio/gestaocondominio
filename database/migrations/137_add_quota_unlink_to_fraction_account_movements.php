<?php

/**
 * Add source_type 'quota_unlink' to fraction_account_movements.
 * Used when a quota payment association is removed (valor libertado para movimento) so the amount
 * is not re-credited to the fraction account — it stays freed on the financial movement for reassociation.
 */
class AddQuotaUnlinkToFractionAccountMovements
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("ALTER TABLE fraction_account_movements MODIFY COLUMN source_type ENUM('quota_payment', 'space_reservation', 'other', 'quota_application', 'historical_credit', 'quota_unlink') NOT NULL");
    }

    public function down(): void
    {
        $this->db->exec("ALTER TABLE fraction_account_movements MODIFY COLUMN source_type ENUM('quota_payment', 'space_reservation', 'other', 'quota_application', 'historical_credit') NOT NULL");
    }
}
