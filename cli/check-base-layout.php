<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Models\EmailTemplate;

$emailTemplateModel = new EmailTemplate();
$baseLayout = $emailTemplateModel->getBaseLayout();

if ($baseLayout) {
    echo "Template base_layout encontrado!\n";
    echo "ID: " . $baseLayout['id'] . "\n";
    echo "Nome: " . $baseLayout['name'] . "\n";
    echo "\nVerificando estrutura HTML...\n";
    
    $html = $baseLayout['html_body'];
    
    // Verificar se tem logo-section
    if (strpos($html, 'logo-section') !== false) {
        echo "✓ Seção logo-section encontrada\n";
    } else {
        echo "✗ Seção logo-section NÃO encontrada\n";
    }
    
    // Verificar se logo está fora do header
    $logoSectionPos = strpos($html, 'logo-section');
    $headerPos = strpos($html, '<div class="header">');
    
    if ($logoSectionPos !== false && $headerPos !== false && $logoSectionPos < $headerPos) {
        echo "✓ Logo está ANTES do header (correto)\n";
    } else {
        echo "✗ Logo NÃO está antes do header\n";
    }
    
    // Verificar se header só tem o título
    if (strpos($html, '<div class="header">') !== false) {
        $headerStart = strpos($html, '<div class="header">');
        $headerEnd = strpos($html, '</div>', $headerStart);
        $headerContent = substr($html, $headerStart, $headerEnd - $headerStart);
        
        if (strpos($headerContent, 'logo-container') === false && strpos($headerContent, '{subject}') !== false) {
            echo "✓ Header contém apenas o título (correto)\n";
        } else {
            echo "✗ Header contém logo ou não contém título\n";
        }
    }
    
    echo "\nPrimeiras 500 caracteres do HTML:\n";
    echo substr($html, 0, 500) . "...\n";
} else {
    echo "ERRO: Template base_layout não encontrado!\n";
}
