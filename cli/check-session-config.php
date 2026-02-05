<?php

// Simular a mesma configuração do index.php
if (session_status() === PHP_SESSION_NONE) {
    // Set session garbage collection max lifetime to 24 hours (86400 seconds)
    // This must be set BEFORE session_start() to take effect
    ini_set('session.gc_maxlifetime', 86400);
    
    // Set secure session cookie parameters
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 86400, // Session cookie expires after 24 hours
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps, // Only send over HTTPS in production
        'httponly' => true, // Prevent JavaScript access
        'samesite' => 'Lax' // CSRF protection
    ]);

    session_start();
}

echo "=== Configurações de Sessão ===\n\n";

echo "session.gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . " segundos (" . (ini_get('session.gc_maxlifetime') / 3600) . " horas)\n";
echo "session.cookie_lifetime: " . ini_get('session.cookie_lifetime') . " segundos (" . (ini_get('session.cookie_lifetime') / 3600) . " horas)\n";
echo "session.gc_probability: " . ini_get('session.gc_probability') . "\n";
echo "session.gc_divisor: " . ini_get('session.gc_divisor') . "\n";

$cookieParams = session_get_cookie_params();
echo "\n=== Parâmetros do Cookie da Sessão ===\n";
echo "lifetime: " . $cookieParams['lifetime'] . " segundos (" . ($cookieParams['lifetime'] / 3600) . " horas)\n";
echo "path: " . $cookieParams['path'] . "\n";
echo "domain: " . ($cookieParams['domain'] ?: '(vazio)') . "\n";
echo "secure: " . ($cookieParams['secure'] ? 'true' : 'false') . "\n";
echo "httponly: " . ($cookieParams['httponly'] ? 'true' : 'false') . "\n";
echo "samesite: " . ($cookieParams['samesite'] ?: 'Lax') . "\n";

if (isset($_SESSION['user'])) {
    echo "\n=== Informações da Sessão Atual ===\n";
    echo "User ID: " . ($_SESSION['user']['id'] ?? 'N/A') . "\n";
    echo "Session created: " . (isset($_SESSION['created']) ? date('Y-m-d H:i:s', $_SESSION['created']) : 'N/A') . "\n";
    echo "Last activity: " . (isset($_SESSION['last_activity']) ? date('Y-m-d H:i:s', $_SESSION['last_activity']) : 'N/A') . "\n";
    if (isset($_SESSION['last_activity'])) {
        $timeSinceLastActivity = time() - $_SESSION['last_activity'];
        echo "Tempo desde última atividade: " . $timeSinceLastActivity . " segundos (" . round($timeSinceLastActivity / 60, 2) . " minutos)\n";
        echo "Tempo restante até expiração: " . (86400 - $timeSinceLastActivity) . " segundos (" . round((86400 - $timeSinceLastActivity) / 3600, 2) . " horas)\n";
    }
} else {
    echo "\n=== Nenhum usuário logado ===\n";
}

echo "\n=== Recomendações ===\n";
if (ini_get('session.gc_maxlifetime') < 86400) {
    echo "⚠️  AVISO: session.gc_maxlifetime está menor que 24 horas. Configure ini_set('session.gc_maxlifetime', 86400) antes de session_start().\n";
} else {
    echo "✓ session.gc_maxlifetime está configurado corretamente (>= 24 horas)\n";
}

if ($cookieParams['lifetime'] < 86400) {
    echo "⚠️  AVISO: Cookie lifetime está menor que 24 horas.\n";
} else {
    echo "✓ Cookie lifetime está configurado corretamente (>= 24 horas)\n";
}

echo "\n";
