<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Security;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Models\Assembly;
use App\Models\AssemblyAttendee;
use App\Models\Condominium;
use App\Models\Fraction;
use App\Services\NotificationService;
use App\Services\PdfService;
use App\Core\EmailService;

class AssemblyController extends Controller
{
    protected $assemblyModel;
    protected $attendeeModel;
    protected $condominiumModel;
    protected $notificationService;
    protected $pdfService;
    protected $emailService;

    public function __construct()
    {
        parent::__construct();
        $this->assemblyModel = new Assembly();
        $this->attendeeModel = new AssemblyAttendee();
        $this->condominiumModel = new Condominium();
        $this->notificationService = new NotificationService();
        $this->pdfService = new PdfService();
        $this->emailService = new EmailService();
    }

    public function index(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $status = $_GET['status'] ?? null;
        $assemblies = $this->assemblyModel->getByCondominium($condominiumId, ['status' => $status]);

        $this->loadPageTranslations('assemblies');
        
        $this->data += [
            'viewName' => 'pages/assemblies/index.html.twig',
            'page' => ['titulo' => 'Assembleias'],
            'condominium' => $condominium,
            'assemblies' => $assemblies,
            'current_status' => $status,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function create(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('assemblies');
        
        $this->data += [
            'viewName' => 'pages/assemblies/create.html.twig',
            'page' => ['titulo' => 'Criar Assembleia'],
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function store(int $condominiumId)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/create');
            exit;
        }

        $userId = AuthMiddleware::userId();

        try {
            $assemblyId = $this->assemblyModel->create([
                'condominium_id' => $condominiumId,
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'type' => Security::sanitize($_POST['type'] ?? 'ordinary'),
                'scheduled_date' => $_POST['scheduled_date'] . ' ' . ($_POST['scheduled_time'] ?? '20:00'),
                'location' => Security::sanitize($_POST['location'] ?? ''),
                'quorum_percentage' => (float)($_POST['quorum_percentage'] ?? 50),
                'status' => 'scheduled',
                'created_by' => $userId
            ]);

            $_SESSION['success'] = 'Assembleia criada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao criar assembleia: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/create');
            exit;
        }
    }

    public function show(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $attendees = $this->attendeeModel->getByAssembly($id);
        $quorum = $this->attendeeModel->calculateQuorum($id, $condominiumId);

        // Get fractions for attendance registration
        $fractionModel = new Fraction();
        $fractions = $fractionModel->getByCondominiumId($condominiumId);

        $this->loadPageTranslations('assemblies');
        
        $this->data += [
            'viewName' => 'pages/assemblies/show.html.twig',
            'page' => ['titulo' => $assembly['title']],
            'assembly' => $assembly,
            'attendees' => $attendees,
            'quorum' => $quorum,
            'fractions' => $fractions,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function sendConvocation(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Get all condominium users
        global $db;
        $stmt = $db->prepare("
            SELECT DISTINCT u.*, cu.fraction_id
            FROM users u
            INNER JOIN condominium_users cu ON cu.user_id = u.id
            WHERE cu.condominium_id = :condominium_id
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $users = $stmt->fetchAll();

        // Generate PDF
        $pdfFilename = $this->pdfService->generateConvocation($id, $assembly, []);

        // Send emails
        $sent = 0;
        foreach ($users as $user) {
            try {
                $this->emailService->send(
                    $user['email'],
                    'Convocatória de Assembleia: ' . $assembly['title'],
                    $this->getConvocationEmailBody($assembly),
                    null,
                    __DIR__ . '/../../storage/documents/' . $pdfFilename
                );
                $sent++;
            } catch (\Exception $e) {
                error_log("Email error: " . $e->getMessage());
            }
        }

        // Mark as sent
        $this->assemblyModel->markConvocationSent($id);

        // Notify users
        foreach ($users as $user) {
            $this->notificationService->createNotification(
                $user['id'],
                $condominiumId,
                'assembly',
                'Nova Assembleia Agendada',
                'Uma assembleia foi agendada: ' . $assembly['title'],
                BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id
            );
        }

        $_SESSION['success'] = "Convocatórias enviadas para {$sent} utilizadores!";
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function registerAttendance(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $fractionId = (int)$_POST['fraction_id'];
        $attendanceType = Security::sanitize($_POST['attendance_type'] ?? 'present');
        $proxyUserId = !empty($_POST['proxy_user_id']) ? (int)$_POST['proxy_user_id'] : null;

        try {
            $this->attendeeModel->register([
                'assembly_id' => $id,
                'fraction_id' => $fractionId,
                'user_id' => $userId,
                'attendance_type' => $attendanceType,
                'proxy_user_id' => $proxyUserId,
                'notes' => Security::sanitize($_POST['notes'] ?? '')
            ]);

            $_SESSION['success'] = 'Presença registada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao registar presença: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }
    }

    public function generateMinutes(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $attendees = $this->attendeeModel->getByAssembly($id);
        
        // Get votes if any
        $voteModel = new \App\Models\Vote();
        $votes = $voteModel->getByAssembly($id);

        $minutesFilename = $this->pdfService->generateMinutes($id, $assembly, $attendees, $votes);

        // Save to documents
        $documentModel = new \App\Models\Document();
        $userId = AuthMiddleware::userId();
        
        $documentModel->create([
            'condominium_id' => $condominiumId,
            'title' => 'Atas da Assembleia: ' . $assembly['title'],
            'description' => 'Atas geradas automaticamente',
            'file_path' => $minutesFilename,
            'file_name' => 'atas_' . $id . '.html',
            'file_size' => filesize(__DIR__ . '/../../storage/documents/' . $minutesFilename),
            'mime_type' => 'text/html',
            'visibility' => 'condominos',
            'document_type' => 'minutes',
            'uploaded_by' => $userId
        ]);

        $_SESSION['success'] = 'Atas geradas com sucesso!';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    protected function getConvocationEmailBody(array $assembly): string
    {
        $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
        $time = date('H:i', strtotime($assembly['scheduled_date']));
        
        return "
        <h2>Convocatória de Assembleia</h2>
        <p><strong>Título:</strong> {$assembly['title']}</p>
        <p><strong>Data:</strong> {$date}</p>
        <p><strong>Hora:</strong> {$time}</p>
        <p><strong>Local:</strong> " . ($assembly['location'] ?? 'A definir') . "</p>
        <p><strong>Descrição:</strong></p>
        <p>" . nl2br($assembly['description'] ?? '') . "</p>
        <p>Por favor, confirme a sua presença através do sistema.</p>
        ";
    }
}

