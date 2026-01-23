<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Plan;
use App\Models\Condominium;
use App\Models\User;
use App\Core\EmailService;

/**
 * Service for monitoring license usage and sending alerts
 */
class LicenseMonitoringService
{
    protected $subscriptionModel;
    protected $planModel;
    protected $condominiumModel;
    protected $userModel;
    protected $emailService;

    public function __construct()
    {
        $this->subscriptionModel = new Subscription();
        $this->planModel = new Plan();
        $this->condominiumModel = new Condominium();
        $this->userModel = new User();
        $this->emailService = new EmailService();
    }

    /**
     * Check subscriptions approaching license limit
     * 
     * @param float $threshold Percentage threshold (default: 0.8 = 80%)
     * @return array List of subscriptions approaching limit
     */
    public function checkSubscriptionsNearLimit(float $threshold = 0.8): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $subscriptions = [];
        
        // Get all active subscriptions with license limits
        $stmt = $db->query("
            SELECT 
                s.id,
                s.user_id,
                s.plan_id,
                s.used_licenses,
                s.license_limit,
                s.allow_overage,
                p.name as plan_name,
                p.plan_type,
                u.email,
                u.name as user_name
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            INNER JOIN users u ON s.user_id = u.id
            WHERE s.status IN ('trial', 'active')
            AND s.license_limit IS NOT NULL
            AND s.license_limit > 0
            AND s.used_licenses > 0
            ORDER BY s.id
        ");
        
        $results = $stmt->fetchAll() ?: [];
        
        foreach ($results as $sub) {
            $usagePercentage = (float)$sub['used_licenses'] / (float)$sub['license_limit'];
            
            if ($usagePercentage >= $threshold) {
                $subscriptions[] = [
                    'subscription_id' => (int)$sub['id'],
                    'user_id' => (int)$sub['user_id'],
                    'user_email' => $sub['email'],
                    'user_name' => $sub['user_name'],
                    'plan_name' => $sub['plan_name'],
                    'used_licenses' => (int)$sub['used_licenses'],
                    'license_limit' => (int)$sub['license_limit'],
                    'usage_percentage' => round($usagePercentage * 100, 2),
                    'remaining_licenses' => (int)$sub['license_limit'] - (int)$sub['used_licenses'],
                    'allow_overage' => (bool)$sub['allow_overage']
                ];
            }
        }
        
        return $subscriptions;
    }

    /**
     * Send alerts for subscriptions near limit
     * 
     * @param float $threshold Percentage threshold (default: 0.8 = 80%)
     * @return int Number of alerts sent
     */
    public function sendLimitAlerts(float $threshold = 0.8): int
    {
        $subscriptions = $this->checkSubscriptionsNearLimit($threshold);
        $sentCount = 0;
        
        foreach ($subscriptions as $sub) {
            // Check user email preferences
            $user = $this->userModel->findById($sub['user_id']);
            if (!$user || !($user['email_notifications'] ?? true)) {
                continue; // Skip if user disabled email notifications
            }
            
            $subject = "Aviso: Subscrição próxima do limite de licenças";
            $message = $this->buildLimitAlertEmail($sub);
            
            try {
                $this->emailService->sendEmail(
                    $sub['user_email'],
                    $subject,
                    $message,
                    strip_tags($message)
                );
                $sentCount++;
            } catch (\Exception $e) {
                // Log error but continue with other subscriptions
                error_log("Failed to send license limit alert to {$sub['user_email']}: " . $e->getMessage());
            }
        }
        
        return $sentCount;
    }

    /**
     * Build email message for limit alert
     */
    protected function buildLimitAlertEmail(array $subscription): string
    {
        $percentage = $subscription['usage_percentage'];
        $remaining = $subscription['remaining_licenses'];
        $planName = $subscription['plan_name'];
        
        $message = "<h2>Aviso de Limite de Licenças</h2>";
        $message .= "<p>Olá {$subscription['user_name']},</p>";
        $message .= "<p>A sua subscrição do plano <strong>{$planName}</strong> está a utilizar <strong>{$percentage}%</strong> do limite de licenças.</p>";
        $message .= "<ul>";
        $message .= "<li><strong>Licenças utilizadas:</strong> {$subscription['used_licenses']}</li>";
        $message .= "<li><strong>Limite de licenças:</strong> {$subscription['license_limit']}</li>";
        $message .= "<li><strong>Licenças restantes:</strong> {$remaining}</li>";
        $message .= "</ul>";
        
        if ($subscription['allow_overage']) {
            $message .= "<p><em>Nota: O seu plano permite exceder o limite de licenças.</em></p>";
        } else {
            $message .= "<p><strong>Atenção:</strong> Quando atingir o limite, não será possível adicionar mais frações ou condomínios.</p>";
            $message .= "<p>Considere fazer upgrade do seu plano ou adicionar licenças extras.</p>";
        }
        
        $message .= "<p><a href=\"" . BASE_URL . "subscription\">Gerir Subscrição</a></p>";
        $message .= "<p>Obrigado,<br>Equipa MeuPrédio</p>";
        
        return $message;
    }

