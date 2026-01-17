<?php

class MigrateCondominiumOwnerRoles
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // For each condominium, ensure the owner has an entry in condominium_users with role='admin'
        $stmt = $this->db->query("
            SELECT id, user_id 
            FROM condominiums 
            WHERE user_id IS NOT NULL
        ");
        
        $condominiums = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        foreach ($condominiums as $condominium) {
            $condominiumId = $condominium['id'];
            $userId = $condominium['user_id'];
            
            // Check if entry already exists
            $checkStmt = $this->db->prepare("
                SELECT id FROM condominium_users 
                WHERE condominium_id = :condominium_id 
                AND user_id = :user_id
                AND (ended_at IS NULL OR ended_at > CURDATE())
            ");
            $checkStmt->execute([
                ':condominium_id' => $condominiumId,
                ':user_id' => $userId
            ]);
            
            if (!$checkStmt->fetch()) {
                // Create entry with admin role
                $insertStmt = $this->db->prepare("
                    INSERT INTO condominium_users (
                        condominium_id, 
                        user_id, 
                        role, 
                        can_view_finances, 
                        can_vote, 
                        is_primary, 
                        started_at
                    )
                    VALUES (
                        :condominium_id,
                        :user_id,
                        'admin',
                        TRUE,
                        TRUE,
                        TRUE,
                        CURDATE()
                    )
                ");
                $insertStmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':user_id' => $userId
                ]);
            } else {
                // Update existing entry to ensure role is 'admin'
                $updateStmt = $this->db->prepare("
                    UPDATE condominium_users 
                    SET role = 'admin', 
                        ended_at = NULL
                    WHERE condominium_id = :condominium_id 
                    AND user_id = :user_id
                ");
                $updateStmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':user_id' => $userId
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove admin entries for owners (optional - can be left empty if you don't want to rollback)
        // This migration is mostly additive, so rollback might not be necessary
    }
}
