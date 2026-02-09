<?php

namespace App\Models;

use App\Core\Model;

class AppSettings extends Model
{
    protected $table = 'app_settings';

    /**
     * Get a setting by key
     */
    public function getSetting(string $settingKey): ?string
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1");
        $stmt->execute([':setting_key' => $settingKey]);
        $result = $stmt->fetch();

        return $result ? $result['setting_value'] : null;
    }

    /**
     * Set a setting value
     */
    public function setSetting(string $settingKey, ?string $settingValue, ?string $description = null): bool
    {
        if (!$this->db) {
            return false;
        }

        // Check if setting exists
        $existing = $this->getSetting($settingKey);
        
        if ($existing !== null) {
            // Update existing
            $stmt = $this->db->prepare("
                UPDATE app_settings 
                SET setting_value = :setting_value, 
                    description = :description,
                    updated_at = NOW()
                WHERE setting_key = :setting_key
            ");
            return $stmt->execute([
                ':setting_key' => $settingKey,
                ':setting_value' => $settingValue,
                ':description' => $description
            ]);
        } else {
            // Insert new
            $stmt = $this->db->prepare("
                INSERT INTO app_settings (setting_key, setting_value, description) 
                VALUES (:setting_key, :setting_value, :description)
            ");
            return $stmt->execute([
                ':setting_key' => $settingKey,
                ':setting_value' => $settingValue,
                ':description' => $description
            ]);
        }
    }

    /**
     * Get pioneer trial end date
     */
    public function getPioneerTrialEndDate(): ?string
    {
        $date = $this->getSetting('pioneer_trial_end_date');
        
        // Validate date is not in the past
        if ($date) {
            $dateObj = \DateTime::createFromFormat('Y-m-d', $date);
            if ($dateObj && $dateObj >= new \DateTime('today')) {
                return $date;
            }
        }
        
        return null;
    }

    /**
     * Set pioneer trial end date
     */
    public function setPioneerTrialEndDate(?string $date, ?string $description = null): bool
    {
        return $this->setSetting('pioneer_trial_end_date', $date, $description);
    }

    /**
     * Count pioneers with active trials
     */
    public function countPioneersWithActiveTrials(): int
    {
        if (!$this->db) {
            return 0;
        }

        $stmt = $this->db->query("
            SELECT COUNT(*) as count 
            FROM subscriptions s
            INNER JOIN users u ON s.user_id = u.id
            WHERE u.is_pioneer = 1 
              AND s.status = 'trial'
        ");
        $result = $stmt->fetch();

        return $result ? (int)$result['count'] : 0;
    }
}
