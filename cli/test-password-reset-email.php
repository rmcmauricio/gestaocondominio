<?php
/**
 * Script de teste para email de recuperação de senha
 * 
 * Uso: php cli/test-password-reset-email.php [email] [nome]
 * Exemplo: php cli/test-password-reset-email.php cantiflas@gmail.com "João Silva"
 */

require_once __DIR__ . '/../config.php';

use App\Core\EmailService;

// Verificar argumentos
$email = $argv[1] ?? 'cantiflas@gmail.com';
$nome = $argv[2] ?? 'Utilizador de Teste';

// Gerar um token de teste (não será válido, mas serve para testar o template)
$token = bin2hex(random_bytes(32));
$resetUrl = BASE_URL . 'reset-password?token=' . $token;

echo "========================================\n";
echo "Teste de Email de Recuperação de Senha\n";
echo "========================================\n\n";

echo "Enviando email de recuperação de senha...\n";
echo "Email: {$email}\n";
echo "Nome: {$nome}\n";
echo "URL de Reset: {$resetUrl}\n";
echo "----------------------------------------\n";

try {
    $emailService = new EmailService();
    
    $result = $emailService->sendPasswordResetEmail($email, $nome, $token);
    
    if ($result) {
        echo "✅ Email enviado com sucesso!\n";
        echo "\nVerifique a caixa de entrada (e spam) de: {$email}\n";
        echo "\nNota: O token gerado é apenas para teste do template.\n";
        echo "      Para testar o fluxo completo, use a funcionalidade de recuperação de senha no site.\n";
        exit(0);
    } else {
        echo "❌ Falha ao enviar email.\n";
        echo "Verifique os logs em: logs/php_error.log\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Erro ao enviar email:\n";
    echo "   " . $e->getMessage() . "\n";
    echo "\nVerifique os logs detalhados em: logs/php_error.log\n";
    exit(1);
}
