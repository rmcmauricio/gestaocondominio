<?php

namespace App\Core;

class DatabaseMigration
{
    protected $db;
    protected $migrationsPath;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->migrationsPath = __DIR__ . '/../../database/migrations';
        
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }
        
        // Create migrations table if it doesn't exist
        $this->createMigrationsTable();
    }

    protected function createMigrationsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL,
            executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->db->exec($sql);
    }

    public function runMigrations(): void
    {
        $files = glob($this->migrationsPath . '/*.php');
        sort($files);
        
        $executedMigrations = $this->getExecutedMigrations();
        $batch = $this->getNextBatch();
        
        foreach ($files as $file) {
            $migrationName = basename($file, '.php');
            
            if (!in_array($migrationName, $executedMigrations)) {
                require_once $file;
                
                $className = $this->getClassNameFromFile($file);
                if (class_exists($className)) {
                    $migration = new $className($this->db);
                    
                    try {
                        // DDL operations (CREATE TABLE, etc.) cause implicit commits in MySQL
                        // So we don't use transactions for migrations
                        $migration->up();
                        $this->recordMigration($migrationName, $batch);
                        echo "Migration {$migrationName} executed successfully.\n";
                    } catch (\Exception $e) {
                        // Log error and continue with next migration
                        echo "ERROR: Migration {$migrationName} failed: " . $e->getMessage() . "\n";
                        throw new \Exception("Migration {$migrationName} failed: " . $e->getMessage());
                    }
                }
            }
        }

        $this->runAddonMigrations($executedMigrations, $batch);
    }

    /**
     * Run migrations for enabled addons (addons/AddonName/database/migrations/).
     * Migration names are prefixed with addon_<key>_ to avoid collisions.
     */
    protected function runAddonMigrations(array $executedMigrations, int $batch): void
    {
        $addonsBase = dirname($this->migrationsPath, 2) . '/addons';
        if (!is_dir($addonsBase)) {
            return;
        }
        $enabledAddons = $GLOBALS['enabled_addons'] ?? [];
        $manifests = $GLOBALS['addon_manifests'] ?? [];
        if (empty($enabledAddons) || empty($manifests)) {
            return;
        }
        foreach ($enabledAddons as $addonKey) {
            $manifest = $manifests[$addonKey] ?? null;
            if (!$manifest || empty($manifest['folder'])) {
                continue;
            }
            $migrationsPath = $addonsBase . '/' . $manifest['folder'] . '/database/migrations';
            if (!is_dir($migrationsPath)) {
                continue;
            }
            $prefix = 'addon_' . $addonKey . '_';
            $files = glob($migrationsPath . '/*.php');
            sort($files);
            foreach ($files as $file) {
                $baseName = basename($file, '.php');
                $migrationName = $prefix . $baseName;
                if (in_array($migrationName, $executedMigrations)) {
                    continue;
                }
                require_once $file;
                $className = $this->getClassNameFromFile($file);
                if (!class_exists($className)) {
                    continue;
                }
                try {
                    $migration = new $className($this->db);
                    $migration->up();
                    $this->recordMigration($migrationName, $batch);
                    echo "Addon migration {$migrationName} executed successfully.\n";
                } catch (\Exception $e) {
                    echo "ERROR: Addon migration {$migrationName} failed: " . $e->getMessage() . "\n";
                    throw new \Exception("Addon migration {$migrationName} failed: " . $e->getMessage());
                }
            }
        }
    }

    public function rollback(int $batches = 1): void
    {
        $migrations = $this->db->query(
            "SELECT migration FROM migrations ORDER BY batch DESC, id DESC LIMIT " . ($batches * 10)
        )->fetchAll(\PDO::FETCH_COLUMN);
        
        foreach (array_reverse($migrations) as $migrationName) {
            $file = $this->migrationsPath . '/' . $migrationName . '.php';
            if (file_exists($file)) {
                require_once $file;
                $className = $this->getClassNameFromFile($file);
                if (class_exists($className)) {
                    $migration = new $className($this->db);
                    $migration->down();
                    $this->removeMigration($migrationName);
                }
            }
        }
    }

    protected function getExecutedMigrations(): array
    {
        $stmt = $this->db->query("SELECT migration FROM migrations ORDER BY id");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    protected function getNextBatch(): int
    {
        $stmt = $this->db->query("SELECT MAX(batch) as max_batch FROM migrations");
        $result = $stmt->fetch();
        return ($result['max_batch'] ?? 0) + 1;
    }

    protected function recordMigration(string $migrationName, int $batch): void
    {
        $stmt = $this->db->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migrationName, $batch]);
    }

    protected function removeMigration(string $migrationName): void
    {
        $stmt = $this->db->prepare("DELETE FROM migrations WHERE migration = ?");
        $stmt->execute([$migrationName]);
    }

    protected function getClassNameFromFile(string $file): string
    {
        $content = file_get_contents($file);
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            return $matches[1];
        }
        throw new \Exception("Could not find class name in migration file: {$file}");
    }
}


