<?php
/**
 * Bootstrap file for PHPUnit tests
 * Initializes the test environment
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

// Set test environment BEFORE loading config
$_ENV['APP_ENV'] = 'testing';
if (!defined('APP_ENV')) {
    define('APP_ENV', 'testing');
}

// Load autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration (will skip APP_ENV definition if already defined)
require_once __DIR__ . '/../config.php';

// Set test database configuration if not already set
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/test/');
}

// Initialize session for tests (only if not already started)
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Clear session before each test
$_SESSION = [];

// Mock global variables if needed
if (!isset($GLOBALS['twig'])) {
    // Create a minimal Twig environment for tests
    $loader = new \Twig\Loader\ArrayLoader([]);
    $GLOBALS['twig'] = new \Twig\Environment($loader, [
        'cache' => false,
        'debug' => true,
        'auto_reload' => true,
    ]);
}

// NEVER connect to database in tests - always use null
global $db;
$db = null;
