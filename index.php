<?php
// Enable output buffering to prevent warnings/errors from corrupting JSON responses
if (!ob_get_level()) {
    ob_start();
}

/**
 * Get base path for redirects (works before config.php is loaded)
 * @return string Base path (e.g., '/predio' or '')
 */
function getBasePathForRedirect(): string {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    $basePath = str_replace(basename($scriptName), '', $scriptName);
    return rtrim($basePath, '/');
}

// Session: 24 hours after last user interaction (single source of truth)
define('SESSION_INACTIVITY_SECONDS', 86400); // 24 * 3600

// Configure secure session settings before starting session
if (session_status() === PHP_SESSION_NONE) {
    // Set session garbage collection max lifetime to 24 hours (must be before session_start)
    ini_set('session.gc_maxlifetime', (string) SESSION_INACTIVITY_SECONDS);

    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => SESSION_INACTIVITY_SECONDS,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    // Regenerate session ID periodically to prevent session fixation
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }

    // Security: Session integrity check - verify session fingerprint
    if (isset($_SESSION['user'])) {
        // Create session fingerprint from IP and User Agent
        $currentIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        if (strpos($currentIp, ',') !== false) {
            $currentIp = trim(explode(',', $currentIp)[0]);
        }
        $currentUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $currentFingerprint = hash('sha256', $currentIp . $currentUserAgent);

        // Check if fingerprint exists and matches
        if (!isset($_SESSION['fingerprint'])) {
            // First time - store fingerprint
            $_SESSION['fingerprint'] = $currentFingerprint;
        } elseif ($_SESSION['fingerprint'] !== $currentFingerprint) {
            // Fingerprint changed - possible session hijacking
            // Log security event
            error_log("SECURITY WARNING: Session fingerprint mismatch for user ID: " . ($_SESSION['user']['id'] ?? 'unknown'));

            // Destroy session and force re-login
            session_destroy();
            session_start();
            $_SESSION['login_error'] = 'Sessão inválida detectada. Por favor, faça login novamente.';

            // Redirect to login if not already there
            if (strpos($_SERVER['REQUEST_URI'] ?? '', '/login') === false) {
                header('Location: ' . getBasePathForRedirect() . '/login');
                exit;
            }
        }

        // Initialize last_activity if not set (for existing sessions)
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
        }

        // Timeout: 24 hours after last activity
        if (time() - $_SESSION['last_activity'] > SESSION_INACTIVITY_SECONDS) {
            session_destroy();
            session_start();
            $_SESSION['login_error'] = 'Sua sessão expirou devido à inatividade. Por favor, faça login novamente.';
            header('Location: ' . getBasePathForRedirect() . '/login');
            exit;
        }

        $_SESSION['last_activity'] = time();

        // Sliding expiration: refresh cookie so it expires 24h from THIS request (not from login)
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + SESSION_INACTIVITY_SECONDS,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]);
    }
}

// Mobile version: when user lands on a page with ?from_mobile=1 (e.g. from /m/occurrences redirect), use mobile template for subsequent pages
if (isset($_GET['from_mobile']) && $_GET['from_mobile'] === '1') {
    $_SESSION['mobile_version'] = true;
}

require __DIR__ . '/vendor/autoload.php';

use App\Core\Router;

require 'config.php';      // Se tiveres BASE_URL, etc.

if (MAINTENANCE_MODE) {
    header('Location: maintenance.html');
}

// autoload básico para core, controllers e models
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/app/';

    $relative_class = substr($class, strlen($prefix));
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// autoload para addons (Addons\HelpChatbot\Controllers\X -> addons/HelpChatbot/Controllers/X.php)
spl_autoload_register(function ($class) {
    $prefix = 'Addons\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $base_dir = __DIR__ . '/addons/';
    $relative_class = substr($class, strlen($prefix));
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

$router = new Router();

// Load routes file if it exists
$routesFile = __DIR__ . '/routes.php';
if (file_exists($routesFile)) {
    require $routesFile;
} else {
    // Default route if routes.php doesn't exist
    $router->get('/', function() {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MVC Framework</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        h1 { color: #333; }
        .info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 20px 0; }
        code { background: #e8e8e8; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Welcome to MVC Framework</h1>
    <div class="info">
        <p><strong>Framework is running successfully!</strong></p>
        <p>To get started, create a <code>routes.php</code> file in the root directory and define your routes.</p>
        <p>Example:</p>
        <pre><code>$router->get(\'/\', \'App\\Controllers\\ExampleController@index\');</code></pre>
    </div>
</body>
</html>';
    });
}

$router->dispatch();
