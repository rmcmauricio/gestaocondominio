<?php

namespace App\Models;

use App\Core\Model;

class CondominiumSetupWizardProgress extends Model
{
    protected $table = 'condominium_setup_wizard_progress';

    public const STEP_BANK_ACCOUNTS = 1;
    public const STEP_FRACTIONS = 2;
    public const STEP_ENTRY_DEBTS = 3;
    public const STEP_BUDGETS = 4;
    public const STEP_QUOTAS = 5;
    public const STEP_SUPPLIERS = 6;
    public const STEP_SPACES = 7;
    public const STEP_DONE = 8;

    public const MAX_STEP = 8;

    /**
     * Get progress for condominium (or null if none / table not yet migrated)
     */
    public function getByCondominium(int $condominiumId): ?array
    {
        if (!$this->db) {
            return null;
        }
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM condominium_setup_wizard_progress
                WHERE condominium_id = :condominium_id
                LIMIT 1
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get current step for condominium (default 1). Uses session when table not yet migrated.
     */
    public function getCurrentStep(int $condominiumId): int
    {
        $row = $this->getByCondominium($condominiumId);
        if ($row) {
            $step = (int)($row['current_step'] ?? 1);
            return max(1, min($step, self::MAX_STEP));
        }
        $key = 'wizard_step_' . $condominiumId;
        if (isset($_SESSION[$key])) {
            $step = (int)$_SESSION[$key];
            return max(1, min($step, self::MAX_STEP));
        }
        return self::STEP_BANK_ACCOUNTS;
    }

    /**
     * Initialize or update progress and advance to next step
     */
    public function advanceToStep(int $condominiumId, int $nextStep, array $completed = [], array $skipped = []): void
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }
        try {
            $row = $this->getByCondominium($condominiumId);
            $completedJson = json_encode($completed);
            $skippedJson = json_encode($skipped);
            if ($row) {
                $stmt = $this->db->prepare("
                    UPDATE condominium_setup_wizard_progress
                    SET current_step = :current_step,
                        completed_steps = :completed_steps,
                        skipped_steps = :skipped_steps,
                        updated_at = NOW()
                    WHERE condominium_id = :condominium_id
                ");
                $stmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':current_step' => $nextStep,
                    ':completed_steps' => $completedJson,
                    ':skipped_steps' => $skippedJson
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO condominium_setup_wizard_progress
                    (condominium_id, current_step, completed_steps, skipped_steps)
                    VALUES (:condominium_id, :current_step, :completed_steps, :skipped_steps)
                ");
                $stmt->execute([
                    ':condominium_id' => $condominiumId,
                    ':current_step' => $nextStep,
                    ':completed_steps' => $completedJson,
                    ':skipped_steps' => $skippedJson
                ]);
            }
        } catch (\Throwable $e) {
            // Table may not exist yet (migration 135 not run); use session
            $_SESSION['wizard_step_' . $condominiumId] = $nextStep;
        }
    }

    /**
     * Mark wizard as done (step 8)
     */
    public function markDone(int $condominiumId): void
    {
        $this->advanceToStep($condominiumId, self::STEP_DONE, [], []);
    }

    /**
     * Check if wizard is complete
     */
    public function isComplete(int $condominiumId): bool
    {
        return $this->getCurrentStep($condominiumId) >= self::STEP_DONE;
    }
}
