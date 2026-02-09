<?php

class MarkExistingAnnualFeesGenerated
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Check if column exists
        $stmt = $this->db->query("SHOW COLUMNS FROM budgets LIKE 'annual_fees_generated'");
        if ($stmt->rowCount() == 0) {
            throw new \Exception("Column 'annual_fees_generated' does not exist. Please run migration 120_add_annual_fees_generated_to_budgets first.");
        }

        // Get all budgets
        $budgets = $this->db->query("SELECT id, condominium_id, year FROM budgets")->fetchAll(\PDO::FETCH_ASSOC);
        
        $updated = 0;
        
        foreach ($budgets as $budget) {
            $budgetId = $budget['id'];
            $condominiumId = $budget['condominium_id'];
            $year = $budget['year'];
            
            // Check if there are fees for all 12 months of this year
            // We consider annual fees generated if there are fees for at least 10 out of 12 months
            // (allowing for some flexibility in case some months weren't generated)
            // Only check regular fees (fee_type = 'regular'), not extra fees
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT period_month) as month_count
                FROM fees
                WHERE condominium_id = :condominium_id
                AND period_year = :year
                AND period_month BETWEEN 1 AND 12
                AND (fee_type = 'regular' OR fee_type IS NULL)
            ");
            
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':year' => $year
            ]);
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            $monthCount = (int)($result['month_count'] ?? 0);
            
            // If we have fees for 10 or more months, consider annual fees as generated
            // Also check if there are fees for all 12 months (more strict check)
            if ($monthCount >= 10) {
                // Double-check: verify if we have fees for all 12 months
                $stmtAllMonths = $this->db->prepare("
                    SELECT COUNT(DISTINCT period_month) as month_count
                    FROM fees
                    WHERE condominium_id = :condominium_id
                    AND period_year = :year
                    AND period_month BETWEEN 1 AND 12
                    AND (fee_type = 'regular' OR fee_type IS NULL)
                    HAVING COUNT(DISTINCT period_month) = 12
                ");
                
                $stmtAllMonths->execute([
                    ':condominium_id' => $condominiumId,
                    ':year' => $year
                ]);
                
                $allMonthsResult = $stmtAllMonths->fetch(\PDO::FETCH_ASSOC);
                
                // If we have exactly 12 months, mark as generated
                if ($allMonthsResult && (int)$allMonthsResult['month_count'] === 12) {
                    $updateStmt = $this->db->prepare("
                        UPDATE budgets 
                        SET annual_fees_generated = TRUE 
                        WHERE id = :budget_id
                        AND annual_fees_generated = FALSE
                    ");
                    
                    $updateStmt->execute([':budget_id' => $budgetId]);
                    
                    if ($updateStmt->rowCount() > 0) {
                        $updated++;
                        echo "Marked budget ID {$budgetId} (year {$year}) as having annual fees generated.\n";
                    }
                } else if ($monthCount >= 10) {
                    // If we have 10-11 months, still mark as generated (might be missing some months)
                    // but log a warning
                    $updateStmt = $this->db->prepare("
                        UPDATE budgets 
                        SET annual_fees_generated = TRUE 
                        WHERE id = :budget_id
                        AND annual_fees_generated = FALSE
                    ");
                    
                    $updateStmt->execute([':budget_id' => $budgetId]);
                    
                    if ($updateStmt->rowCount() > 0) {
                        $updated++;
                        echo "Marked budget ID {$budgetId} (year {$year}) as having annual fees generated ({$monthCount} months found).\n";
                    }
                }
            }
        }
        
        echo "Migration completed. Updated {$updated} budget(s).\n";
    }

    public function down(): void
    {
        // Reset all annual_fees_generated flags to FALSE
        // Note: This will reset ALL flags, including ones that were set by the system
        $this->db->exec("UPDATE budgets SET annual_fees_generated = FALSE");
        echo "Reset all annual_fees_generated flags to FALSE.\n";
    }
}
