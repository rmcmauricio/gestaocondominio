<?php
/**
 * Test Script for Account Type Selection and Trial Management
 * 
 * This script helps validate the implementation by checking:
 * - Database schema
 * - Model methods
 * - Service methods
 * - Route registration
 * - View files existence
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

echo "=== Test Account Type Selection and Trial Management ===\n\n";

$errors = [];
$warnings = [];
$success = [];

// 1. Check database schema
echo "1. Checking database schema...\n";
try {
    global $db;
    if (!$db) {
        $errors[] = "Database connection not available";
    } else {
        // Check users table columns
        $stmt = $db->query("DESCRIBE users");
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $requiredColumns = ['google_id', 'auth_provider'];
        foreach ($requiredColumns as $col) {
            if (in_array($col, $columns)) {
                $success[] = "Column '{$col}' exists in users table";
            } else {
                $errors[] = "Column '{$col}' missing in users table";
            }
        }
        
        // Check subscriptions table
        $stmt = $db->query("DESCRIBE subscriptions");
        $subColumns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        $requiredSubColumns = ['trial_ends_at', 'status'];
        foreach ($requiredSubColumns as $col) {
            if (in_array($col, $subColumns)) {
                $success[] = "Column '{$col}' exists in subscriptions table";
            } else {
                $errors[] = "Column '{$col}' missing in subscriptions table";
            }
        }
    }
} catch (\Exception $e) {
    $errors[] = "Database check failed: " . $e->getMessage();
}

// 2. Check Model methods
echo "\n2. Checking User model methods...\n";
try {
    $userModel = new \App\Models\User();
    
    if (method_exists($userModel, 'findByGoogleId')) {
        $success[] = "User::findByGoogleId() method exists";
    } else {
        $errors[] = "User::findByGoogleId() method missing";
    }
    
    if (method_exists($userModel, 'linkGoogleAccount')) {
        $success[] = "User::linkGoogleAccount() method exists";
    } else {
        $errors[] = "User::linkGoogleAccount() method missing";
    }
} catch (\Exception $e) {
    $errors[] = "User model check failed: " . $e->getMessage();
}

// 3. Check SubscriptionService methods
echo "\n3. Checking SubscriptionService methods...\n";
try {
    $subscriptionService = new \App\Services\SubscriptionService();
    
    if (method_exists($subscriptionService, 'isTrialExpired')) {
        $success[] = "SubscriptionService::isTrialExpired() method exists";
    } else {
        $errors[] = "SubscriptionService::isTrialExpired() method missing";
    }
    
    if (method_exists($subscriptionService, 'hasActiveSubscription')) {
        $success[] = "SubscriptionService::hasActiveSubscription() method exists";
    } else {
        $errors[] = "SubscriptionService::hasActiveSubscription() method missing";
    }
} catch (\Exception $e) {
    $errors[] = "SubscriptionService check failed: " . $e->getMessage();
}

// 4. Check SubscriptionMiddleware
echo "\n4. Checking SubscriptionMiddleware...\n";
try {
    if (class_exists('\App\Middleware\SubscriptionMiddleware')) {
        $success[] = "SubscriptionMiddleware class exists";
        
        if (method_exists('\App\Middleware\SubscriptionMiddleware', 'handle')) {
            $success[] = "SubscriptionMiddleware::handle() method exists";
        } else {
            $errors[] = "SubscriptionMiddleware::handle() method missing";
        }
    } else {
        $errors[] = "SubscriptionMiddleware class missing";
    }
} catch (\Exception $e) {
    $errors[] = "SubscriptionMiddleware check failed: " . $e->getMessage();
}

// 5. Check AuthController methods
echo "\n5. Checking AuthController methods...\n";
try {
    $authController = new \App\Controllers\AuthController();
    
    $requiredMethods = [
        'selectAccountType',
        'processAccountType',
        'selectPlanForAdmin',
        'processPlanSelection'
    ];
    
    foreach ($requiredMethods as $method) {
        if (method_exists($authController, $method)) {
            $success[] = "AuthController::{$method}() method exists";
        } else {
            $errors[] = "AuthController::{$method}() method missing";
        }
    }
} catch (\Exception $e) {
    $errors[] = "AuthController check failed: " . $e->getMessage();
}

// 6. Check view files
echo "\n6. Checking view files...\n";
$viewFiles = [
    'app/Views/pages/register.html.twig',
    'app/Views/pages/auth/select-account-type.html.twig',
    'app/Views/pages/auth/select-plan.html.twig'
];

foreach ($viewFiles as $file) {
    $fullPath = __DIR__ . '/../' . $file;
    if (file_exists($fullPath)) {
        $success[] = "View file exists: {$file}";
    } else {
        $errors[] = "View file missing: {$file}";
    }
}

// 7. Check routes
echo "\n7. Checking routes...\n";
$routesFile = __DIR__ . '/../routes.php';
if (file_exists($routesFile)) {
    $routesContent = file_get_contents($routesFile);
    
    $requiredRoutes = [
        '/auth/select-account-type',
        '/auth/select-account-type/process',
        '/auth/select-plan',
        '/auth/select-plan/process'
    ];
    
    foreach ($requiredRoutes as $route) {
        if (strpos($routesContent, $route) !== false) {
            $success[] = "Route registered: {$route}";
        } else {
            $errors[] = "Route not found: {$route}";
        }
    }
} else {
    $errors[] = "Routes file not found";
}

// 8. Check Router middleware integration
echo "\n8. Checking Router middleware integration...\n";
$routerFile = __DIR__ . '/../app/Core/Router.php';
if (file_exists($routerFile)) {
    $routerContent = file_get_contents($routerFile);
    
    if (strpos($routerContent, 'SubscriptionMiddleware') !== false) {
        $success[] = "SubscriptionMiddleware integrated in Router";
    } else {
        $errors[] = "SubscriptionMiddleware not integrated in Router";
    }
} else {
    $errors[] = "Router file not found";
}

// Summary
echo "\n=== Summary ===\n";
echo "Success: " . count($success) . "\n";
echo "Warnings: " . count($warnings) . "\n";
echo "Errors: " . count($errors) . "\n\n";

if (!empty($success)) {
    echo "✓ Successful checks:\n";
    foreach ($success as $msg) {
        echo "  - {$msg}\n";
    }
    echo "\n";
}

if (!empty($warnings)) {
    echo "⚠ Warnings:\n";
    foreach ($warnings as $msg) {
        echo "  - {$msg}\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "✗ Errors found:\n";
    foreach ($errors as $msg) {
        echo "  - {$msg}\n";
    }
    echo "\n";
    exit(1);
} else {
    echo "✓ All checks passed! Implementation appears to be complete.\n";
    echo "\nNext steps:\n";
    echo "1. Test registration flow (user and admin)\n";
    echo "2. Test Google OAuth flow\n";
    echo "3. Test trial expiration blocking\n";
    echo "4. Test notification filtering by condominium\n";
    exit(0);
}
