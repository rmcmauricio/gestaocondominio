<?php
/**
 * Database connection file
 * 
 * NOTE: This file is kept for backward compatibility.
 * The actual database connection is handled in config.php.
 * 
 * If $db is not already set by config.php, this file will attempt
 * to create a connection using the $config array from config.php.
 */

// Only create connection if $db is not already set by config.php
if (!isset($db) || $db === null) {
    // Check if $config is available (from config.php)
    if (!isset($config)) {
        // If config.php wasn't loaded, we can't connect
        $db = null;
    } else {
        try {
            $dbname = $config['dbname'] ?? '';
            $dbhost = $config['host'] ?? 'localhost';
            
            // Handle host with port (e.g., localhost:33066)
            if (strpos($dbhost, ':') !== false) {
                list($dbhost, $dbport) = explode(':', $dbhost, 2);
                $dsn = "mysql:dbname=" . $dbname . ";host=" . $dbhost . ";port=" . $dbport;
            } else {
                $dsn = "mysql:dbname=" . $dbname . ";host=" . $dbhost;
            }
            
            $dbuser = $config['dbuser'] ?? 'root';
            $dbpass = $config['dbpass'] ?? '';
            
            if (!empty($dbname)) {
                $db = new PDO(
                    $dsn,
                    $dbuser,
                    $dbpass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                    ]
                );
            } else {
                $db = null;
            }
        } catch(PDOException $e) {
            // Log error but don't echo (security: don't expose connection details)
            error_log("Database connection error: " . $e->getMessage());
            $db = null;
        }
    }
}