    /**
     * Generate weekly license usage report
     * 
     * @return array Report data
     */
    public function generateWeeklyReport(): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $report = [
            'generated_at' => date('Y-m-d H:i:s'),
            'period_start' => date('Y-m-d', strtotime('-7 days')),
            'period_end' => date('Y-m-d'),
            'total_subscriptions' => 0,
            'active_subscriptions' => 0,
            'subscriptions_near_limit' => 0,
            'total_licenses_used' => 0,
            'total_licenses_limit' => 0,
            'locked_condominiums' => 0,
            'by_plan_type' => []
        ];

        // Count subscriptions
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status IN ('trial', 'active') THEN 1 ELSE 0 END) as active
            FROM subscriptions
        ");
        $counts = $stmt->fetch();
        $report['total_subscriptions'] = (int)($counts['total'] ?? 0);
        $report['active_subscriptions'] = (int)($counts['active'] ?? 0);

        // Count subscriptions near limit
        $nearLimit = $this->checkSubscriptionsNearLimit(0.8);
        $report['subscriptions_near_limit'] = count($nearLimit);

        // Sum licenses
        $stmt = $db->query("
            SELECT 
                SUM(used_licenses) as total_used,
                SUM(license_limit) as total_limit
            FROM subscriptions
            WHERE status IN ('trial', 'active')
            AND license_limit IS NOT NULL
        ");
        $licenses = $stmt->fetch();
        $report['total_licenses_used'] = (int)($licenses['total_used'] ?? 0);
        $report['total_licenses_limit'] = (int)($licenses['total_limit'] ?? 0);

        // Count locked condominiums
        $stmt = $db->query("
            SELECT COUNT(*) as count
            FROM condominiums
            WHERE subscription_status = 'locked'
        ");
        $locked = $stmt->fetch();
        $report['locked_condominiums'] = (int)($locked['count'] ?? 0);

        // Breakdown by plan type
        $stmt = $db->query("
            SELECT 
                p.plan_type,
                COUNT(s.id) as count,
                SUM(s.used_licenses) as total_licenses
            FROM subscriptions s
            INNER JOIN plans p ON s.plan_id = p.id
            WHERE s.status IN ('trial', 'active')
            AND p.plan_type IS NOT NULL
            GROUP BY p.plan_type
        ");
        $byPlanType = $stmt->fetchAll() ?: [];
        
        foreach ($byPlanType as $row) {
            $report['by_plan_type'][$row['plan_type']] = [
                'count' => (int)$row['count'],
                'total_licenses' => (int)$row['total_licenses']
            ];
        }

        return $report;
    }

    /**
     * Check for condominiums locked for too long
     * 
     * @param int $days Number of days to consider "too long" (default: 30)
     * @return array List of condominiums locked for extended period
     */
    public function checkLongLockedCondominiums(int $days = 30): array
    {
        global $db;
        
        if (!$db) {
            return [];
        }

        $stmt = $db->prepare("
            SELECT 
                c.id,
                c.name,
                c.subscription_status,
                c.locked_at,
                c.locked_reason,
                c.user_id,
                u.email,
                u.name as user_name,
                DATEDIFF(NOW(), c.locked_at) as days_locked
            FROM condominiums c
            INNER JOIN users u ON c.user_id = u.id
            WHERE c.subscription_status = 'locked'
            AND c.locked_at IS NOT NULL
            AND DATEDIFF(NOW(), c.locked_at) >= :days
            ORDER BY c.locked_at ASC
        ");
        
        $stmt->execute([':days' => $days]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Send alerts for long-locked condominiums
     * 
     * @param int $days Number of days to consider "too long" (default: 30)
     * @return int Number of alerts sent
     */
    public function sendLongLockedAlerts(int $days = 30): int
    {
        $condominiums = $this->checkLongLockedCondominiums($days);
        $sentCount = 0;
        
        foreach ($condominiums as $condo) {
            // Check user email preferences
            $user = $this->userModel->findById($condo['user_id']);
            if (!$user || !($user['email_notifications'] ?? true)) {
                continue;
            }
            
            $subject = "Aviso: Condomínio bloqueado há {$condo['days_locked']} dias";
            $message = $this->buildLongLockedAlertEmail($condo);
            
            try {
                $this->emailService->sendEmail(
                    $condo['email'],
                    $subject,
                    $message,
                    strip_tags($message)
                );
                $sentCount++;
            } catch (\Exception $e) {
                error_log("Failed to send long-locked alert to {$condo['email']}: " . $e->getMessage());
            }
        }
        
        return $sentCount;
    }

    /**
     * Build email message for long-locked condominium alert
     */
    protected function buildLongLockedAlertEmail(array $condominium): string
    {
        $days = $condominium['days_locked'];
        $reason = $condominium['locked_reason'] ?? 'Não especificado';
        
        $message = "<h2>Aviso: Condomínio Bloqueado</h2>";
        $message .= "<p>Olá {$condominium['user_name']},</p>";
        $message .= "<p>O condomínio <strong>{$condominium['name']}</strong> está bloqueado há <strong>{$days} dias</strong>.</p>";
        $message .= "<p><strong>Motivo do bloqueio:</strong> {$reason}</p>";
        $message .= "<p>Para desbloquear o condomínio, é necessário ter uma subscrição ativa.</p>";
        $message .= "<p><a href=\"" . BASE_URL . "subscription\">Ver Subscrições</a></p>";
        $message .= "<p>Obrigado,<br>Equipa MeuPrédio</p>";
        
        return $message;
    }
}
