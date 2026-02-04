<?php

namespace Tests\Integration\Services;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;
use App\Services\SubscriptionService;
use App\Services\LicenseService;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Condominium;
use App\Models\Fraction;
use App\Models\SubscriptionCondominium;

/**
 * Integration tests for Subscription Acceptance Criteria
 * 
 * These tests verify the acceptance criteria using a real database (SQLite in-memory)
 */
class SubscriptionAcceptanceCriteriaTest extends TestCase
{
    protected $db;
    protected $subscriptionService;
    protected $licenseService;

    protected function setUp(): void
    {
        parent::setUp();
        
        try {
            $this->db = $this->getTestDatabase();
            $this->setupTables();
            $this->setupGlobalDb();
            
            $this->subscriptionService = new SubscriptionService();
            $this->licenseService = new LicenseService();
        } catch (PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            try {
                $this->db->exec("DELETE FROM subscription_condominiums");
                $this->db->exec("DELETE FROM fractions");
                $this->db->exec("DELETE FROM condominiums");
                $this->db->exec("DELETE FROM subscriptions");
                $this->db->exec("DELETE FROM plans");
                $this->db->exec("DELETE FROM users");
            } catch (PDOException $e) {
                // Ignore cleanup errors
            }
        }
        
        parent::tearDown();
    }

    /**
     * Get test database connection
     */
    protected function getTestDatabase(): PDO
    {
        // Try MySQL first if configured
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: 'predio_test';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        if (getenv('DB_NAME') || !empty($dbname)) {
            try {
                if (strpos($host, ':') !== false) {
                    list($host, $port) = explode(':', $host, 2);
                    $dsn = "mysql:dbname={$dbname};host={$host};port={$port}";
                } else {
                    $dsn = "mysql:dbname={$dbname};host={$host}";
                }

                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                
                $pdo->query("SELECT 1");
                return $pdo;
            } catch (PDOException $e) {
                // Fall through to SQLite
            }
        }

        // Fallback to SQLite in-memory
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }

    /**
     * Setup global $db variable for models
     */
    protected function setupGlobalDb(): void
    {
        global $db;
        $db = $this->db;
    }

