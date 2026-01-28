<?php

namespace Tests\Helpers;

use PDO;
use PDOStatement;

/**
 * Helper class for creating database mocks for unit tests
 * 
 * This class provides utilities to create mock PDO connections and statements
 * for testing without requiring a real database connection.
 */
class DatabaseMockHelper
{
    /**
     * Create a SQLite in-memory database for testing
     * 
     * This is useful for integration tests that need a real database
     * but don't want to depend on MySQL being available.
     * 
     * @return PDO
     * @throws \RuntimeException If SQLite PDO driver is not available
     */
    public static function createInMemoryDatabase(): PDO
    {
        // Check if SQLite driver is available
        $availableDrivers = PDO::getAvailableDrivers();
        if (!in_array('sqlite', $availableDrivers)) {
            throw new \RuntimeException(
                'PDO SQLite driver is not available. ' .
                'Please install/enable the pdo_sqlite PHP extension. ' .
                'Available PDO drivers: ' . implode(', ', $availableDrivers)
            );
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        return $pdo;
    }

    /**
     * Create a mock PDO statement that returns predefined data
     * 
     * @param array $data Data to return when fetch() or fetchAll() is called
     * @return PDOStatement
     */
    public static function createMockStatement(array $data = []): PDOStatement
    {
        $pdo = self::createInMemoryDatabase();
        $stmt = $pdo->prepare("SELECT 1");
        $stmt->execute();
        
        // Create a custom statement that returns our mock data
        return new class($data) extends PDOStatement {
            private $mockData;
            private $currentIndex = 0;

            public function __construct(array $data)
            {
                $this->mockData = $data;
            }

            public function fetch($fetchStyle = PDO::FETCH_ASSOC, $cursorOrientation = PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
            {
                if ($this->currentIndex >= count($this->mockData)) {
                    return false;
                }
                return $this->mockData[$this->currentIndex++];
            }

            public function fetchAll($fetchStyle = PDO::FETCH_ASSOC, $fetchArgument = null, $ctorArgs = [])
            {
                return $this->mockData;
            }

            public function rowCount(): int
            {
                return count($this->mockData);
            }
        };
    }

    /**
     * Create mock plan data
     * 
     * @param array $attributes Additional attributes to override defaults
     * @return array
     */
    public static function createMockPlan(array $attributes = []): array
    {
        $defaults = [
            'id' => 1,
            'name' => 'Test Plan',
            'slug' => 'test-plan',
            'description' => 'Test plan description',
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
            'features' => json_encode([]),
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return array_merge($defaults, $attributes);
    }

    /**
     * Create mock pricing tier data
     * 
     * @param int $planId Plan ID
     * @param array $attributes Additional attributes to override defaults
     * @return array
     */
    public static function createMockPricingTier(int $planId, array $attributes = []): array
    {
        $defaults = [
            'id' => 1,
            'plan_id' => $planId,
            'min_licenses' => 10,
            'max_licenses' => 19,
            'price_per_license' => 1.00,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return array_merge($defaults, $attributes);
    }

    /**
     * Create mock subscription data
     * 
     * @param int $userId User ID
     * @param int $planId Plan ID
     * @param array $attributes Additional attributes to override defaults
     * @return array
     */
    public static function createMockSubscription(int $userId, int $planId, array $attributes = []): array
    {
        $defaults = [
            'id' => 1,
            'user_id' => $userId,
            'plan_id' => $planId,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'current_period_start' => date('Y-m-d H:i:s'),
            'current_period_end' => date('Y-m-d H:i:s', strtotime('+1 month')),
            'used_licenses' => 0,
            'extra_licenses' => 0,
            'condominium_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return array_merge($defaults, $attributes);
    }

    /**
     * Setup basic tables in SQLite in-memory database
     * 
     * @param PDO $pdo Database connection
     * @return void
     */
    public static function setupBasicTables(PDO $pdo): void
    {
        // Create plans table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plans (
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

        // Create plan_pricing_tiers table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS plan_pricing_tiers (
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

        // Create subscriptions table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INT NOT NULL,
                plan_id INT NOT NULL,
                status VARCHAR(50) NOT NULL,
                billing_cycle VARCHAR(50),
                current_period_start DATETIME,
                current_period_end DATETIME,
                used_licenses INT DEFAULT 0,
                extra_licenses INT DEFAULT 0,
                condominium_id INT NULL,
                license_limit INT NULL,
                allow_overage BOOLEAN DEFAULT 0,
                charge_minimum BOOLEAN DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
            )
        ");
    }

    /**
     * Create mock condominium data
     * 
     * @param int $userId User ID
     * @param array $attributes Additional attributes to override defaults
     * @return array
     */
    public static function createMockCondominium(int $userId, array $attributes = []): array
    {
        $defaults = [
            'id' => 1,
            'user_id' => $userId,
            'name' => 'Test Condominium',
            'address' => 'Test Address',
            'subscription_id' => null,
            'subscription_status' => 'active',
            'locked_at' => null,
            'locked_reason' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return array_merge($defaults, $attributes);
    }

    /**
     * Create mock fraction data
     * 
     * @param int $condominiumId Condominium ID
     * @param array $attributes Additional attributes to override defaults
     * @return array
     */
    public static function createMockFraction(int $condominiumId, array $attributes = []): array
    {
        $defaults = [
            'id' => 1,
            'condominium_id' => $condominiumId,
            'identifier' => 'A1',
            'permillage' => 100.0,
            'is_active' => true,
            'archived_at' => null,
            'license_consumed' => true,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        return array_merge($defaults, $attributes);
    }

    /**
     * Setup all required tables for subscription tests
     * 
     * @param PDO $pdo Database connection
     * @return void
     */
    public static function setupSubscriptionTables(PDO $pdo): void
    {
        // Setup basic tables first
        self::setupBasicTables($pdo);

        // Create users table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
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

        // Create condominiums table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS condominiums (
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

        // Create fractions table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fractions (
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

        // Create subscription_condominiums table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS subscription_condominiums (
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
    }

    /**
     * Insert mock data into database
     * 
     * @param PDO $pdo Database connection
     * @param string $table Table name
     * @param array $data Data to insert
     * @return int Inserted row ID
     */
    public static function insertMockData(PDO $pdo, string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $stmt = $pdo->prepare($sql);
        
        foreach ($data as $key => $value) {
            // Convert booleans to integers for SQLite compatibility
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            $stmt->bindValue(':' . $key, $value);
        }
        
        $stmt->execute();
        return (int)$pdo->lastInsertId();
    }
}
