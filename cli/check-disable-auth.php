<?php

require_once __DIR__ . '/../config.php';

echo "=== Verificação DISABLE_AUTH_REGISTRATION ===\n\n";

// Verificar .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/DISABLE_AUTH_REGISTRATION\s*=\s*(.+)/i', $envContent, $matches)) {
        echo "Valor no .env: " . trim($matches[1]) . "\n";
    } else {
        echo "DISABLE_AUTH_REGISTRATION não encontrado no .env\n";
    }
} else {
    echo ".env não encontrado\n";
}

// Verificar constante PHP
if (defined('DISABLE_AUTH_REGISTRATION')) {
    echo "Constante PHP definida: " . (DISABLE_AUTH_REGISTRATION ? 'true' : 'false') . "\n";
} else {
    echo "Constante PHP NÃO definida\n";
}

// Verificar variável global Twig
global $twig;
if (isset($twig)) {
    $globals = $twig->getGlobals();
    if (isset($globals['DISABLE_AUTH_REGISTRATION'])) {
        echo "Variável Twig global: " . ($globals['DISABLE_AUTH_REGISTRATION'] ? 'true' : 'false') . "\n";
    } else {
        echo "Variável Twig global NÃO definida\n";
    }
} else {
    echo "Twig não inicializado\n";
}

echo "\n=== Fim da verificação ===\n";
