<?php

class SeedDefaultVoteOptions
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Get all condominiums
        $stmt = $this->db->query("SELECT id FROM condominiums");
        $condominiums = $stmt->fetchAll();
        
        $defaultOptions = [
            ['label' => 'A favor', 'order' => 1, 'is_default' => true],
            ['label' => 'Contra', 'order' => 2, 'is_default' => true],
            ['label' => 'Abstenção', 'order' => 3, 'is_default' => true]
        ];
        
        $insertStmt = $this->db->prepare("
            INSERT INTO vote_options (condominium_id, option_label, order_index, is_default, is_active)
            VALUES (:condominium_id, :option_label, :order_index, :is_default, 1)
        ");
        
        foreach ($condominiums as $condominium) {
            foreach ($defaultOptions as $option) {
                // Check if option already exists
                $checkStmt = $this->db->prepare("
                    SELECT id FROM vote_options 
                    WHERE condominium_id = :condominium_id AND option_label = :option_label
                ");
                $checkStmt->execute([
                    ':condominium_id' => $condominium['id'],
                    ':option_label' => $option['label']
                ]);
                
                if ($checkStmt->rowCount() == 0) {
                    $insertStmt->execute([
                        ':condominium_id' => $condominium['id'],
                        ':option_label' => $option['label'],
                        ':order_index' => $option['order'],
                        ':is_default' => $option['is_default'] ? 1 : 0
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Remove default options
        $this->db->exec("DELETE FROM vote_options WHERE is_default = TRUE");
    }
}
