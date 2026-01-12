<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../database.php';

use App\Models\Assembly;
use App\Models\Condominium;
use App\Services\PdfService;
use App\Core\EmailService;
use App\Services\NotificationService;

/**
 * CLI command to send assembly convocations automatically
 * 
 * Usage: php cli/send-assembly-convocations.php [days_before]
 * 
 * Default: sends convocations 7 days before assembly date
 */

$daysBefore = isset($argv[1]) ? (int)$argv[1] : 7;

echo "=== Envio Automático de Convocatórias ===\n";
echo "Dias antes da assembleia: {$daysBefore}\n\n";

$assemblyModel = new Assembly();
$condominiumModel = new Condominium();
$pdfService = new PdfService();
$emailService = new EmailService();
$notificationService = new NotificationService();

// Get assemblies scheduled for X days from now
$targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));
$startDate = $targetDate . ' 00:00:00';
$endDate = $targetDate . ' 23:59:59';

global $db;
$stmt = $db->prepare("
    SELECT a.* 
    FROM assemblies a
    WHERE a.status = 'scheduled'
    AND a.scheduled_date >= :start_date
    AND a.scheduled_date <= :end_date
    AND (a.convocation_sent_at IS NULL OR a.convocation_sent_at < DATE_SUB(NOW(), INTERVAL 1 DAY))
");

$stmt->execute([
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);

$assemblies = $stmt->fetchAll();

if (empty($assemblies)) {
    echo "Nenhuma assembleia encontrada para enviar convocatórias.\n";
    exit(0);
}

echo "Encontradas " . count($assemblies) . " assembleia(s) para processar.\n\n";

$totalSent = 0;
$totalErrors = 0;

foreach ($assemblies as $assembly) {
    echo "Processando: {$assembly['title']} (ID: {$assembly['id']})\n";
    
    $condominium = $condominiumModel->findById($assembly['condominium_id']);
    if (!$condominium) {
        echo "  ERRO: Condomínio não encontrado.\n";
        $totalErrors++;
        continue;
    }
    
    // Get all condominium users
    $stmt = $db->prepare("
        SELECT DISTINCT u.*, cu.fraction_id
        FROM users u
        INNER JOIN condominium_users cu ON cu.user_id = u.id
        WHERE cu.condominium_id = :condominium_id
    ");
    $stmt->execute([':condominium_id' => $assembly['condominium_id']]);
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "  AVISO: Nenhum utilizador encontrado para este condomínio.\n";
        continue;
    }
    
    // Generate PDF
    try {
        $pdfFilename = $pdfService->generateConvocation($assembly['id'], $assembly, []);
        $pdfPath = __DIR__ . '/../storage/documents/' . $pdfFilename;
        
        if (!file_exists($pdfPath)) {
            echo "  ERRO: PDF não foi gerado corretamente.\n";
            $totalErrors++;
            continue;
        }
    } catch (\Exception $e) {
        echo "  ERRO ao gerar PDF: " . $e->getMessage() . "\n";
        $totalErrors++;
        continue;
    }
    
    // Send emails
    $sent = 0;
    $failed = 0;
    
    foreach ($users as $user) {
        try {
            $emailBody = getConvocationEmailBody($assembly, $condominium);
            
            $emailService->send(
                $user['email'],
                'Convocatória de Assembleia: ' . $assembly['title'],
                $emailBody,
                null,
                $pdfPath
            );
            
            $sent++;
            
            // Create notification
            $notificationService->createNotification(
                $user['id'],
                $assembly['condominium_id'],
                'assembly',
                'Nova Assembleia Agendada',
                'Uma assembleia foi agendada: ' . $assembly['title'],
                BASE_URL . 'condominiums/' . $assembly['condominium_id'] . '/assemblies/' . $assembly['id']
            );
            
        } catch (\Exception $e) {
            $failed++;
            error_log("Email error for user {$user['id']}: " . $e->getMessage());
        }
    }
    
    // Mark as sent
    $assemblyModel->markConvocationSent($assembly['id']);
    
    echo "  ✓ Enviadas: {$sent} | Falhadas: {$failed}\n";
    $totalSent += $sent;
    $totalErrors += $failed;
    
    echo "\n";
}

echo "=== Resumo ===\n";
echo "Total de emails enviados: {$totalSent}\n";
echo "Total de erros: {$totalErrors}\n";
echo "\nConcluído!\n";

function getConvocationEmailBody(array $assembly, array $condominium): string
{
    $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
    $time = date('H:i', strtotime($assembly['scheduled_date']));
    $type = $assembly['type'] === 'extraordinary' ? 'Extraordinária' : 'Ordinária';
    $quorum = $assembly['quorum_percentage'] ?? 50;
    
    return "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background-color: #f9f9f9; }
            .info-box { background-color: white; padding: 15px; margin: 15px 0; border-left: 4px solid #007bff; }
            .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
            .button { display: inline-block; padding: 12px 24px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>Convocatória de Assembleia</h2>
            </div>
            <div class='content'>
                <p>Prezado(a) Condómino(a),</p>
                
                <p>Informamos que foi agendada uma assembleia para o condomínio <strong>{$condominium['name']}</strong>.</p>
                
                <div class='info-box'>
                    <h3 style='margin-top: 0;'>{$assembly['title']}</h3>
                    <p><strong>Tipo:</strong> {$type}</p>
                    <p><strong>Data:</strong> {$date}</p>
                    <p><strong>Hora:</strong> {$time}</p>
                    <p><strong>Local:</strong> " . ($assembly['location'] ?? 'A definir') . "</p>
                    <p><strong>Quórum necessário:</strong> {$quorum}%</p>
                </div>
                
                <p><strong>Ordem de Trabalhos:</strong></p>
                <p>" . nl2br(htmlspecialchars($assembly['description'] ?? 'A definir na assembleia')) . "</p>
                
                <p><strong>Importante:</strong></p>
                <ul>
                    <li>Por favor, confirme a sua presença através do sistema</li>
                    <li>Em caso de impossibilidade, pode nomear um representante mediante procuração</li>
                    <li>A assembleia iniciará pontualmente no horário indicado</li>
                </ul>
                
                <div style='text-align: center;'>
                    <a href='" . BASE_URL . "condominiums/{$assembly['condominium_id']}/assemblies/{$assembly['id']}' class='button'>Ver Detalhes da Assembleia</a>
                </div>
            </div>
            <div class='footer'>
                <p>Esta convocatória foi gerada automaticamente pelo sistema de gestão de condomínios.</p>
                <p>Em anexo encontra-se o documento PDF da convocatória.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}
