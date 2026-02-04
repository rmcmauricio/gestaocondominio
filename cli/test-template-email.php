<?php
/**
 * Script de teste para envio de email de template específico
 * 
 * Uso: php cli/test-template-email.php [template_key] [email_destino]
 * Exemplo: php cli/test-template-email.php welcome cantiflas@gmail.com
 */

require_once __DIR__ . '/../config.php';

use App\Core\EmailService;
use App\Models\EmailTemplate;

// Verificar argumentos
$templateKey = $argv[1] ?? 'welcome';
$toEmail = $argv[2] ?? 'cantiflas@gmail.com';

echo "========================================\n";
echo "Teste de Envio de Email de Template\n";
echo "========================================\n\n";

echo "Template: {$templateKey}\n";
echo "Email destino: {$toEmail}\n\n";

// Verificar se template existe
$emailTemplateModel = new EmailTemplate();
$template = $emailTemplateModel->findByKey($templateKey);

if (!$template) {
    echo "❌ ERRO: Template '{$templateKey}' não encontrado no banco de dados!\n";
    echo "\nTemplates disponíveis:\n";
    $allTemplates = $emailTemplateModel->getAll();
    foreach ($allTemplates as $t) {
        echo "  - {$t['template_key']} ({$t['name']})\n";
    }
    exit(1);
}

echo "✅ Template encontrado: {$template['name']}\n";

// Verificar base layout
$baseLayout = $emailTemplateModel->getBaseLayout();
if (!$baseLayout) {
    echo "❌ ERRO: Template base não encontrado!\n";
    exit(1);
}
echo "✅ Template base encontrado\n\n";

// Gerar dados de exemplo
$sampleData = [];
switch ($templateKey) {
    case 'welcome':
        $sampleData = [
            'nome' => 'João Silva',
            'verificationUrl' => BASE_URL . 'verify-email?token=test_token_123',
            'baseUrl' => BASE_URL
        ];
        break;
    case 'approval':
        $sampleData = [
            'nome' => 'João Silva',
            'baseUrl' => BASE_URL
        ];
        break;
    case 'password_reset':
        $sampleData = [
            'nome' => 'João Silva',
            'resetUrl' => BASE_URL . 'reset-password?token=test_token_123',
            'baseUrl' => BASE_URL
        ];
        break;
    default:
        $sampleData = [
            'nome' => 'João Silva',
            'baseUrl' => BASE_URL
        ];
}

echo "Dados de exemplo gerados:\n";
foreach ($sampleData as $key => $value) {
    echo "  {$key}: {$value}\n";
}
echo "\n";

// Renderizar template
echo "Renderizando template...\n";
$emailService = new EmailService();
$html = $emailService->renderTemplate($templateKey, $sampleData);
$text = $emailService->renderTextTemplate($templateKey, $sampleData);

if (empty($html)) {
    echo "❌ ERRO: Template renderizado está vazio!\n";
    echo "Verifique se o template base contém o placeholder {body}\n";
    exit(1);
}

echo "✅ Template renderizado com sucesso\n";
echo "   HTML length: " . strlen($html) . " bytes\n";
echo "   Text length: " . strlen($text) . " bytes\n\n";

// Preparar assunto
$subject = $template['subject'] ?? 'Email de Teste: ' . $template['name'];
foreach ($sampleData as $key => $value) {
    $subject = str_replace('{' . $key . '}', $value, $subject);
}

echo "Assunto: [TESTE] {$subject}\n\n";

// Enviar email
echo "Enviando email...\n";
$sent = $emailService->sendEmail(
    $toEmail,
    '[TESTE] ' . $subject,
    $html,
    $text,
    null,
    null
);

if ($sent) {
    echo "✅ Email enviado com sucesso!\n";
    
    // Verificar se foi redirecionado
    $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
    $isDevelopment = (strtolower($appEnv) === 'development');
    $devEmail = $_ENV['DEV_EMAIL'] ?? '';
    
    if ($isDevelopment && !empty($devEmail)) {
        echo "📧 Em desenvolvimento, o email foi redirecionado para: {$devEmail}\n";
        echo "   (destinatário original: {$toEmail})\n";
    } else {
        echo "📧 Verifique a caixa de entrada de: {$toEmail}\n";
    }
} else {
    echo "❌ Falha ao enviar email\n";
    echo "Verifique os logs em logs/php_error.log para mais detalhes\n";
    exit(1);
}

echo "\n========================================\n";
echo "Teste concluído\n";
echo "========================================\n";
