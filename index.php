<?php
session_start();
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
