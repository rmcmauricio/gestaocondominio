<?php
/**
 * Script de diagnóstico para problemas de email em produção
 * 
 * Uso: php cli/diagnose-email-production.php
 */

require_once __DIR__ . '/../config.php';

echo "========================================\n";
echo "Diagnóstico de Email - Produção\n";
echo "========================================\n\n";

// 1. Verificar ambiente
echo "1. Verificação do Ambiente:\n";
$appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'não definido');
echo "   APP_ENV: {$appEnv}\n";
$isProduction = (strtolower($appEnv) === 'production');
echo "   É Produção: " . ($isProduction ? 'SIM ✅' : 'NÃO ⚠️') . "\n";
if (!$isProduction) {
    echo "   ⚠️  AVISO: APP_ENV não está definido como 'production'\n";
    echo "   ⚠️  Emails podem estar sendo redirecionados para DEV_EMAIL\n";
}
echo "\n";

// 2. Verificar configurações SMTP
echo "2. Verificação das Configurações SMTP:\n";
$smtpHost = $_ENV['SMTP_HOST'] ?? '';
$smtpPort = $_ENV['SMTP_PORT'] ?? '';
$smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
$smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
$fromEmail = $_ENV['FROM_EMAIL'] ?? '';
$fromName = $_ENV['FROM_NAME'] ?? '';
$devEmail = $_ENV['DEV_EMAIL'] ?? '';

echo "   SMTP_HOST: " . ($smtpHost ? $smtpHost : '❌ NÃO CONFIGURADO') . "\n";
echo "   SMTP_PORT: " . ($smtpPort ? $smtpPort : '❌ NÃO CONFIGURADO') . "\n";
echo "   SMTP_USERNAME: " . ($smtpUsername ? $smtpUsername : '❌ NÃO CONFIGURADO') . "\n";
echo "   SMTP_PASSWORD: " . ($smtpPassword ? '***configurado***' : '❌ NÃO CONFIGURADO') . "\n";
echo "   FROM_EMAIL: " . ($fromEmail ? $fromEmail : '❌ NÃO CONFIGURADO') . "\n";
echo "   FROM_NAME: " . ($fromName ? $fromName : '❌ NÃO CONFIGURADO') . "\n";
echo "   DEV_EMAIL: " . ($devEmail ? $devEmail : 'não configurado') . "\n";

$missingConfig = [];
if (empty($smtpHost)) $missingConfig[] = 'SMTP_HOST';
if (empty($smtpPort)) $missingConfig[] = 'SMTP_PORT';
if (empty($smtpUsername)) $missingConfig[] = 'SMTP_USERNAME';
if (empty($smtpPassword)) $missingConfig[] = 'SMTP_PASSWORD';
if (empty($fromEmail)) $missingConfig[] = 'FROM_EMAIL';
if (empty($fromName)) $missingConfig[] = 'FROM_NAME';

if (!empty($missingConfig)) {
    echo "\n   ❌ ERRO: Configurações faltando: " . implode(', ', $missingConfig) . "\n";
} else {
    echo "\n   ✅ Todas as configurações SMTP estão presentes\n";
}
echo "\n";

// 3. Testar conectividade SMTP
echo "3. Teste de Conectividade SMTP:\n";
if (!empty($smtpHost) && !empty($smtpPort)) {
    $port = (int)$smtpPort;
    echo "   Tentando conectar a {$smtpHost}:{$port}...\n";
    
    $connection = @fsockopen($smtpHost, $port, $errno, $errstr, 10);
    if ($connection) {
        echo "   ✅ Conectividade OK: {$smtpHost}:{$port}\n";
        fclose($connection);
    } else {
        echo "   ❌ ERRO: Não foi possível conectar a {$smtpHost}:{$port}\n";
        echo "   Código de erro: {$errno}\n";
        echo "   Mensagem: {$errstr}\n";
        echo "\n   Possíveis causas:\n";
        echo "   - Servidor SMTP está offline\n";
        echo "   - Porta bloqueada pelo firewall\n";
        echo "   - Hostname incorreto\n";
    }
} else {
    echo "   ⚠️  Não é possível testar conectividade (SMTP_HOST ou SMTP_PORT não configurados)\n";
}
echo "\n";

// 4. Verificar redirecionamento em dev
echo "4. Verificação de Redirecionamento:\n";
if ($isProduction) {
    echo "   ✅ Ambiente de produção - emails serão enviados normalmente\n";
    if (!empty($devEmail)) {
        echo "   ℹ️  DEV_EMAIL está configurado mas não será usado em produção\n";
    }
} else {
    echo "   ⚠️  Ambiente de desenvolvimento\n";
    if (!empty($devEmail)) {
        echo "   ⚠️  AVISO: Todos os emails serão redirecionados para: {$devEmail}\n";
        echo "   ⚠️  Para enviar emails reais, defina APP_ENV=production\n";
    } else {
        echo "   ❌ ERRO: DEV_EMAIL não configurado - emails serão bloqueados!\n";
    }
}
echo "\n";

// 5. Verificar logs recentes
echo "5. Verificação de Logs Recentes:\n";
$logFile = __DIR__ . '/../logs/php_error.log';
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    $emailLogs = [];
    $lines = explode("\n", $logContent);
    $recentLines = array_slice($lines, -50); // Últimas 50 linhas
    
    foreach ($recentLines as $line) {
        if (stripos($line, 'EmailService') !== false || stripos($line, 'PHPMailer') !== false) {
            $emailLogs[] = $line;
        }
    }
    
    if (!empty($emailLogs)) {
        echo "   📋 Encontrados " . count($emailLogs) . " logs relacionados a email:\n";
        foreach (array_slice($emailLogs, -10) as $log) {
            echo "      " . substr($log, 0, 100) . (strlen($log) > 100 ? '...' : '') . "\n";
        }
    } else {
        echo "   ℹ️  Nenhum log recente de email encontrado\n";
    }
} else {
    echo "   ⚠️  Arquivo de log não encontrado: {$logFile}\n";
}
echo "\n";

// 6. Resumo e recomendações
echo "========================================\n";
echo "Resumo e Recomendações:\n";
echo "========================================\n\n";

$hasIssues = false;

if (!$isProduction) {
    echo "❌ PROBLEMA CRÍTICO: APP_ENV não está definido como 'production'\n";
    echo "   → Defina APP_ENV=production no arquivo .env\n\n";
    $hasIssues = true;
}

if (!empty($missingConfig)) {
    echo "❌ PROBLEMA CRÍTICO: Configurações SMTP faltando\n";
    echo "   → Configure as seguintes variáveis no .env: " . implode(', ', $missingConfig) . "\n\n";
    $hasIssues = true;
}

if (!$hasIssues) {
    echo "✅ Configurações básicas estão corretas\n";
    echo "\n";
    echo "Se ainda não está recebendo emails, verifique:\n";
    echo "1. Se as credenciais SMTP estão corretas\n";
    echo "2. Se o servidor SMTP permite envio do domínio atual\n";
    echo "3. Se os emails não estão indo para spam\n";
    echo "4. Os logs em logs/php_error.log para erros específicos\n";
    echo "\n";
    echo "Para testar o envio, execute:\n";
    echo "   php cli/test-email.php seu-email@exemplo.com\n";
}

echo "\n";
