<?php

class AddPendingStatusToSubscriptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Add 'pending' status to the ENUM
        $sql = "ALTER TABLE subscriptions 
                MODIFY COLUMN status ENUM('trial', 'active', 'suspended', 'canceled', 'expired', 'pending') DEFAULT 'trial'";
        
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Remove 'pending' status from the ENUM
        // Note: This will fail if there are any subscriptions with status 'pending'
        // In that case, you would need to update them first
        $sql = "ALTER TABLE subscriptions 
                MODIFY COLUMN status ENUM('trial', 'active', 'suspended', 'canceled', 'expired') DEFAULT 'trial'";
        
        $this->db->exec($sql);
    }
}
