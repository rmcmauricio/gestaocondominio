<?php
/**
 * Script de diagnóstico de configuração de email
 * 
 * Uso: php cli/test-email-config.php
 */

require_once __DIR__ . '/../config.php';

echo "========================================\n";
echo "Diagnóstico de Configuração de Email\n";
echo "========================================\n\n";

// Verificar configurações do .env
echo "1. Verificando configurações do .env...\n";
$smtpHost = $_ENV['SMTP_HOST'] ?? 'não configurado';
$smtpPort = $_ENV['SMTP_PORT'] ?? 'não configurado';
$smtpUsername = $_ENV['SMTP_USERNAME'] ?? 'não configurado';
$smtpPassword = isset($_ENV['SMTP_PASSWORD']) && !empty($_ENV['SMTP_PASSWORD']) ? '***configurado***' : 'não configurado';
$fromEmail = $_ENV['FROM_EMAIL'] ?? 'não configurado';
$fromName = $_ENV['FROM_NAME'] ?? 'não configurado';
$appEnv = $_ENV['APP_ENV'] ?? 'não configurado';
$devEmail = $_ENV['DEV_EMAIL'] ?? 'não configurado';

echo "   APP_ENV: {$appEnv}\n";
echo "   SMTP_HOST: {$smtpHost}\n";
echo "   SMTP_PORT: {$smtpPort}\n";
echo "   SMTP_USERNAME: {$smtpUsername}\n";
echo "   SMTP_PASSWORD: {$smtpPassword}\n";
echo "   FROM_EMAIL: {$fromEmail}\n";
echo "   FROM_NAME: {$fromName}\n";
echo "   DEV_EMAIL: {$devEmail}\n";
echo "\n";

// Verificar se está em desenvolvimento
$isDevelopment = (strtolower($appEnv) === 'development');
if ($isDevelopment) {
    echo "⚠️  AMBIENTE DE DESENVOLVIMENTO DETECTADO\n";
    if (empty($devEmail) || $devEmail === 'não configurado') {
        echo "   ❌ DEV_EMAIL não configurado!\n";
        echo "   Em desenvolvimento, todos os emails serão BLOQUEADOS se DEV_EMAIL não estiver configurado.\n";
        echo "   Configure DEV_EMAIL no arquivo .env para receber emails de teste.\n\n";
    } else {
        echo "   ✅ DEV_EMAIL configurado: {$devEmail}\n";
        echo "   Todos os emails serão redirecionados para este endereço.\n\n";
    }
}

// Verificar se as configurações essenciais estão presentes
echo "2. Verificando configurações essenciais...\n";
$missing = [];
if (empty($smtpHost) || $smtpHost === 'não configurado') {
    $missing[] = 'SMTP_HOST';
}
if (empty($smtpPort) || $smtpPort === 'não configurado') {
    $missing[] = 'SMTP_PORT';
}
if (empty($smtpUsername) || $smtpUsername === 'não configurado') {
    $missing[] = 'SMTP_USERNAME';
}
if (empty($_ENV['SMTP_PASSWORD'])) {
    $missing[] = 'SMTP_PASSWORD';
}
if (empty($fromEmail) || $fromEmail === 'não configurado') {
    $missing[] = 'FROM_EMAIL';
}

if (!empty($missing)) {
    echo "   ❌ Configurações faltando: " . implode(', ', $missing) . "\n";
    echo "   Configure estas variáveis no arquivo .env\n\n";
} else {
    echo "   ✅ Todas as configurações essenciais estão presentes\n\n";
}

// Testar conectividade com o servidor SMTP
echo "3. Testando conectividade com o servidor SMTP...\n";
if ($smtpHost !== 'não configurado' && $smtpPort !== 'não configurado') {
    $host = $smtpHost;
    $port = (int)$smtpPort;
    
    echo "   Tentando conectar a {$host}:{$port}...\n";
    $connection = @fsockopen($host, $port, $errno, $errstr, 5);
    if ($connection) {
        echo "   ✅ Conectividade OK: {$host}:{$port}\n";
        fclose($connection);
    } else {
        echo "   ❌ Não foi possível conectar a {$host}:{$port}\n";
        echo "   Erro: {$errstr} (Código: {$errno})\n";
        echo "   Verifique se o servidor SMTP está acessível e se a porta está correta.\n";
    }
} else {
    echo "   ⚠️  Não é possível testar conectividade (SMTP_HOST ou SMTP_PORT não configurados)\n";
}
echo "\n";

// Tentar enviar um email de teste
echo "4. Tentando enviar email de teste...\n";
if (!empty($missing)) {
    echo "   ⚠️  Pulando teste de envio (configurações incompletas)\n";
} else {
    try {
        $emailService = new \App\Core\EmailService();
        $testEmail = !empty($devEmail) && $devEmail !== 'não configurado' ? $devEmail : $smtpUsername;
        
        echo "   Enviando email de teste para: {$testEmail}\n";
        
        $html = "<h1>Email de Teste</h1><p>Este é um email de teste enviado pelo script de diagnóstico.</p>";
        $text = "Email de Teste\n\nEste é um email de teste enviado pelo script de diagnóstico.";
        
        $sent = $emailService->sendEmail(
            $testEmail,
            '[TESTE] Diagnóstico de Email',
            $html,
            $text,
            null,
            null
        );
        
        if ($sent) {
            echo "   ✅ Email enviado com sucesso!\n";
            if ($isDevelopment && !empty($devEmail) && $devEmail !== 'não configurado') {
                echo "   📧 Verifique a caixa de entrada de: {$devEmail}\n";
            } else {
                echo "   📧 Verifique a caixa de entrada de: {$testEmail}\n";
            }
        } else {
            echo "   ❌ Falha ao enviar email\n";
            echo "   Verifique os logs em logs/php_error.log para mais detalhes\n";
        }
    } catch (Exception $e) {
        echo "   ❌ Erro ao tentar enviar email: " . $e->getMessage() . "\n";
    }
}
echo "\n";

echo "========================================\n";
echo "Diagnóstico concluído\n";
echo "========================================\n";
echo "\n";
echo "DICAS:\n";
echo "- Em desenvolvimento, configure DEV_EMAIL no .env para receber todos os emails\n";
echo "- Verifique os logs em logs/php_error.log para erros detalhados\n";
echo "- Certifique-se de que as credenciais SMTP estão corretas\n";
echo "- Alguns provedores de email (como Gmail) requerem 'senhas de app' em vez da senha normal\n";
echo "\n";