    /**
     * Setup required tables
     */
    protected function setupTables(): void
    {
        $isSQLite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        
        if ($isSQLite) {
            // SQLite syntax
            $this->db->exec("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    role VARCHAR(50) DEFAULT 'condomino',
                    status VARCHAR(50) DEFAULT 'active',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $this->db->exec("
                CREATE TABLE plans (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    description TEXT,
                    price_monthly DECIMAL(10,2),
                    price_yearly DECIMAL(10,2),
                    plan_type VARCHAR(50),
                    license_min INT,
                    license_limit INT,
                    allow_multiple_condos BOOLEAN DEFAULT 0,
                    allow_overage BOOLEAN DEFAULT 0,
                    pricing_mode VARCHAR(50),
                    annual_discount_percentage DECIMAL(5,2) DEFAULT 0,
                    limit_condominios INT,
                    limit_fracoes INT,
                    features TEXT,
                    is_active BOOLEAN DEFAULT 1,
                    sort_order INT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");

            $this->db->exec("
                CREATE TABLE subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INT NOT NULL,
                    plan_id INT NOT NULL,
                    condominium_id INT NULL,
                    status VARCHAR(50) DEFAULT 'trial',
                    used_licenses INT DEFAULT 0,
                    license_limit INT NULL,
                    allow_overage BOOLEAN DEFAULT 0,
                    charge_minimum BOOLEAN DEFAULT 1,
                    trial_ends_at DATETIME NULL,
                    current_period_start DATETIME NOT NULL,
                    current_period_end DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    FOREIGN KEY (plan_id) REFERENCES plans(id),
                    FOREIGN KEY (condominium_id) REFERENCES condominiums(id)
                )
            ");

            $this->db->exec("
                CREATE TABLE condominiums (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    address TEXT,
                    subscription_id INT NULL,
                    subscription_status VARCHAR(50) DEFAULT 'active',
                    locked_at DATETIME NULL,
                    locked_reason TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id)
                )
            ");

            $this->db->exec("
                CREATE TABLE fractions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    condominium_id INT NOT NULL,
                    identifier VARCHAR(50) NOT NULL,
                    permillage DECIMAL(10,2) DEFAULT 0,
                    is_active BOOLEAN DEFAULT 1,
                    archived_at DATETIME NULL,
                    license_consumed BOOLEAN DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (condominium_id) REFERENCES condominiums(id)
                )
            ");

            $this->db->exec("
                CREATE TABLE subscription_condominiums (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    subscription_id INT NOT NULL,
                    condominium_id INT NOT NULL,
                    status VARCHAR(50) DEFAULT 'active',
                    attached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    detached_at DATETIME NULL,
                    detached_by INT NULL,
                    notes TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
                    FOREIGN KEY (condominium_id) REFERENCES condominiums(id)
                )
            ");

            $this->db->exec("
                CREATE TABLE audit_subscriptions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    subscription_id INT NOT NULL,
                    user_id INT NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    old_plan_id INT NULL,
                    new_plan_id INT NULL,
                    old_status VARCHAR(50) NULL,
                    new_status VARCHAR(50) NULL,
                    old_period_start DATETIME NULL,
                    new_period_start DATETIME NULL,
                    old_period_end DATETIME NULL,
                    new_period_end DATETIME NULL,
                    description TEXT NULL,
                    metadata TEXT NULL,
                    performed_by INT NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id),
                    FOREIGN KEY (user_id) REFERENCES users(id),
                    FOREIGN KEY (old_plan_id) REFERENCES plans(id),
                    FOREIGN KEY (new_plan_id) REFERENCES plans(id),
                    FOREIGN KEY (performed_by) REFERENCES users(id)
                )
            ");
        } else {
            // MySQL syntax - similar structure but with MySQL-specific syntax
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    role VARCHAR(50) DEFAULT 'condomino',
                    status VARCHAR(50) DEFAULT 'active',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS plans (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL UNIQUE,
                    description TEXT,
                    price_monthly DECIMAL(10,2),
                    price_yearly DECIMAL(10,2),
                    plan_type VARCHAR(50),
                    license_min INT,
                    license_limit INT,
                    allow_multiple_condos BOOLEAN DEFAULT FALSE,
                    allow_overage BOOLEAN DEFAULT FALSE,
                    pricing_mode VARCHAR(50),
                    annual_discount_percentage DECIMAL(5,2) DEFAULT 0,
                    limit_condominios INT,
                    limit_fracoes INT,
                    features TEXT,
                    is_active BOOLEAN DEFAULT TRUE,
                    sort_order INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    plan_id INT NOT NULL,
                    condominium_id INT NULL,
                    status ENUM('trial', 'active', 'suspended', 'canceled', 'expired') DEFAULT 'trial',
                    used_licenses INT DEFAULT 0,
                    license_limit INT NULL,
                    allow_overage BOOLEAN DEFAULT FALSE,
                    charge_minimum BOOLEAN DEFAULT TRUE,
                    trial_ends_at TIMESTAMP NULL,
                    current_period_start TIMESTAMP NOT NULL,
                    current_period_end TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (plan_id) REFERENCES plans(id),
                    FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE SET NULL
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS condominiums (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    address TEXT,
                    subscription_id INT NULL,
                    subscription_status ENUM('active', 'locked', 'read_only') DEFAULT 'active',
                    locked_at TIMESTAMP NULL,
                    locked_reason TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS fractions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    condominium_id INT NOT NULL,
                    identifier VARCHAR(50) NOT NULL,
                    permillage DECIMAL(10,2) DEFAULT 0,
                    is_active BOOLEAN DEFAULT TRUE,
                    archived_at TIMESTAMP NULL,
                    license_consumed BOOLEAN DEFAULT TRUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS subscription_condominiums (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    subscription_id INT NOT NULL,
                    condominium_id INT NOT NULL,
                    status ENUM('active', 'detached') DEFAULT 'active',
                    attached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    detached_at TIMESTAMP NULL,
                    detached_by INT NULL,
                    notes TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
                    FOREIGN KEY (condominium_id) REFERENCES condominiums(id) ON DELETE CASCADE
                )
            ");

            $this->db->exec("
                CREATE TABLE IF NOT EXISTS audit_subscriptions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    subscription_id INT NOT NULL,
                    user_id INT NOT NULL,
                    action VARCHAR(100) NOT NULL,
                    old_plan_id INT NULL,
                    new_plan_id INT NULL,
                    old_status VARCHAR(50) NULL,
                    new_status VARCHAR(50) NULL,
                    old_period_start DATETIME NULL,
                    new_period_start DATETIME NULL,
                    old_period_end DATETIME NULL,
                    new_period_end DATETIME NULL,
                    description TEXT NULL,
                    metadata JSON NULL,
                    performed_by INT NULL,
                    ip_address VARCHAR(45) NULL,
                    user_agent TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (old_plan_id) REFERENCES plans(id) ON DELETE SET NULL,
                    FOREIGN KEY (new_plan_id) REFERENCES plans(id) ON DELETE SET NULL,
                    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
                )
            ");
        }
    }

    /**
     * Create a test user
     */
    protected function createUser(): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, password, role, status)
            VALUES (:name, :email, :password, :role, :status)
        ");
        
        $stmt->execute([
            ':name' => 'Test User',
            ':email' => 'test@example.com',
            ':password' => password_hash('password123', PASSWORD_DEFAULT),
            ':role' => 'admin',
            ':status' => 'active'
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Create a test plan
     */
    protected function createPlan(string $slug, string $planType, int $licenseMin, bool $allowMultipleCondos = false, bool $allowOverage = false): int
    {
        $isSQLite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        
        $stmt = $this->db->prepare("
            INSERT INTO plans (name, slug, plan_type, license_min, allow_multiple_condos, allow_overage, is_active)
            VALUES (:name, :slug, :plan_type, :license_min, :allow_multiple_condos, :allow_overage, :is_active)
        ");
        
        $stmt->execute([
            ':name' => ucfirst($slug),
            ':slug' => $slug,
            ':plan_type' => $planType,
            ':license_min' => $licenseMin,
            ':allow_multiple_condos' => $isSQLite ? ($allowMultipleCondos ? 1 : 0) : $allowMultipleCondos,
            ':allow_overage' => $isSQLite ? ($allowOverage ? 1 : 0) : $allowOverage,
            ':is_active' => $isSQLite ? 1 : true
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Create a test condominium
     */
    protected function createCondominium(int $userId, string $name = 'Test Condominium'): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO condominiums (user_id, name, address)
            VALUES (:user_id, :name, :address)
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':name' => $name,
            ':address' => 'Test Address'
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Create test fractions
     */
    protected function createFractions(int $condominiumId, int $count): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO fractions (condominium_id, identifier, is_active)
            VALUES (:condominium_id, :identifier, :is_active)
        ");
        
        for ($i = 1; $i <= $count; $i++) {
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':identifier' => "A{$i}",
                ':is_active' => 1
            ]);
        }
    }

    /**
     * Create a test subscription
     */
    protected function createSubscription(int $userId, int $planId, ?int $condominiumId = null, ?int $licenseLimit = null, bool $allowOverage = false): int
    {
        $isSQLite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime('+1 month'));
        
        $stmt = $this->db->prepare("
            INSERT INTO subscriptions (
                user_id, plan_id, condominium_id, status, license_limit, allow_overage,
                charge_minimum, current_period_start, current_period_end
            )
            VALUES (
                :user_id, :plan_id, :condominium_id, :status, :license_limit, :allow_overage,
                :charge_minimum, :current_period_start, :current_period_end
            )
        ");
        
        $stmt->execute([
            ':user_id' => $userId,
            ':plan_id' => $planId,
            ':condominium_id' => $condominiumId,
            ':status' => 'active',
            ':license_limit' => $licenseLimit,
            ':allow_overage' => $isSQLite ? ($allowOverage ? 1 : 0) : $allowOverage,
            ':charge_minimum' => $isSQLite ? 1 : true,
            ':current_period_start' => $now,
            ':current_period_end' => $periodEnd
        ]);
        
        return (int)$this->db->lastInsertId();
    }

    /**
     * Test 1: Base plan with 6 fractions => used_licenses = 10 (minimum)
     */
    public function testBasePlanAppliesMinimumWithSixFractions(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('condominio', 'condominio', 10, false, false);
        $condominiumId = $this->createCondominium($userId);
        $this->createFractions($condominiumId, 6);
        
        $subscriptionId = $this->createSubscription($userId, $planId, $condominiumId);
        
        // Update condominium subscription_id for Base plan
        $this->db->prepare("UPDATE condominiums SET subscription_id = :subscription_id WHERE id = :id")
            ->execute([':subscription_id' => $subscriptionId, ':id' => $condominiumId]);
        
        // Recalculate licenses
        $usedLicenses = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        
        // Get subscription to check charge_minimum
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->findById($subscriptionId);
        
        // Verify used_licenses = 10 (minimum applied)
        $this->assertEquals(10, $usedLicenses, 'Used licenses should be 10 (minimum)');
        $this->assertEquals(10, (int)$subscription['used_licenses'], 'Subscription should have 10 used licenses');
        
        // Verify charge_minimum is true (applied via applyMinimumCharge)
        $effectiveLicenses = $this->licenseService->applyMinimumCharge(
            $subscriptionId,
            $usedLicenses,
            10
        );
        $this->assertEquals(10, $effectiveLicenses, 'Effective licenses should be 10 (minimum)');
    }

    /**
     * Test 2: Base plan cannot attach second condominium
     */
    public function testBasePlanCannotAttachSecondCondominium(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('condominio', 'condominio', 10, false, false);
        $condominiumId1 = $this->createCondominium($userId, 'Condominium 1');
        $condominiumId2 = $this->createCondominium($userId, 'Condominium 2');
        
        $subscriptionId = $this->createSubscription($userId, $planId, $condominiumId1);
        
        // Update first condominium subscription_id
        $this->db->prepare("UPDATE condominiums SET subscription_id = :subscription_id WHERE id = :id")
            ->execute([':subscription_id' => $subscriptionId, ':id' => $condominiumId1]);
        
        // Try to attach second condominium - should throw exception
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Base|único|um condomínio/i');
        
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId2, $userId);
    }

    /**
     * Test 3: Pro plan with 2 condominiums (30+40) => used_licenses = 70
     */
    public function testProPlanSumsAllCondominiums(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('professional', 'professional', 50, true, false);
        $condominiumId1 = $this->createCondominium($userId, 'Condominium 1');
        $condominiumId2 = $this->createCondominium($userId, 'Condominium 2');
        
        $this->createFractions($condominiumId1, 30);
        $this->createFractions($condominiumId2, 40);
        
        $subscriptionId = $this->createSubscription($userId, $planId);
        
        // Attach both condominiums
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId1, $userId);
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId2, $userId);
        
        // Recalculate licenses
        $usedLicenses = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        
        // Verify used_licenses = 70 (30 + 40)
        $this->assertEquals(70, $usedLicenses, 'Used licenses should be 70 (30 + 40)');
        
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->findById($subscriptionId);
        $this->assertEquals(70, (int)$subscription['used_licenses'], 'Subscription should have 70 used licenses');
    }

    /**
     * Test 4: Pro plan detach condominium with 40 => used_licenses = 30
     */
    public function testProPlanRecalculatesAfterDetach(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('professional', 'professional', 50, true, false);
        $condominiumId1 = $this->createCondominium($userId, 'Condominium 1');
        $condominiumId2 = $this->createCondominium($userId, 'Condominium 2');
        
        $this->createFractions($condominiumId1, 30);
        $this->createFractions($condominiumId2, 40);
        
        $subscriptionId = $this->createSubscription($userId, $planId);
        
        // Attach both condominiums
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId1, $userId);
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId2, $userId);
        
        // Verify initial count is 70
        $initialLicenses = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        $this->assertEquals(70, $initialLicenses);
        
        // Detach condominium with 40 fractions
        $this->subscriptionService->detachCondominium($subscriptionId, $condominiumId2, $userId, 'Test detach');
        
        // Recalculate licenses
        $usedLicenses = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        
        // Verify used_licenses = 30 (after detach)
        $this->assertEquals(30, $usedLicenses, 'Used licenses should be 30 after detaching condominium with 40 fractions');
        
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->findById($subscriptionId);
        $this->assertEquals(30, (int)$subscription['used_licenses'], 'Subscription should have 30 used licenses');
    }

    /**
     * Test 5: Pro plan with license_limit=60 cannot exceed (allow_overage=false)
     */
    public function testProPlanBlocksExceedingLimitWithoutOverage(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('professional', 'professional', 50, true, false);
        $condominiumId1 = $this->createCondominium($userId, 'Condominium 1');
        $condominiumId2 = $this->createCondominium($userId, 'Condominium 2');
        
        // Create 60 fractions in first condominium (at limit)
        $this->createFractions($condominiumId1, 60);
        // Create 10 fractions in second condominium (would exceed limit)
        $this->createFractions($condominiumId2, 10);
        
        $subscriptionId = $this->createSubscription($userId, $planId, null, 60, false);
        
        // Attach first condominium (60 licenses - at limit)
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId1, $userId);
        
        // Verify we're at limit
        $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        
        // Try to attach second condominium - should fail
        $this->expectException(\Exception::class);
        
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId2, $userId);
    }

