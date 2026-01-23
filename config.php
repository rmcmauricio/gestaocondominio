<?php
// Initialize config array
$config = [];

// Load environment variables
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $config[$key] = $value;
            // Also populate $_ENV for EmailService and other components
            $_ENV[$key] = $value;
        }
    }
}

// Carregar autoloader primeiro
require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Utils;
use App\Core\Cache;

global $utils;
$utils = new Utils();
$cache = new Cache();
global $userInfo;
global $db;

// Framework version
define("VERSION", "1.0.11");

// Application environment
if (!defined('APP_ENV')) {
    define('APP_ENV', $config['APP_ENV'] ?? 'development');
}

// Configure error handling based on environment
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

if (APP_ENV === 'production') {
    // Production: Hide errors from users, log everything
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', $logDir . '/php_error.log');
    error_reporting(E_ALL); // Still log all errors
} else {
    // Development: Show errors but also log them
    $logFile = $logDir . '/php_error.log';
    ini_set('log_errors', '1');
    ini_set('error_log', $logFile);
    // Display errors in development for debugging
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

// Base path configuration (set in .env or default to empty)
define('BASE_PATH', $config['BASE_PATH'] ?? '');

// Detect current domain and set BASE_URL accordingly
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = BASE_PATH ? BASE_PATH . '/' : '';
$baseUrl = $protocol . '://' . $host . '/' . $basePath;

define('BASE_URL', $baseUrl);

// Database connection (optional - only connects if configured)
// In testing environment, never connect to database
$db = null;
if (defined('APP_ENV') && APP_ENV === 'testing') {
    // Skip database connection in tests
    $db = null;
} else {
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

    // Only attempt database connection if dbname is configured
    if (!empty($dbname)) {
        try {
            $db = new PDO(
                $dsn,
                $dbuser,
                $dbpass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch(PDOException $e) {
            // Log error but don't stop execution
            error_log("Database connection error: " . $e->getMessage());
            $db = null;
        }
    }
}

// Twig Template Engine Configuration
$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/app/Views');

// Create Twig environment
$twig = new \Twig\Environment($loader, [
    'cache' => ($config['APP_ENV'] ?? 'development') === 'production' ? __DIR__ . '/cache/twig' : false,
    'debug' => ($config['APP_ENV'] ?? 'development') !== 'production',
    'auto_reload' => ($config['APP_ENV'] ?? 'development') !== 'production',
]);

// Add debug extension in development
if (($config['APP_ENV'] ?? 'development') !== 'production') {
    $twig->addExtension(new \Twig\Extension\DebugExtension());
}

// Add global variables to Twig
$twig->addGlobal('BASE_URL', BASE_URL);
$twig->addGlobal('VERSION', VERSION);

// Breadcrumbs: needs_context to receive full Twig context
$twig->addFunction(new \Twig\TwigFunction('get_breadcrumbs', [\App\Services\BreadcrumbService::class, 'getBreadcrumbs'], ['needs_context' => true]));

// Add HTML sanitization filter for safe HTML rendering
$twig->addFilter(new \Twig\TwigFilter('sanitize_html', function($html) {
    return \App\Core\Security::sanitizeHtml($html);
}, ['is_safe' => ['html']]));

// Make Twig available globally
$GLOBALS['twig'] = $twig;

// Optional: Define additional constants from .env
if (isset($config['WEBSOCKET_URL'])) {
    define('WEBSOCKET_URL', $config['WEBSOCKET_URL']);
}

if (isset($config['WEBSOCKET_AUTH_KEY'])) {
    define('WEBSOCKET_AUTH_KEY', $config['WEBSOCKET_AUTH_KEY']);
}

if (isset($config['RECAPTCHA_SITE_KEY'])) {
    define('RECAPTCHA_SITE_KEY', $config['RECAPTCHA_SITE_KEY']);
}

if (isset($config['RECAPTCHA_SECRET'])) {
    define('RECAPTCHA_SECRET', $config['RECAPTCHA_SECRET']);
}

// Maintenance mode
define('MAINTENANCE_MODE', isset($config['MAINTENANCE_MODE']) && $config['MAINTENANCE_MODE'] === 'true');
