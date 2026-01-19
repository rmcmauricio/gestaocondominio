<?php
/**
 * Script de debug para verificar o template de email
 */

require_once __DIR__ . '/../config.php';

use App\Core\EmailService;

echo "========================================\n";
echo "Debug do Template de Email\n";
echo "========================================\n\n";

$emailService = new EmailService();

// Test reflection to access private methods
$reflection = new ReflectionClass($emailService);

// Test getLogoBase64
$getLogoMethod = $reflection->getMethod('getLogoBase64');
$getLogoMethod->setAccessible(true);
$logoBase64 = $getLogoMethod->invoke($emailService);

echo "1. Logo Base64:\n";
if ($logoBase64) {
    echo "   ✅ Logo encontrado (tamanho: " . strlen($logoBase64) . " caracteres)\n";
    echo "   Primeiros 100 caracteres: " . substr($logoBase64, 0, 100) . "...\n";
} else {
    echo "   ❌ Logo não encontrado\n";
}
echo "\n";

// Test loadEmailTranslations
$loadTranslationsMethod = $reflection->getMethod('loadEmailTranslations');
$loadTranslationsMethod->setAccessible(true);
$loadTranslationsMethod->invoke($emailService);

$translationsProperty = $reflection->getProperty('emailTranslations');
$translationsProperty->setAccessible(true);
$translations = $translationsProperty->getValue($emailService);

echo "2. Traduções Carregadas:\n";
if (!empty($translations)) {
    echo "   ✅ " . count($translations) . " traduções carregadas\n";
    echo "   Chaves disponíveis:\n";
    $keys = array_keys($translations);
    foreach (array_slice($keys, 0, 10) as $key) {
        echo "      - {$key}\n";
    }
    if (count($keys) > 10) {
        echo "      ... e mais " . (count($keys) - 10) . " chaves\n";
    }
} else {
    echo "   ❌ Nenhuma tradução carregada\n";
}
echo "\n";

// Test t() method
$tMethod = $reflection->getMethod('t');
$tMethod->setAccessible(true);

echo "3. Teste de Traduções:\n";
$testKeys = [
    'password_reset_title',
    'password_reset_subtitle',
    'password_reset_hello',
    'password_reset_message',
    'password_reset_button',
];

foreach ($testKeys as $key) {
    $result = $tMethod->invoke($emailService, $key, ['nome' => 'Teste']);
    $status = ($result !== $key && !empty($result)) ? '✅' : '❌';
    echo "   {$status} {$key}: {$result}\n";
}
echo "\n";

// Test getPasswordResetEmailTemplate
$getTemplateMethod = $reflection->getMethod('getPasswordResetEmailTemplate');
$getTemplateMethod->setAccessible(true);
$template = $getTemplateMethod->invoke($emailService, 'Teste Utilizador', 'http://localhost/test?token=123');

echo "4. Template Gerado:\n";
echo "   Tamanho: " . strlen($template) . " caracteres\n";
echo "   Contém logo: " . (strpos($template, 'logo') !== false ? 'Sim' : 'Não') . "\n";
echo "   Contém traduções: " . (strpos($template, 'password_reset_') === false ? 'Sim' : 'Não') . "\n";
echo "\n";

// Check for untranslated keys
echo "5. Verificação de Chaves Não Traduzidas:\n";
$untranslated = [];
if (preg_match_all('/password_reset_\w+|welcome_footer_\w+/', $template, $matches)) {
    foreach ($matches[0] as $match) {
        if (!isset($translations[$match])) {
            $untranslated[] = $match;
        }
    }
}

if (empty($untranslated)) {
    echo "   ✅ Todas as chaves estão traduzidas\n";
} else {
    echo "   ❌ Chaves não traduzidas encontradas:\n";
    foreach (array_unique($untranslated) as $key) {
        echo "      - {$key}\n";
    }
}
echo "\n";

echo "========================================\n";
echo "Debug Concluído\n";
echo "========================================\n";
