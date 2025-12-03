<?php
session_start();
require __DIR__ . '/vendor/autoload.php';
require 'config.php';

use App\Core\Router;

$router = new Router();
require 'routes.php';

echo "<h1>Router Debug</h1>";
echo "<h2>Server Variables:</h2>";
echo "<pre>";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'not set') . "\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set') . "\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n";
echo "BASE_PATH: " . (defined('BASE_PATH') ? BASE_PATH : 'not defined') . "\n";
echo "</pre>";

echo "<h2>Registered Routes:</h2>";
echo "<pre>";
print_r($router->getRoutes());
echo "</pre>";

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
echo "<h2>URI Processing:</h2>";
echo "<pre>";
echo "Original URI: $uri\n";

// Simulate router processing
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if (!empty($scriptName)) {
    $subfolder = str_replace('\\', '/', dirname($scriptName));
    $subfolder = trim($subfolder, '/');
    echo "Subfolder detected: $subfolder\n";
    
    if (!empty($subfolder) && strpos($uri, '/' . $subfolder) === 0) {
        $processedUri = substr($uri, strlen('/' . $subfolder));
        echo "Processed URI: $processedUri\n";
    } else {
        echo "Processed URI: $uri (no change)\n";
    }
}
echo "</pre>";

