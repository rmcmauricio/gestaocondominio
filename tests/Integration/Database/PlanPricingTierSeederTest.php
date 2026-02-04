<?php

namespace Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use PDO;
use PDOException;

class PlanPricingTierSeederTest extends TestCase
{
    protected $db;
    protected static $testDbName = 'predio_test';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Try to connect to test database
        // If not available, skip tests
        try {
            $this->db = $this->getTestDatabase();
            $this->setupTables();
            $this->seedPlans();
        } catch (PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Clean up test data
        if ($this->db) {
            try {
                $this->db->exec("DELETE FROM plan_pricing_tiers");
                $this->db->exec("DELETE FROM plans WHERE slug IN ('condominio', 'professional', 'enterprise')");
            } catch (PDOException $e) {
                // Ignore cleanup errors
            }
        }
        
        parent::tearDown();
    }

    /**
     * Get test database connection
     * Tries MySQL first, falls back to SQLite in-memory
     */
    protected function getTestDatabase(): PDO
    {
        // Try MySQL first if configured
        $host = getenv('DB_HOST') ?: 'localhost';
        $dbname = getenv('DB_NAME') ?: self::$testDbName;
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        // Only try MySQL if DB_NAME is explicitly set
        if (getenv('DB_NAME') || !empty($dbname)) {
            try {
                // Handle host with port
                if (strpos($host, ':') !== false) {
                    list($host, $port) = explode(':', $host, 2);
                    $dsn = "mysql:dbname={$dbname};host={$host};port={$port}";
                } else {
                    $dsn = "mysql:dbname={$dbname};host={$host}";
                }

                $pdo = new PDO(
                    $dsn,
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );
                
                // Test connection
                $pdo->query("SELECT 1");
                return $pdo;
            } catch (PDOException $e) {
                // Fall through to SQLite
            }
        }

        // Fallback to SQLite in-memory
        return new PDO(
            'sqlite::memory:',
            null,
            null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    }

    /**
     * Setup required tables for testing
     */
    protected function setupTables(): void
    {
        $isSQLite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        
        if ($isSQLite) {
            // SQLite syntax
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
                CREATE TABLE plan_pricing_tiers (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    plan_id INT NOT NULL,
                    min_licenses INT NOT NULL,
                    max_licenses INT NULL,
                    price_per_license DECIMAL(10,2) NOT NULL,
                    is_active BOOLEAN DEFAULT 1,
                    sort_order INT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
                )
            ");
        } else {
            // MySQL syntax
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
                CREATE TABLE IF NOT EXISTS plan_pricing_tiers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    plan_id INT NOT NULL,
                    min_licenses INT NOT NULL,
                    max_licenses INT NULL,
                    price_per_license DECIMAL(10,2) NOT NULL,
                    is_active BOOLEAN DEFAULT TRUE,
                    sort_order INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
                )
            ");
        }
    }

