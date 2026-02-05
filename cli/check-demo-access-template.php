<?php
/**
 * Script para verificar se o template demo_access existe no banco de dados
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Models\EmailTemplate;

global $db;

if (!$db) {
    echo "Erro: Conexão à base de dados não disponível.\n";
    exit(1);
}

echo "========================================\n";
echo "Verificação do Template demo_access\n";
echo "========================================\n\n";

$emailTemplateModel = new EmailTemplate();

// Verificar se o template existe
$template = $emailTemplateModel->findByKey('demo_access');

if ($template) {
    echo "✅ Template 'demo_access' encontrado!\n\n";
    echo "Detalhes:\n";
    echo "  - Nome: " . ($template['name'] ?? 'N/A') . "\n";
    echo "  - Subject: " . ($template['subject'] ?? 'N/A') . "\n";
    echo "  - Ativo: " . ($template['is_active'] ? 'Sim' : 'Não') . "\n";
    echo "  - HTML Body (primeiros 200 caracteres): " . substr($template['html_body'] ?? '', 0, 200) . "...\n";
    echo "\n";
} else {
    echo "❌ Template 'demo_access' NÃO encontrado!\n\n";
    echo "Solução:\n";
    echo "1. Execute o seeder de templates de email:\n";
    echo "   php cli/seed.php EmailTemplatesSeeder\n\n";
    echo "2. Ou verifique se o template foi adicionado ao EmailTemplatesSeeder.php\n\n";
}

// Verificar base layout
$baseLayout = $emailTemplateModel->getBaseLayout();
if ($baseLayout) {
    echo "✅ Base layout encontrado!\n\n";
} else {
    echo "❌ Base layout NÃO encontrado!\n";
    echo "   Isso também pode causar problemas no envio de emails.\n\n";
}

// Verificar configuração de email
echo "========================================\n";
echo "Configuração de Email\n";
echo "========================================\n\n";

$appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? 'development');
$devEmail = $_ENV['DEV_EMAIL'] ?? '';

echo "APP_ENV: {$appEnv}\n";
echo "DEV_EMAIL: " . ($devEmail ?: 'NÃO CONFIGURADO') . "\n\n";

if (strtolower($appEnv) === 'development') {
    if (empty($devEmail)) {
        echo "⚠️  AVISO: Em desenvolvimento, emails são bloqueados se DEV_EMAIL não estiver configurado!\n";
        echo "   Configure DEV_EMAIL no arquivo .env\n\n";
    } else {
        echo "ℹ️  NOTA: Em desenvolvimento, todos os emails são redirecionados para:\n";
        echo "   {$devEmail}\n\n";
        echo "   Verifique este email para receber os emails de acesso à demo!\n\n";
    }
}

echo "SMTP_HOST: " . ($_ENV['SMTP_HOST'] ?? 'NÃO CONFIGURADO') . "\n";
echo "SMTP_USERNAME: " . ($_ENV['SMTP_USERNAME'] ?? 'NÃO CONFIGURADO') . "\n";
echo "SMTP_PASSWORD: " . (isset($_ENV['SMTP_PASSWORD']) && !empty($_ENV['SMTP_PASSWORD']) ? 'CONFIGURADO' : 'NÃO CONFIGURADO') . "\n";
echo "FROM_EMAIL: " . ($_ENV['FROM_EMAIL'] ?? 'NÃO CONFIGURADO') . "\n\n";
