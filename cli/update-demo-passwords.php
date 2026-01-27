<?php
/**
 * Update Demo User Passwords
 * 
 * Updates all demo users' passwords to 'demo'
 * 
 * Usage: php cli/update-demo-passwords.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Core\Security;

global $db;

if (!$db) {
    echo "Erro: Conexão à base de dados não disponível.\n";
    exit(1);
}

echo "========================================\n";
echo "Atualizar Senhas dos Utilizadores Demo\n";
echo "========================================\n\n";

try {
    // Get all demo users
    $stmt = $db->prepare("SELECT id, email, name FROM users WHERE is_demo = TRUE");
    $stmt->execute();
    $users = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "Nenhum utilizador demo encontrado.\n";
        exit(0);
    }
    
    echo "Encontrados " . count($users) . " utilizador(es) demo.\n\n";
    
    $hashedPassword = Security::hashPassword('demo');
    $updated = 0;
    
    foreach ($users as $user) {
        $updateStmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
        $updateStmt->execute([
            ':password' => $hashedPassword,
            ':id' => $user['id']
        ]);
        
        $updated++;
        echo "✓ Senha atualizada para: {$user['email']} ({$user['name']})\n";
    }
    
    echo "\n========================================\n";
    echo "Concluído! {$updated} utilizador(es) atualizado(s).\n";
    echo "========================================\n";
    echo "\nCredenciais de acesso:\n";
    echo "- Email: qualquer email dos utilizadores demo\n";
    echo "- Password: demo\n";
    echo "\n";
    
} catch (\Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
    exit(1);
}