    /**
     * Seed basic plans for testing
     */
    protected function seedPlans(): void
    {
        $commonFeatures = json_encode([
            'financas_completas' => true,
            'documentos' => true,
            'ocorrencias' => true,
            'votacoes_online' => true,
            'reservas_espacos' => true,
            'gestao_contratos' => true,
            'gestao_fornecedores' => true
        ]);

        $plans = [
            [
                'name' => 'Condomínio',
                'slug' => 'condominio',
                'description' => 'Plano base ideal para pequenos condomínios',
                'price_monthly' => 9.99,
                'price_yearly' => 99.99,
                'plan_type' => 'condominio',
                'license_min' => 10,
                'license_limit' => null,
                'allow_multiple_condos' => false,
                'allow_overage' => false,
                'pricing_mode' => 'flat',
                'annual_discount_percentage' => 0,
                'limit_condominios' => 1,
                'limit_fracoes' => null,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 1
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Plano completo para gestão profissional com múltiplos condomínios',
                'price_monthly' => 39.99,
                'price_yearly' => 399.99,
                'plan_type' => 'professional',
                'license_min' => 50,
                'license_limit' => null,
                'allow_multiple_condos' => true,
                'allow_overage' => false,
                'pricing_mode' => 'flat',
                'annual_discount_percentage' => 0,
                'limit_condominios' => null,
                'limit_fracoes' => null,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 2
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Solução empresarial com todas as funcionalidades e suporte a overage',
                'price_monthly' => 169.99,
                'price_yearly' => 1699.99,
                'plan_type' => 'enterprise',
                'license_min' => 200,
                'license_limit' => null,
                'allow_multiple_condos' => true,
                'allow_overage' => true,
                'pricing_mode' => 'flat',
                'annual_discount_percentage' => 0,
                'limit_condominios' => null,
                'limit_fracoes' => null,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 3
            ]
        ];

        $isSQLite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        
        $stmt = $this->db->prepare("
            INSERT INTO plans (
                name, slug, description, price_monthly, price_yearly,
                plan_type, license_min, license_limit, allow_multiple_condos,
                allow_overage, pricing_mode, annual_discount_percentage,
                limit_condominios, limit_fracoes, features, is_active, sort_order
            ) VALUES (
                :name, :slug, :description, :price_monthly, :price_yearly,
                :plan_type, :license_min, :license_limit, :allow_multiple_condos,
                :allow_overage, :pricing_mode, :annual_discount_percentage,
                :limit_condominios, :limit_fracoes, :features, :is_active, :sort_order
            )
        ");

        foreach ($plans as $plan) {
            // Convert booleans to integers for SQLite compatibility
            if ($isSQLite) {
                $plan['allow_multiple_condos'] = $plan['allow_multiple_condos'] ? 1 : 0;
                $plan['allow_overage'] = $plan['allow_overage'] ? 1 : 0;
                $plan['is_active'] = $plan['is_active'] ? 1 : 0;
            }
            $stmt->execute($plan);
        }
    }

    /**
     * Test that seeder runs without errors
     */
    public function testSeederRunsSuccessfully(): void
    {
        require_once __DIR__ . '/../../../database/seeders/PlanPricingTierSeeder.php';
        
        $seeder = new \PlanPricingTierSeeder($this->db);
        
        // Should not throw any exceptions
        $this->expectNotToPerformAssertions();
        $seeder->run();
    }

    /**
     * Test that Condomínio plan tiers are seeded correctly
     */
    public function testCondominioTiersAreSeeded(): void
    {
        require_once __DIR__ . '/../../../database/seeders/PlanPricingTierSeeder.php';
        
        $seeder = new \PlanPricingTierSeeder($this->db);
        $seeder->run();

        // Get plan ID
        $planStmt = $this->db->query("SELECT id FROM plans WHERE slug = 'condominio'");
        $plan = $planStmt->fetch();
        $this->assertNotFalse($plan, 'Condomínio plan should exist');
        $planId = $plan['id'];

        // Get tiers
        $tiersStmt = $this->db->prepare("
            SELECT * FROM plan_pricing_tiers 
            WHERE plan_id = :plan_id 
            ORDER BY sort_order
        ");
        $tiersStmt->execute([':plan_id' => $planId]);
        $tiers = $tiersStmt->fetchAll();

        // Should have 3 tiers
        $this->assertCount(3, $tiers, 'Condomínio plan should have 3 tiers');

        // Verify first tier
        $this->assertEquals(10, (int)$tiers[0]['min_licenses']);
        $this->assertEquals(19, (int)$tiers[0]['max_licenses']);
        $this->assertEqualsWithDelta(1.00, (float)$tiers[0]['price_per_license'], 0.01);
        $this->assertEquals(1, (int)$tiers[0]['sort_order']);
        $this->assertTrue((bool)$tiers[0]['is_active']);

        // Verify second tier
        $this->assertEquals(20, (int)$tiers[1]['min_licenses']);
        $this->assertEquals(39, (int)$tiers[1]['max_licenses']);
        $this->assertEqualsWithDelta(0.95, (float)$tiers[1]['price_per_license'], 0.01);
        $this->assertEquals(2, (int)$tiers[1]['sort_order']);

        // Verify third tier
        $this->assertEquals(40, (int)$tiers[2]['min_licenses']);
        $this->assertNull($tiers[2]['max_licenses']);
        $this->assertEqualsWithDelta(0.90, (float)$tiers[2]['price_per_license'], 0.01);
        $this->assertEquals(3, (int)$tiers[2]['sort_order']);
    }

    /**
     * Test that Professional plan tiers are seeded correctly
     */
    public function testProfessionalTiersAreSeeded(): void
    {
        require_once __DIR__ . '/../../../database/seeders/PlanPricingTierSeeder.php';
        
        $seeder = new \PlanPricingTierSeeder($this->db);
        $seeder->run();

        // Get plan ID
        $planStmt = $this->db->query("SELECT id FROM plans WHERE slug = 'professional'");
        $plan = $planStmt->fetch();
        $this->assertNotFalse($plan, 'Professional plan should exist');
        $planId = $plan['id'];

        // Get tiers
        $tiersStmt = $this->db->prepare("
            SELECT * FROM plan_pricing_tiers 
            WHERE plan_id = :plan_id 
            ORDER BY sort_order
        ");
        $tiersStmt->execute([':plan_id' => $planId]);
        $tiers = $tiersStmt->fetchAll();

        // Should have 3 tiers
        $this->assertCount(3, $tiers, 'Professional plan should have 3 tiers');

        // Verify first tier
        $this->assertEquals(50, (int)$tiers[0]['min_licenses']);
        $this->assertEquals(199, (int)$tiers[0]['max_licenses']);
        $this->assertEquals('0.85', (string)$tiers[0]['price_per_license']);
        $this->assertEquals(1, (int)$tiers[0]['sort_order']);

        // Verify second tier
        $this->assertEquals(200, (int)$tiers[1]['min_licenses']);
        $this->assertEquals(499, (int)$tiers[1]['max_licenses']);
        $this->assertEqualsWithDelta(0.80, (float)$tiers[1]['price_per_license'], 0.01);
        $this->assertEquals(2, (int)$tiers[1]['sort_order']);

        // Verify third tier
        $this->assertEquals(500, (int)$tiers[2]['min_licenses']);
        $this->assertNull($tiers[2]['max_licenses']);
        $this->assertEquals('0.75', (string)$tiers[2]['price_per_license']);
        $this->assertEquals(3, (int)$tiers[2]['sort_order']);
    }

    /**
     * Test that Enterprise plan tiers are seeded correctly
     */
    public function testEnterpriseTiersAreSeeded(): void
    {
        require_once __DIR__ . '/../../../database/seeders/PlanPricingTierSeeder.php';
        
        $seeder = new \PlanPricingTierSeeder($this->db);
        $seeder->run();

        // Get plan ID
        $planStmt = $this->db->query("SELECT id FROM plans WHERE slug = 'enterprise'");
        $plan = $planStmt->fetch();
        $this->assertNotFalse($plan, 'Enterprise plan should exist');
        $planId = $plan['id'];

        // Get tiers
        $tiersStmt = $this->db->prepare("
            SELECT * FROM plan_pricing_tiers 
            WHERE plan_id = :plan_id 
            ORDER BY sort_order
        ");
        $tiersStmt->execute([':plan_id' => $planId]);
        $tiers = $tiersStmt->fetchAll();

        // Should have 3 tiers
        $this->assertCount(3, $tiers, 'Enterprise plan should have 3 tiers');

        // Verify first tier
        $this->assertEquals(200, (int)$tiers[0]['min_licenses']);
        $this->assertEquals(499, (int)$tiers[0]['max_licenses']);
        $this->assertEqualsWithDelta(0.80, (float)$tiers[0]['price_per_license'], 0.01);
        $this->assertEquals(1, (int)$tiers[0]['sort_order']);

        // Verify second tier
        $this->assertEquals(500, (int)$tiers[1]['min_licenses']);
        $this->assertEquals(1999, (int)$tiers[1]['max_licenses']);
        $this->assertEqualsWithDelta(0.75, (float)$tiers[1]['price_per_license'], 0.01);
        $this->assertEquals(2, (int)$tiers[1]['sort_order']);

        // Verify third tier
        $this->assertEquals(2000, (int)$tiers[2]['min_licenses']);
        $this->assertNull($tiers[2]['max_licenses']);
        $this->assertEqualsWithDelta(0.65, (float)$tiers[2]['price_per_license'], 0.01);
        $this->assertEquals(3, (int)$tiers[2]['sort_order']);
    }

    /**
     * Test that seeder handles missing plans gracefully
     */
    public function testSeederHandlesMissingPlans(): void
    {
        require_once __DIR__ . '/../../../database/seeders/PlanPricingTierSeeder.php';
        
        // Delete all plans
        $this->db->exec("DELETE FROM plans WHERE slug IN ('condominio', 'professional', 'enterprise')");
        
        $seeder = new \PlanPricingTierSeeder($this->db);
        
        // Should not throw exception
        $seeder->run();
        
        // Verify no tiers were created
        $tiersStmt = $this->db->query("SELECT COUNT(*) as count FROM plan_pricing_tiers");
        $result = $tiersStmt->fetch();
        $this->assertEquals(0, (int)$result['count']);
    }

    /**
     * Test that seeder can be run multiple times (idempotent)
     */
    public function testSeederIsIdempotent(): void
    {
        require_once __DIR__ . '/../../../database/seeders/PlanPricingTierSeeder.php';
        
        $seeder = new \PlanPricingTierSeeder($this->db);
        
        // Run first time
        $seeder->run();
        
        // Count tiers after first run
        $tiersStmt = $this->db->query("SELECT COUNT(*) as count FROM plan_pricing_tiers");
        $firstRun = $tiersStmt->fetch();
        $firstCount = (int)$firstRun['count'];
        
        // Get a specific tier to verify it gets updated
        $tierStmt = $this->db->query("
            SELECT * FROM plan_pricing_tiers 
            WHERE plan_id = (SELECT id FROM plans WHERE slug = 'condominio') 
            AND min_licenses = 10 
            LIMIT 1
        ");
        $tierBefore = $tierStmt->fetch();
        $this->assertNotFalse($tierBefore, 'Tier should exist after first run');
        
        // Run second time
        $seeder->run();
        
        // Count tiers after second run
        $tiersStmt = $this->db->query("SELECT COUNT(*) as count FROM plan_pricing_tiers");
        $secondRun = $tiersStmt->fetch();
        $secondCount = (int)$secondRun['count'];
        
        // Should have the same number of tiers (seeder is now idempotent)
        $this->assertEquals($firstCount, $secondCount, 
            'Seeder should not create duplicate tiers when run multiple times');
        
        // Verify the tier was updated (updated_at should be different)
        $tierStmt = $this->db->query("
            SELECT * FROM plan_pricing_tiers 
            WHERE plan_id = (SELECT id FROM plans WHERE slug = 'condominio') 
            AND min_licenses = 10 
            LIMIT 1
        ");
        $tierAfter = $tierStmt->fetch();
        $this->assertNotFalse($tierAfter, 'Tier should still exist after second run');
        $this->assertEquals($tierBefore['price_per_license'], $tierAfter['price_per_license'], 
            'Tier price should remain the same');
    }
}