    /**
     * Test 6: Detached condominium is locked
     */
    public function testDetachedCondominiumIsLocked(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('professional', 'professional', 50, true, false);
        $condominiumId1 = $this->createCondominium($userId, 'Condominium 1');
        $condominiumId2 = $this->createCondominium($userId, 'Condominium 2');
        
        $this->createFractions($condominiumId1, 30);
        $this->createFractions($condominiumId2, 40);
        
        $subscriptionId = $this->createSubscription($userId, $planId);
        
        // Attach both condominiums
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId1, $userId);
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId2, $userId);
        
        // Detach second condominium
        $reason = 'Test detachment reason';
        $this->subscriptionService->detachCondominium($subscriptionId, $condominiumId2, $userId, $reason);
        
        // Verify condominium is locked
        $stmt = $this->db->prepare("SELECT subscription_status, locked_at, locked_reason FROM condominiums WHERE id = :id");
        $stmt->execute([':id' => $condominiumId2]);
        $condominium = $stmt->fetch();
        
        $this->assertEquals('locked', $condominium['subscription_status'], 'Condominium should be locked');
        $this->assertNotNull($condominium['locked_at'], 'locked_at should be set');
        $this->assertEquals($reason, $condominium['locked_reason'], 'locked_reason should match');
    }

    /**
     * Test 7: Enterprise plan allows overage
     */
    public function testEnterprisePlanAllowsOverage(): void
    {
        $userId = $this->createUser();
        $planId = $this->createPlan('enterprise', 'enterprise', 200, true, true);
        $condominiumId1 = $this->createCondominium($userId, 'Condominium 1');
        $condominiumId2 = $this->createCondominium($userId, 'Condominium 2');
        
        // Create 200 fractions in first condominium (at limit)
        $this->createFractions($condominiumId1, 200);
        // Create 10 fractions in second condominium (would exceed limit, but overage allowed)
        $this->createFractions($condominiumId2, 10);
        
        $subscriptionId = $this->createSubscription($userId, $planId, null, 200, true);
        
        // Attach first condominium (200 licenses - at limit)
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId1, $userId);
        
        // Verify we're at limit
        $beforeOverage = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        $this->assertEquals(200, $beforeOverage);
        
        // Attach second condominium - should succeed (overage allowed)
        $this->subscriptionService->attachCondominium($subscriptionId, $condominiumId2, $userId);
        
        // Recalculate licenses
        $afterOverage = $this->subscriptionService->recalculateUsedLicenses($subscriptionId);
        
        // Verify used_licenses > 200 (overage allowed)
        $this->assertGreaterThan(200, $afterOverage, 'Used licenses should exceed limit when overage is allowed');
        $this->assertEquals(210, $afterOverage, 'Used licenses should be 210 (200 + 10)');
        
        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->findById($subscriptionId);
        $this->assertEquals(210, (int)$subscription['used_licenses'], 'Subscription should have 210 used licenses');
    }
}
