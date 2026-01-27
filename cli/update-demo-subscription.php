<?php
/**
 * Update demo user subscription to use demo plan
 * 
 * This script ensures that the demo user (demo@predio.pt) 
 * is using the demo plan instead of any other plan.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';

use App\Models\Subscription;
use App\Models\Plan;

echo "=== Atualizar Subscrição Demo ===\n\n";

try {
    // Find demo user
    $stmt = $db->prepare("SELECT id, email FROM users WHERE email = 'demo@predio.pt' AND is_demo = TRUE LIMIT 1");
    $stmt->execute();
    $demoUser = $stmt->fetch();

    if (!$demoUser) {
        echo "ERRO: Utilizador demo não encontrado.\n";
        exit(1);
    }

    echo "Utilizador demo encontrado: {$demoUser['email']} (ID: {$demoUser['id']})\n\n";

    // Find demo plan
    $planModel = new Plan();
    $demoPlanStmt = $db->prepare("
        SELECT * FROM plans 
        WHERE (slug = 'demo' OR (limit_condominios = 2 AND is_active = FALSE))
        ORDER BY id ASC 
        LIMIT 1
    ");
    $demoPlanStmt->execute();
    $demoPlan = $demoPlanStmt->fetch();

    if (!$demoPlan) {
        // Try condominio plan as fallback
        $condominioPlanStmt = $db->prepare("
            SELECT * FROM plans 
            WHERE plan_type = 'condominio' 
            ORDER BY id ASC 
            LIMIT 1
        ");
        $condominioPlanStmt->execute();
        $demoPlan = $condominioPlanStmt->fetch();
    }

    if (!$demoPlan) {
        echo "ERRO: Plano demo não encontrado. Execute o DatabaseSeeder primeiro.\n";
        exit(1);
    }

    echo "Plano demo encontrado: {$demoPlan['name']} (ID: {$demoPlan['id']}, Limite: {$demoPlan['limit_condominios']})\n\n";

    // Find existing subscription
    $subscriptionModel = new Subscription();
    $existingSubscription = $subscriptionModel->getActiveSubscription($demoUser['id']);

    if ($existingSubscription) {
        if ($existingSubscription['plan_id'] == $demoPlan['id']) {
            echo "✓ Subscrição já está usando o plano demo correto.\n";
            echo "  Subscrição ID: {$existingSubscription['id']}\n";
            echo "  Plano: {$demoPlan['name']}\n";
            echo "  Status: {$existingSubscription['status']}\n";
        } else {
            // Get current plan name
            $currentPlanStmt = $db->prepare("SELECT name FROM plans WHERE id = :id LIMIT 1");
            $currentPlanStmt->execute([':id' => $existingSubscription['plan_id']]);
            $currentPlan = $currentPlanStmt->fetch();
            $currentPlanName = $currentPlan ? $currentPlan['name'] : 'Desconhecido';

            echo "Subscrição encontrada mas usando plano incorreto:\n";
            echo "  Subscrição ID: {$existingSubscription['id']}\n";
            echo "  Plano atual: {$currentPlanName} (ID: {$existingSubscription['plan_id']})\n";
            echo "  Plano correto: {$demoPlan['name']} (ID: {$demoPlan['id']})\n\n";

            // Update subscription to use demo plan
            $now = date('Y-m-d H:i:s');
            $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));
            
            $subscriptionModel->update($existingSubscription['id'], [
                'plan_id' => $demoPlan['id'],
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'payment_method' => 'demo'
            ]);

            echo "✓ Subscrição atualizada para usar o plano demo.\n";
            echo "  Novo plano: {$demoPlan['name']}\n";
            echo "  Status: active\n";
            echo "  Período: {$now} até {$periodEnd}\n";
        }
    } else {
        // Check for any subscription (including inactive)
        $allSubscriptionsStmt = $db->prepare("
            SELECT * FROM subscriptions 
            WHERE user_id = :user_id 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $allSubscriptionsStmt->execute([':user_id' => $demoUser['id']]);
        $anySubscription = $allSubscriptionsStmt->fetch();

        if ($anySubscription) {
            echo "Subscrição encontrada mas não está ativa:\n";
            echo "  Subscrição ID: {$anySubscription['id']}\n";
            echo "  Status: {$anySubscription['status']}\n\n";

            // Update to active with demo plan
            $now = date('Y-m-d H:i:s');
            $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));
            
            $subscriptionModel->update($anySubscription['id'], [
                'plan_id' => $demoPlan['id'],
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'payment_method' => 'demo'
            ]);

            echo "✓ Subscrição atualizada para ativa com plano demo.\n";
        } else {
            // Create new subscription
            $now = date('Y-m-d H:i:s');
            $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));

            $subscriptionId = $subscriptionModel->create([
                'user_id' => $demoUser['id'],
                'plan_id' => $demoPlan['id'],
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'payment_method' => 'demo'
            ]);

            echo "✓ Nova subscrição criada com plano demo.\n";
            echo "  Subscrição ID: {$subscriptionId}\n";
            echo "  Plano: {$demoPlan['name']}\n";
            echo "  Status: active\n";
            echo "  Período: {$now} até {$periodEnd}\n";
        }
    }

    echo "\n=== Concluído com sucesso ===\n";

} catch (\Exception $e) {
    echo "\nERRO: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
