<?php
/**
 * Script de teste para envio de emails
 * 
 * Uso: php cli/test-email.php [email_destino]
 * Exemplo: php cli/test-email.php cantiflas@gmail.com
 */

require_once __DIR__ . '/../config.php';

use App\Core\EmailService;

// Verificar se o email foi fornecido como argumento
$toEmail = $argv[1] ?? 'cantiflas@gmail.com';

echo "========================================\n";
echo "Teste de Envio de Email\n";
echo "========================================\n\n";

// Verificar configurações do .env
echo "Verificando configurações...\n";
$smtpHost = $_ENV['SMTP_HOST'] ?? 'não configurado';
$smtpPort = $_ENV['SMTP_PORT'] ?? 'não configurado';
$smtpUsername = $_ENV['SMTP_USERNAME'] ?? 'não configurado';
$fromEmail = $_ENV['FROM_EMAIL'] ?? 'não configurado';
$fromName = $_ENV['FROM_NAME'] ?? 'não configurado';

echo "SMTP_HOST: {$smtpHost}\n";
echo "SMTP_PORT: {$smtpPort}\n";
echo "SMTP_USERNAME: {$smtpUsername}\n";
echo "FROM_EMAIL: {$fromEmail}\n";
echo "FROM_NAME: {$fromName}\n";
echo "SMTP_PASSWORD: " . (isset($_ENV['SMTP_PASSWORD']) && !empty($_ENV['SMTP_PASSWORD']) ? '***configurado***' : 'não configurado') . "\n";
echo "\n";

// Verificar se as configurações essenciais estão presentes
if (empty($_ENV['SMTP_HOST']) || empty($_ENV['SMTP_USERNAME']) || empty($_ENV['SMTP_PASSWORD'])) {
    echo "ERRO: Configurações de email incompletas no arquivo .env\n";
    echo "Por favor, configure:\n";
    echo "  - SMTP_HOST\n";
    echo "  - SMTP_PORT\n";
    echo "  - SMTP_USERNAME\n";
    echo "  - SMTP_PASSWORD\n";
    echo "  - FROM_EMAIL\n";
    echo "  - FROM_NAME\n";
    exit(1);
}

// Testar conectividade com o servidor SMTP
echo "Testando conectividade com o servidor SMTP...\n";
$host = $_ENV['SMTP_HOST'];
$port = $_ENV['SMTP_PORT'] ?? 587;

$connection = @fsockopen($host, $port, $errno, $errstr, 5);
if ($connection) {
    echo "✅ Conectividade OK: {$host}:{$port}\n";
    fclose($connection);
} else {
    echo "⚠️  Aviso: Não foi possível conectar a {$host}:{$port}\n";
    echo "   Erro: {$errstr} ({$errno})\n";
    echo "   Isso pode ser normal se o servidor requer autenticação antes de aceitar conexões.\n";
}
echo "\n";

echo "Enviando email de teste para: {$toEmail}\n";
echo "----------------------------------------\n";

try {
    $emailService = new EmailService();
    
    $subject = "Teste de Email - " . date('d/m/Y H:i:s');
    
    $html = "
    <!DOCTYPE html>
    <html lang='pt'>
    <head>
        <meta charset='UTF-8'>
        <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
            .success-box { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .info-box { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .footer { text-align: center; margin-top: 30px; color: #666; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>✅ Email de Teste</h1>
                <p>Sistema de Gestão de Condomínio</p>
            </div>
            <div class='content'>
                <h2>Olá!</h2>
                <p>Este é um email de teste para verificar se o sistema de envio de emails está funcionando corretamente.</p>
                
                <div class='success-box'>
                    <strong>✅ Status:</strong> Email enviado com sucesso!<br>
                    <strong>📅 Data/Hora:</strong> " . date('d/m/Y H:i:s') . "<br>
                    <strong>📧 Destinatário:</strong> {$toEmail}
                </div>
                
                <div class='info-box'>
                    <strong>ℹ️ Informações:</strong><br>
                    Se você recebeu este email, significa que a configuração do servidor SMTP está correta e funcionando.
                </div>
                
                <p>Este é um email automático de teste. Por favor, não responda.</p>
            </div>
            <div class='footer'>
                <p>Sistema de Gestão de Condomínio</p>
                <p>Este é um email de teste automático</p>
            </div>
        </div>
    </body>
    </html>";
    
    $text = "
Email de Teste - Sistema de Gestão de Condomínio

Olá!

Este é um email de teste para verificar se o sistema de envio de emails está funcionando corretamente.

Status: Email enviado com sucesso!
Data/Hora: " . date('d/m/Y H:i:s') . "
Destinatário: {$toEmail}

Se você recebeu este email, significa que a configuração do servidor SMTP está correta e funcionando.

Este é um email automático de teste. Por favor, não responda.

---
Sistema de Gestão de Condomínio
";
    
    $result = $emailService->sendEmail($toEmail, $subject, $html, $text);
    
    if ($result) {
        echo "✅ Email enviado com sucesso!\n";
        echo "\nVerifique a caixa de entrada (e spam) de: {$toEmail}\n";
        echo "\nDica: Se não receber o email, verifique:\n";
        echo "  1. A pasta de spam/lixo eletrônico\n";
        echo "  2. Se o servidor SMTP está configurado corretamente\n";
        echo "  3. Se o firewall permite conexões SMTP na porta {$port}\n";
        exit(0);
    } else {
        echo "❌ Falha ao enviar email.\n";
        echo "\nPossíveis causas:\n";
        echo "  1. Servidor SMTP não acessível ou incorreto\n";
        echo "  2. Credenciais incorretas (usuário/senha)\n";
        echo "  3. Porta bloqueada pelo firewall\n";
        echo "  4. Servidor requer autenticação diferente\n";
        echo "\nVerifique os logs detalhados em: logs/php_error.log\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Erro ao enviar email:\n";
    echo "   " . $e->getMessage() . "\n";
    echo "\nVerifique os logs detalhados em: logs/php_error.log\n";
    echo "\nDica: Em modo de desenvolvimento, o EmailService fornece logs detalhados.\n";
    exit(1);
}
