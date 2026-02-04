<?php
/**
 * Overdue Fees Notification CLI
 * 
 * Sends email notifications to condominium owners with overdue fees.
 * Can be run via cron job daily.
 * 
 * Usage: php cli/notify-overdue-fees.php [--days-after=X] [--dry-run]
 * 
 * Options:
 *   --days-after=X    Notify only X days after due date (default: 0, notify all overdue)
 *   --dry-run         Show what would be sent without actually sending emails
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\NotificationService;
use App\Core\EmailService;
use App\Models\Fee;
use App\Models\FeePayment;

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
echo "Overdue Fees Notification System\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no emails will be sent)" : "LIVE") . "\n";
echo "Days after due date: " . ($daysAfter > 0 ? "{$daysAfter} (only fees overdue by {$daysAfter}+ days)" : "0 (all overdue fees)") . "\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $notificationService = new NotificationService();
    $feeModel = new Fee();
    $feePaymentModel = new FeePayment();
    
    // Get all overdue fees with user information
    $sql = "
        SELECT DISTINCT
            f.id as fee_id,
            f.condominium_id,
            f.fraction_id,
            f.period_year,
            f.period_month,
            f.amount,
            f.due_date,
            f.reference,
            fr.identifier as fraction_identifier,
            c.name as condominium_name,
            cu.user_id,
            u.email,
            u.name as user_name,
            COALESCE((
                SELECT SUM(fp.amount) 
                FROM fee_payments fp 
                WHERE fp.fee_id = f.id
            ), 0) as paid_amount,
            (f.amount - COALESCE((
                SELECT SUM(fp.amount) 
                FROM fee_payments fp 
                WHERE fp.fee_id = f.id
            ), 0)) as pending_amount
        FROM fees f
        INNER JOIN fractions fr ON fr.id = f.fraction_id
        INNER JOIN condominiums c ON c.id = f.condominium_id
        INNER JOIN condominium_users cu ON cu.fraction_id = f.fraction_id AND cu.condominium_id = f.condominium_id
        INNER JOIN users u ON u.id = cu.user_id
        WHERE f.status = 'pending'
        AND f.due_date < CURDATE()
        AND COALESCE(f.is_historical, 0) = 0
        AND (f.amount - COALESCE((
            SELECT SUM(fp.amount) 
            FROM fee_payments fp 
            WHERE fp.fee_id = f.id
        ), 0)) > 0
        ORDER BY f.condominium_id, cu.user_id, f.due_date ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([':cutoff_date' => $cutoffDate]);
    $overdueFees = $stmt->fetchAll() ?: [];
    
    if (empty($overdueFees)) {
        echo "No overdue fees found.\n";
        exit(0);
    }
    
    echo "Found " . count($overdueFees) . " overdue fee(s).\n\n";
    
    // Group fees by user and condominium
    $userFees = [];
    foreach ($overdueFees as $fee) {
        $key = $fee['user_id'] . '_' . $fee['condominium_id'];
        if (!isset($userFees[$key])) {
            $userFees[$key] = [
                'user_id' => $fee['user_id'],
                'user_name' => $fee['user_name'],
                'user_email' => $fee['email'],
                'condominium_id' => $fee['condominium_id'],
                'condominium_name' => $fee['condominium_name'],
                'fees' => []
            ];
        }
        $userFees[$key]['fees'][] = $fee;
    }
    
    echo "Grouped into " . count($userFees) . " notification(s) to send.\n\n";
    
    $totalSent = 0;
    $totalSkipped = 0;
    $errors = [];
    
    foreach ($userFees as $key => $userFeeData) {
        $userId = $userFeeData['user_id'];
        $userEmail = $userFeeData['user_email'];
        $userName = $userFeeData['user_name'];
        $condominiumId = $userFeeData['condominium_id'];
        $condominiumName = $userFeeData['condominium_name'];
        $fees = $userFeeData['fees'];
        
        $totalAmount = array_sum(array_column($fees, 'pending_amount'));
        $feesCount = count($fees);
        
        echo "Processing: {$userName} ({$userEmail})\n";
        echo "  Condomínio: {$condominiumName}\n";
        echo "  Quotas em atraso: {$feesCount}\n";
        echo "  Valor total: €" . number_format($totalAmount, 2, ',', '.') . "\n";
        
        if (empty($userEmail)) {
            echo "  ⚠ Skipped: No email address\n";
            $totalSkipped++;
            continue;
        }
        
        // Check if notification was already sent today for this user/condominium
        $checkStmt = $db->prepare("
            SELECT id FROM notifications
            WHERE user_id = :user_id
            AND condominium_id = :condominium_id
            AND type = 'fee_overdue'
            AND DATE(created_at) = CURDATE()
        ");
        $checkStmt->execute([
            ':user_id' => $userId,
            ':condominium_id' => $condominiumId
        ]);
        
        if ($checkStmt->fetch()) {
            echo "  ⚠ Skipped: Notification already sent today\n";
            $totalSkipped++;
            continue;
        }
        
        // Check user email preferences
        $prefStmt = $db->prepare("
            SELECT receive_fee_notifications 
            FROM user_email_preferences 
            WHERE user_id = :user_id
        ");
        $prefStmt->execute([':user_id' => $userId]);
        $prefs = $prefStmt->fetch();
        
        $shouldSendEmail = !$prefs || $prefs['receive_fee_notifications'] == 1;
        
        if ($dryRun) {
            echo "  [DRY RUN] Would send notification email";
            if (!$shouldSendEmail) {
                echo " (but user has disabled email notifications)";
            }
            echo "\n";
            $totalSent++;
        } else {
            try {
                // Always create in-app notification
                foreach ($fees as $fee) {
                    $notificationService->notifyFeeOverdue($fee['fee_id'], $userId, $condominiumId);
                }
                
                // Send email if preferences allow
                if ($shouldSendEmail) {
                    $success = $notificationService->sendOverdueFeeEmail($userId, $fees, $condominiumId);
                    
                    if ($success) {
                        echo "  ✓ Email sent successfully\n";
                        $totalSent++;
                    } else {
                        echo "  ⚠ In-app notification created, but email failed\n";
                        $errors[] = "Failed to send email to {$userEmail}";
                        $totalSkipped++;
                    }
                } else {
                    echo "  ✓ In-app notification created (email disabled by user preferences)\n";
                    $totalSent++;
                }
            } catch (\Exception $e) {
                $errorMsg = "Error sending notification to {$userEmail}: " . $e->getMessage();
                echo "  ✗ {$errorMsg}\n";
                $errors[] = $errorMsg;
                $totalSkipped++;
            }
        }
        
        echo "\n";
    }
    
    // Summary
    echo "========================================\n";
    echo "Summary\n";
    echo "========================================\n";
    echo "Notifications sent: {$totalSent}\n";
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
    
    echo "\nNotification process completed successfully!\n";
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
