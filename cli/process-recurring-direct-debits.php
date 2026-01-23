<?php
/**
 * Process Recurring Direct Debits CLI
 * 
 * Creates monthly direct debit payments for active subscriptions with direct debit payment method.
 * 
 * Usage: php cli/process-recurring-direct-debits.php [--days-before=X] [--dry-run]
 * 
 * Options:
 *   --days-before=X    Days before expiration to create payment (default: 3)
 *   --dry-run          Show what would be created without actually creating payments
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config.php';
require __DIR__ . '/../database.php';

use App\Services\PaymentService;
use App\Services\SubscriptionService;
use App\Services\PricingService;
use App\Models\Payment;

// Set timezone
date_default_timezone_set('Europe/Lisbon');

// Parse command line arguments
$daysBefore = 3;
$dryRun = false;

foreach ($argv as $arg) {
    if (strpos($arg, '--days-before=') === 0) {
        $daysBefore = (int)substr($arg, strlen('--days-before='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}

echo "========================================\n";
echo "Process Recurring Direct Debits\n";
echo "========================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no payments will be created)" : "LIVE") . "\n";
echo "Days before expiration: {$daysBefore}\n";
echo "Started at: " . date('Y-m-d H:i:s') . "\n";
echo "========================================\n\n";

try {
    global $db;
    
    if (!$db) {
        throw new \Exception("Database connection not available");
    }
    
    $paymentService = new PaymentService();
    $subscriptionService = new SubscriptionService();
    $pricingService = new PricingService();
    $paymentModel = new Payment();
    
    // Get subscriptions that need recurring payment
    // - Active subscriptions
    // - Payment method is direct_debit or sepa
    // - Expiring within X days
    // - Last successful payment was more than 25 days ago (avoid duplicates)
    $startDate = date('Y-m-d H:i:s');
    $endDate = date('Y-m-d H:i:s', strtotime("+{$daysBefore} days"));
    $minDaysSinceLastPayment = 25;
    $lastPaymentCutoff = date('Y-m-d H:i:s', strtotime("-{$minDaysSinceLastPayment} days"));
    
    $stmt = $db->prepare("
        SELECT s.*, p.name as plan_name, p.slug as plan_slug, u.name as user_name, u.email as user_email
        FROM subscriptions s
        INNER JOIN plans p ON s.plan_id = p.id
        INNER JOIN users u ON s.user_id = u.id
        WHERE s.status = 'active'
        AND (s.payment_method = 'direct_debit' OR s.payment_method = 'sepa')
        AND s.current_period_end >= :start_date
        AND s.current_period_end <= :end_date
        AND (
            -- No successful payment in last 25 days
            NOT EXISTS (
                SELECT 1 FROM payments pay
                WHERE pay.subscription_id = s.id
                AND pay.status = 'completed'
                AND pay.processed_at >= :last_payment_cutoff
            )
            OR
            -- No payment exists at all
            NOT EXISTS (
                SELECT 1 FROM payments pay
                WHERE pay.subscription_id = s.id
            )
        )
        ORDER BY s.current_period_end ASC
    ");
    
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':last_payment_cutoff' => $lastPaymentCutoff
    ]);
    
    $subscriptions = $stmt->fetchAll() ?: [];
    
    if (empty($subscriptions)) {
        echo "No subscriptions found that need recurring direct debit payment.\n";
        exit(0);
    }
    
    echo "Found " . count($subscriptions) . " subscription(s) that need recurring payment.\n\n";
    
    $totalCreated = 0;
    $totalSkipped = 0;
    $errors = [];
    
    foreach ($subscriptions as $subscription) {
        $subscriptionId = $subscription['id'];
        $userName = $subscription['user_name'];
        $userEmail = $subscription['user_email'];
        $planName = $subscription['plan_name'];
        $expirationDate = date('d/m/Y', strtotime($subscription['current_period_end']));
        $daysLeft = ceil((strtotime($subscription['current_period_end']) - time()) / 86400);
        
        echo "Processing: {$userName} ({$userEmail})\n";
        echo "  Plan: {$planName}\n";
        echo "  Expires: {$expirationDate} ({$daysLeft} day(s))\n";
        echo "  Payment method: {$subscription['payment_method']}\n";
        
        // Get bank data from subscription metadata
        $metadata = null;
        if (isset($subscription['metadata']) && $subscription['metadata']) {
            $metadata = is_string($subscription['metadata']) ? json_decode($subscription['metadata'], true) : $subscription['metadata'];
        }
        
        if (!$metadata || empty($metadata['bank_account']) || empty($metadata['bank_iban'])) {
            echo "  ⚠ Skipped: No bank account information found\n";
            $totalSkipped++;
            continue;
        }
        
        // Calculate monthly amount (base + extras)
        $licenseLimit = $subscription['license_limit'] ?? $subscription['plan']['license_min'] ?? 0;
        $plan = ['id' => $subscription['plan_id'], 'pricing_mode' => $subscription['plan']['pricing_mode'] ?? 'flat'];
        $pricingBreakdown = $pricingService->getPriceBreakdown(
            $subscription['plan_id'],
            $licenseLimit,
            $plan['pricing_mode'] ?? 'flat'
        );
        $monthlyAmount = $pricingBreakdown['total'];
        
        // Add extra condominiums if Business plan
        if ($subscription['plan_slug'] === 'business' && isset($subscription['extra_condominiums']) && $subscription['extra_condominiums'] > 0) {
            $extraCondominiumsPricingModel = new \App\Models\PlanExtraCondominiumsPricing();
            $pricePerCondominium = $extraCondominiumsPricingModel->getPriceForCondominiums(
                $subscription['plan_id'],
                $subscription['extra_condominiums']
            );
            if ($pricePerCondominium !== null) {
                $monthlyAmount += $pricePerCondominium * $subscription['extra_condominiums'];
            }
        }
        
        echo "  Monthly amount: €" . number_format($monthlyAmount, 2, ',', '.') . "\n";
        
        if ($dryRun) {
            echo "  [DRY RUN] Would create direct debit payment\n";
            $totalCreated++;
        } else {
            try {
                // Check if invoice exists, create if not
                $invoiceModel = new \App\Models\Invoice();
                $invoice = $invoiceModel->getPendingBySubscriptionId($subscriptionId);
                
                if (!$invoice) {
                    // Create invoice for monthly payment
                    $invoiceService = new \App\Services\InvoiceService();
                    $invoiceId = $invoiceService->createInvoice($subscriptionId, $monthlyAmount, [
                        'is_recurring_payment' => true,
                        'payment_method' => $subscription['payment_method']
                    ]);
                    $invoice = $invoiceModel->findById($invoiceId);
                } else {
                    $invoiceId = $invoice['id'];
                }
                
                // Prepare bank data
                $bankData = [
                    'iban' => $metadata['bank_iban'],
                    'account_holder' => $metadata['bank_account']['account_holder'] ?? $userName,
                    'mandate_reference' => $metadata['mandate_reference'] ?? null
                ];
                
                // Generate direct debit payment
                $result = $paymentService->generateDirectDebitPayment(
                    $monthlyAmount,
                    $bankData,
                    $subscriptionId,
                    $invoiceId
                );
                
                if ($result && isset($result['payment_id'])) {
                    echo "  ✓ Direct debit payment created successfully\n";
                    echo "    Payment ID: {$result['payment_id']}\n";
                    if (isset($result['reference'])) {
                        echo "    Reference: {$result['reference']}\n";
                    }
                    $totalCreated++;
                } else {
                    echo "  ✗ Failed to create direct debit payment\n";
                    $errors[] = "Failed to create payment for subscription ID {$subscriptionId}";
                    $totalSkipped++;
                }
            } catch (\Exception $e) {
                $errorMsg = "Error creating payment for subscription ID {$subscriptionId}: " . $e->getMessage();
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
    echo "Payments created: {$totalCreated}\n";
    echo "Payments skipped: {$totalSkipped}\n";
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
        echo "\nThis was a DRY RUN. No payments were actually created.\n";
        echo "Run without --dry-run to create the payments.\n";
    } else {
        echo "\nRecurring direct debit processing completed successfully!\n";
    }
    
    exit(0);
    
} catch (\Exception $e) {
    echo "\nFatal Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
