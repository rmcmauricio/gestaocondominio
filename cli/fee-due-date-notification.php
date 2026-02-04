<?php
/**
 * Fee Due Date Notification CLI
 * 
 * Creates notifications when fees pass their due date.
 * 
 * Usage: php cli/fee-due-date-notification.php [--days-after=X] [--dry-run]
 * 
 * Options:
 *   --days-after=X    Notify X days after due date (default: 0, only on due date)
 *   --dry-run         Show what would be notified without actually creating notifications
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\NotificationService;
use App\Core\EmailService;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$daysAfter = 0;
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--days-after=') === 0) {
        $daysAfter = (int)substr($arg, strlen('--days-after='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

echo "========================================\n";
echo "Fee Due Date Notification System\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no notifications will be created)" : "LIVE") . "\n";
echo "Days after due date: {$daysAfter}\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $notificationService = new NotificationService();
    $emailService = new EmailService();
    
    // Calculate cutoff date
    $cutoffDate = date('Y-m-d', strtotime("-{$daysAfter} days"));
    
    // Get fees that are due or past due date
    // - status = 'pending'
    // - due_date <= cutoff date (today - daysAfter)
    // - is_historical = 0
    // - remaining amount > 0 (calculated from amount - sum of payments)
    $stmt = $db->prepare("
        SELECT 
            f.id,
            f.fraction_id,
            f.condominium_id,
            f.period_year,
            f.period_month,
            f.fee_type,
            f.amount,
            f.due_date,
            f.reference,
            c.name as condominium_name,
            fr.number as fraction_number,
            fr.floor as fraction_floor,
            fr.door as fraction_door,
            -- Calculate remaining amount
            COALESCE(f.amount - COALESCE(SUM(fp.amount), 0), f.amount) as remaining_amount
        FROM fees f
        INNER JOIN fractions fr ON f.fraction_id = fr.id
        INNER JOIN condominiums c ON f.condominium_id = c.id
        LEFT JOIN fee_payments fp ON fp.fee_id = f.id AND fp.status = 'completed'
        WHERE f.status = 'pending'
        AND f.due_date <= :cutoff_date
        AND f.is_historical = 0
        GROUP BY f.id
        HAVING remaining_amount > 0
        ORDER BY f.due_date ASC, f.condominium_id ASC, fr.number ASC
    ");
    
    $stmt->execute([':cutoff_date' => $cutoffDate]);
    $fees = $stmt->fetchAll() ?: [];
    
    if (empty($fees)) {
        echo "No fees found that need notification.\n";
        exit(0);
    }
    
    echo "Found " . count($fees) . " fee(s) that need notification.\n\n";
    
    $totalNotified = 0;
    $totalSkipped = 0;
    $errors = [];
    
    foreach ($fees as $fee) {
        $feeId = $fee['id'];
        $condominiumId = $fee['condominium_id'];
        $fractionId = $fee['fraction_id'];
        $dueDate = date('d/m/Y', strtotime($fee['due_date']));
        $daysPastDue = ceil((time() - strtotime($fee['due_date'])) / 86400);
        $remainingAmount = (float)$fee['remaining_amount'];
        
        echo "Processing Fee ID: {$feeId}\n";
        echo "  Condominium: {$fee['condominium_name']}\n";
        echo "  Fraction: {$fee['fraction_number']}" . 
             ($fee['fraction_floor'] ? " ({$fee['fraction_floor']}º)" : '') . 
             ($fee['fraction_door'] ? " - {$fee['fraction_door']}" : '') . "\n";
        echo "  Period: {$fee['period_month']}/{$fee['period_year']}\n";
        echo "  Due date: {$dueDate} ({$daysPastDue} day(s) past due)\n";
        echo "  Remaining amount: €" . number_format($remainingAmount, 2, ',', '.') . "\n";
        
        // Get users associated with this fraction
        $userStmt = $db->prepare("
            SELECT DISTINCT u.id, u.name, u.email, u.role
            FROM users u
            INNER JOIN condominium_users cu ON u.id = cu.user_id
            INNER JOIN fraction_associations fa ON cu.id = fa.condominium_user_id
            WHERE fa.fraction_id = :fraction_id
            AND cu.condominium_id = :condominium_id
            AND u.role IN ('admin', 'owner')
        ");
        
        $userStmt->execute([
            ':fraction_id' => $fractionId,
            ':condominium_id' => $condominiumId
        ]);
        
        $users = $userStmt->fetchAll() ?: [];
        
        if (empty($users)) {
            echo "  ⚠ Skipped: No users found for this fraction\n";
            $totalSkipped++;
            continue;
        }
        
        // Check if notification was already sent today for this fee
        $checkStmt = $db->prepare("
            SELECT id FROM notifications
            WHERE type = 'fee_due_date'
            AND DATE(created_at) = CURDATE()
            AND link LIKE :link_pattern
        ");
        $checkStmt->execute([
            ':link_pattern' => '%fees%' . $feeId . '%'
        ]);
        
        if ($checkStmt->fetch()) {
            echo "  ⚠ Skipped: Notification already sent today\n";
            $totalSkipped++;
            continue;
        }
        
        // Create notification for each user
        foreach ($users as $user) {
            $userId = $user['id'];
            $userName = $user['name'];
            $userEmail = $user['email'];
            
            $title = "Quota em Atraso";
            $message = "A quota referente a {$fee['period_month']}/{$fee['period_year']} ";
            $message .= "da fração {$fee['fraction_number']} ";
            $message .= "do condomínio {$fee['condominium_name']} ";
            $message .= "passou do prazo de pagamento ({$dueDate}). ";
            $message .= "Valor pendente: €" . number_format($remainingAmount, 2, ',', '.');
            
            $link = BASE_URL . "condominiums/{$condominiumId}/fees";
            
            if ($dryRun) {
                echo "  [DRY RUN] Would create notification for: {$userName} ({$userEmail})\n";
            } else {
                try {
                    // Create notification
                    $notificationService->createNotification(
                        $userId,
                        $condominiumId,
                        'fee_due_date',
                        $title,
                        $message,
                        $link
                    );
                    
                    // Send email if user preferences allow
                    if (!empty($userEmail)) {
                        // Check user email preferences
                        $prefStmt = $db->prepare("
                            SELECT receive_fee_notifications 
                            FROM user_email_preferences 
                            WHERE user_id = :user_id
                        ");
                        $prefStmt->execute([':user_id' => $userId]);
                        $prefs = $prefStmt->fetch();
                        
                        $shouldSendEmail = !$prefs || $prefs['receive_fee_notifications'] == 1;
                        
                        if ($shouldSendEmail) {
                            $htmlMessage = "
                                <p>A quota referente a <strong>{$fee['period_month']}/{$fee['period_year']}</strong> ";
                            $htmlMessage .= "da fração <strong>{$fee['fraction_number']}</strong> ";
                            $htmlMessage .= "do condomínio <strong>{$fee['condominium_name']}</strong> ";
                            $htmlMessage .= "passou do prazo de pagamento (<strong>{$dueDate}</strong>).</p>";
                            $htmlMessage .= "<p><strong>Valor pendente:</strong> €" . number_format($remainingAmount, 2, ',', '.') . "</p>";
                            $htmlMessage .= "<p><a href=\"{$link}\" style=\"display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px;\">Ver Quotas</a></p>";
                            
                            $emailService->sendEmail(
                                $userEmail,
                                $title,
                                $htmlMessage,
                                $message,
                                'notification',
                                $userId
                            );
                        }
                    }
                    
                    echo "  ✓ Notification created for: {$userName}\n";
                    $totalNotified++;
                } catch (\Exception $e) {
                    $errorMsg = "Error creating notification for user ID {$userId}: " . $e->getMessage();
                    echo "  ✗ {$errorMsg}\n";
                    $errors[] = $errorMsg;
                    $totalSkipped++;
                }
            }
        }
        
        echo "\n";
    }
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Notifications created: {$totalNotified}\n";
    echo "Notifications skipped: {$totalSkipped}\n";
    echo "Errors: " . count($errors) . "\n";
    echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n";
    
    if (!empty($errors)) {
        echo "\nErrors encountered:\n";
        foreach ($errors as $error) {
            echo "  - {$error}\n";
        }
        exit(1);
    }
    
    if ($dryRun) {
        echo "\nThis was a DRY RUN. No notifications were actually created.\n";
        echo "Run without --dry-run to create the notifications.\n";
    } else {
        echo "\nFee due date notification process completed successfully!\n";
    }
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
