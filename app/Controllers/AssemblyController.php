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
use App\Models\VoteTopic;
use App\Models\Vote;
use App\Services\NotificationService;
use App\Services\PdfService;
use App\Core\EmailService;

class AssemblyController extends Controller
{
    protected $assemblyModel;
    protected $attendeeModel;
    protected $condominiumModel;
    protected $topicModel;
    protected $voteModel;
    protected $notificationService;
    protected $pdfService;
    protected $emailService;

    public function __construct()
    {
        parent::__construct();
        $this->assemblyModel = new Assembly();
        $this->attendeeModel = new AssemblyAttendee();
        $this->condominiumModel = new Condominium();
        $this->topicModel = new VoteTopic();
        $this->voteModel = new Vote();
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
        
        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');
        
        $this->data += [
            'viewName' => 'pages/assemblies/index.html.twig',
            'page' => ['titulo' => 'Assembleias'],
            'condominium' => $condominium,
            'assemblies' => $assemblies,
            'current_status' => $status,
            'is_admin' => $isAdmin,
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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
            // Map type to database enum values
            $type = Security::sanitize($_POST['type'] ?? 'ordinary');
            if ($type === 'ordinary') {
                $type = 'ordinaria';
            } elseif ($type === 'extraordinary') {
                $type = 'extraordinaria';
            }
            
            $assemblyId = $this->assemblyModel->create([
                'condominium_id' => $condominiumId,
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'type' => $type,
                'scheduled_date' => $_POST['scheduled_date'] . ' ' . ($_POST['scheduled_time'] ?? '20:00'),
                'location' => Security::sanitize($_POST['location'] ?? ''),
                'quorum_percentage' => (float)($_POST['quorum_percentage'] ?? 50),
                'status' => 'scheduled',
                'created_by' => $userId
            ]);

            // Get assembly details for notifications
            $assembly = $this->assemblyModel->findById($assemblyId);
            if (!$assembly) {
                error_log("AssemblyController: Assembly not found after creation (ID: {$assemblyId})");
            }
            
            // Get all users in the condominium (except demo users and ended associations)
            global $db;
            $stmt = $db->prepare("
                SELECT DISTINCT u.id, u.email, u.name
                FROM users u
                INNER JOIN condominium_users cu ON cu.user_id = u.id
                WHERE cu.condominium_id = :condominium_id
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
                AND (u.is_demo = FALSE OR u.is_demo IS NULL)
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $users = $stmt->fetchAll() ?: [];

            // Create notifications and send emails for all users
            $assemblyLink = BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $assemblyId;
            $assemblyTitle = $assembly['title'] ?? Security::sanitize($_POST['title'] ?? '');
            $scheduledDate = $_POST['scheduled_date'] . ' ' . ($_POST['scheduled_time'] ?? '20:00');
            $formattedDate = date('d/m/Y H:i', strtotime($scheduledDate));
            $notificationMessage = "Uma assembleia foi agendada: {$assemblyTitle} em {$formattedDate}";
            
            error_log("AssemblyController: Creating notifications for " . count($users) . " users for assembly ID: {$assemblyId}");
            
            $notificationsCreated = 0;
            foreach ($users as $user) {
                try {
                    // Create notification (this will also send email if preferences allow and not demo)
                    $result = $this->notificationService->createNotification(
                        $user['id'],
                        $condominiumId,
                        'assembly',
                        'Nova Assembleia Agendada',
                        $notificationMessage,
                        $assemblyLink
                    );
                    if ($result) {
                        $notificationsCreated++;
                        error_log("AssemblyController: Notification created successfully for user ID: {$user['id']} ({$user['email']})");
                    } else {
                        error_log("AssemblyController: Failed to create notification for user ID: {$user['id']} ({$user['email']}) - createNotification returned false");
                    }
                } catch (\Exception $e) {
                    // Log error but don't fail assembly creation
                    error_log("AssemblyController: Exception creating notification for user ID {$user['id']} ({$user['email']}): " . $e->getMessage());
                }
            }
            
            error_log("AssemblyController: Created {$notificationsCreated} notifications out of " . count($users) . " users for assembly ID: {$assemblyId}");

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
        $allFractions = $fractionModel->getByCondominiumId($condominiumId);
        
        // Get user's fractions in this condominium
        $userId = AuthMiddleware::userId();
        
        // Check if user is admin in this condominium
        $isAdmin = RoleMiddleware::isAdminInCondominium($userId, $condominiumId);
        
        if ($isAdmin) {
            // Admin should see all fractions
            $fractions = $allFractions;
        } else {
            // Non-admin users only see their own fractions
            global $db;
            $stmt = $db->prepare("
                SELECT DISTINCT f.*
                FROM fractions f
                INNER JOIN condominium_users cu ON cu.fraction_id = f.id
                WHERE cu.condominium_id = :condominium_id
                AND cu.user_id = :user_id
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
                AND f.is_active = TRUE
                ORDER BY f.identifier ASC
            ");
            $stmt->execute([':condominium_id' => $condominiumId, ':user_id' => $userId]);
            $fractions = $stmt->fetchAll() ?: [];
        }
        
        // Get attendance status for each fraction
        $attendanceStatus = [];
        foreach ($attendees as $attendee) {
            $attendanceStatus[$attendee['fraction_id']] = [
                'type' => $attendee['attendance_type'],
                'user_id' => $attendee['user_id'],
                'user_name' => $attendee['user_name']
            ];
        }
        
        // Get present fractions for voting
        $presentFractionIds = $this->attendeeModel->getPresentFractions($id);

        // Get vote topics
        $topics = $this->topicModel->getByAssembly($id);
        
        // Get vote results for each topic
        $topicsWithResults = [];
        foreach ($topics as $topic) {
            $results = $this->voteModel->calculateResults($topic['id']);
            $votes = $this->voteModel->getByTopic($topic['id']);
            
            // Get present fractions for this topic
            $presentFractionsForTopic = array_filter($fractions, function($fraction) use ($presentFractionIds) {
                return in_array($fraction['id'], $presentFractionIds);
            });
            
            // Get vote status for each present fraction
            $fractionVotes = [];
            foreach ($presentFractionsForTopic as $fraction) {
                $vote = $this->voteModel->getVoteByTopicAndFraction($topic['id'], $fraction['id']);
                $fractionVotes[$fraction['id']] = $vote;
            }
            
            $topicsWithResults[] = [
                'topic' => $topic,
                'results' => $results,
                'votes' => $votes,
                'present_fractions' => array_values($presentFractionsForTopic),
                'fraction_votes' => $fractionVotes
            ];
        }

        // Get template and approved minutes documents
        $documentModel = new \App\Models\Document();
        
        // First, try to get template with correct document_type
        $minutesTemplate = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes_template'
        ]);
        
        // If no template found, check if there's any document for this assembly that might be a template
        if (empty($minutesTemplate)) {
            // Get all documents for this assembly to check
            $allAssemblyDocs = $documentModel->getByAssemblyId($id);
            foreach ($allAssemblyDocs as $doc) {
                // Check if it looks like a template (has minutes_template in filename or is HTML)
                if (($doc['document_type'] === 'minutes_template' || 
                     strpos($doc['file_name'] ?? '', 'minutes_template') !== false ||
                     strpos($doc['file_path'] ?? '', 'minutes_template') !== false) &&
                    ($doc['mime_type'] === 'text/html' || pathinfo($doc['file_name'] ?? '', PATHINFO_EXTENSION) === 'html')) {
                    $minutesTemplate = [$doc];
                    break;
                }
            }
        }
        
        // If still no template found, check condominium documents that might be templates
        if (empty($minutesTemplate)) {
            $condominiumDocs = $documentModel->getByCondominium($condominiumId, [
                'document_type' => 'minutes_template'
            ]);
            // Filter by assembly_id if column exists, or check filename
            foreach ($condominiumDocs as $doc) {
                if (isset($doc['assembly_id']) && $doc['assembly_id'] == $id) {
                    $minutesTemplate = [$doc];
                    break;
                } elseif (strpos($doc['file_name'] ?? '', '_' . $id . '_') !== false || 
                         strpos($doc['file_path'] ?? '', '_' . $id . '_') !== false) {
                    $minutesTemplate = [$doc];
                    break;
                }
            }
        }
        
        // Get approved minutes
        $approvedMinutes = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes',
            'status' => 'approved'
        ]);
        
        // If no approved minutes found, check for any minutes document
        if (empty($approvedMinutes)) {
            $allMinutes = $documentModel->getByAssemblyId($id, [
                'document_type' => 'minutes'
            ]);
            if (!empty($allMinutes)) {
                $approvedMinutes = [$allMinutes[0]]; // Use the first one found
            }
        }
        
        // Get condominium for sidebar
        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $userId = AuthMiddleware::userId();
        $userRole = RoleMiddleware::getUserRoleInCondominium($userId, $condominiumId);
        $isAdmin = ($userRole === 'admin');

        $mt = !empty($minutesTemplate) ? $minutesTemplate[0] : null;
        $reviewDeadline = null;
        $reviewSentAt = null;
        $revisionStats = null;
        $revisions = [];
        $myFractionsForRevision = [];
        $myRevision = [];
        if ($mt && ($mt['status'] ?? '') === 'in_review') {
            $reviewDeadline = $mt['review_deadline'] ?? null;
            $reviewSentAt = $mt['review_sent_at'] ?? null;
            $revModel = new \App\Models\MinutesRevision();
            $revisionStats = $revModel->getStats((int)$mt['id'], $id);
            if ($isAdmin) {
                $revisions = $revModel->getByDocument((int)$mt['id']);
            } else {
                $presentIds = $this->attendeeModel->getPresentFractions($id);
                if (!empty($presentIds)) {
                    $ph = implode(',', array_map('intval', $presentIds));
                    $stmt = $GLOBALS['db']->prepare("
                        SELECT fraction_id FROM condominium_users
                        WHERE condominium_id = ? AND user_id = ? AND fraction_id IN ({$ph})
                        AND (ended_at IS NULL OR ended_at > CURDATE())
                    ");
                    $stmt->execute([$condominiumId, $userId]);
                    $mine = $stmt->fetchAll() ?: [];
                    foreach ($mine as $r) {
                        $fid = (int)$r['fraction_id'];
                        $f = null;
                        foreach ($fractions as $fr) {
                            if ((int)$fr['id'] === $fid) {
                                $f = $fr;
                                break;
                            }
                        }
                        if ($f) {
                            $myFractionsForRevision[] = $f;
                            $rev = $revModel->getByDocumentAndFraction((int)$mt['id'], $fid);
                            $myRevision[$fid] = $rev;
                        }
                    }
                }
            }
        }

        $this->loadPageTranslations('assemblies');
        
        $this->data += [
            'viewName' => 'pages/assemblies/show.html.twig',
            'page' => ['titulo' => $assembly['title']],
            'condominium' => $condominium,
            'assembly' => $assembly,
            'attendees' => $attendees,
            'quorum' => $quorum,
            'fractions' => $fractions,
            'attendance_status' => $attendanceStatus,
            'topics' => $topicsWithResults,
            'minutes_template' => $mt,
            'approved_minutes' => !empty($approvedMinutes) ? $approvedMinutes[0] : null,
            'is_admin' => $isAdmin,
            'review_deadline' => $reviewDeadline,
            'review_sent_at' => $reviewSentAt,
            'revision_stats' => $revisionStats,
            'revisions' => $revisions,
            'my_fractions_for_revision' => $myFractionsForRevision,
            'my_revision' => $myRevision,
            'today_ymd' => date('Y-m-d'),
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null,
            'info' => $_SESSION['info'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);
        unset($_SESSION['info']);

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
        
        // Check if bulk registration
        if (isset($_POST['bulk_attendances']) && is_array($_POST['bulk_attendances'])) {
            // Bulk registration
            $attendances = [];
            foreach ($_POST['bulk_attendances'] as $fractionId => $data) {
                $fractionId = (int)$fractionId;
                
                // Get type from POST data, default to 'absent' if not set or empty
                $attendanceType = 'absent';
                if (isset($data['type']) && !empty(trim($data['type']))) {
                    $attendanceType = trim($data['type']);
                }
                
                // Validate attendance type
                if (!in_array($attendanceType, ['present', 'proxy', 'absent'])) {
                    $attendanceType = 'absent';
                }
                
                if ($attendanceType !== 'absent') {
                    $attendances[] = [
                        'fraction_id' => $fractionId,
                        'user_id' => $userId,
                        'attendance_type' => Security::sanitize($attendanceType),
                        'proxy_user_id' => !empty($data['proxy_user_id']) ? (int)$data['proxy_user_id'] : null,
                        'notes' => Security::sanitizeNullable($data['notes'] ?? null)
                    ];
                } else {
                    // Mark as absent (will be removed)
                    $attendances[] = [
                        'fraction_id' => $fractionId,
                        'attendance_type' => 'absent'
                    ];
                }
            }

            try {
                $registered = $this->attendeeModel->registerBulk($id, $attendances);
                $_SESSION['success'] = "Presenças registadas com sucesso! ({$registered} frações)";
                // Add timestamp to force page reload and avoid cache issues
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id . '?t=' . time());
                exit;
            } catch (\Exception $e) {
                $_SESSION['error'] = 'Erro ao registar presenças: ' . $e->getMessage();
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
                exit;
            }
        } else {
            // Single registration (backward compatibility)
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
                    'notes' => Security::sanitizeNullable($_POST['notes'] ?? null)
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

        // Check if approved minutes already exist
        $documentModel = new \App\Models\Document();
        $approvedMinutes = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes',
            'status' => 'approved'
        ]);

        if (!empty($approvedMinutes)) {
            $_SESSION['info'] = 'Atas aprovadas já existem para esta assembleia.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        // Check if template exists
        $templates = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes_template'
        ]);

        $htmlContent = '';
        if (!empty($templates)) {
            // Use template content
            $template = $templates[0];
            $templatePath = __DIR__ . '/../../storage/documents/' . $template['file_path'];
            
            if (file_exists($templatePath)) {
                $htmlContent = file_get_contents($templatePath);
            }
        }

        // If no template or template file doesn't exist, use automatic generation
        if (empty($htmlContent)) {
            $attendees = $this->attendeeModel->getByAssembly($id);
            $topics = $this->topicModel->getByAssembly($id);
            $allVotes = [];
            foreach ($topics as $topic) {
                $votes = $this->voteModel->getByTopic($topic['id']);
                $allVotes = array_merge($allVotes, $votes);
            }
            $htmlContent = $this->pdfService->getMinutesHtml($assembly, $attendees, $allVotes);
        }

        // Generate minutes file
        $minutesFilename = $this->pdfService->generateMinutes($id, $assembly, [], []);
        
        // Overwrite with correct content
        $filepath = __DIR__ . '/../../storage/documents/' . $minutesFilename;
        file_put_contents($filepath, $htmlContent);

        // Save to documents
        $userId = AuthMiddleware::userId();
        
        $documentModel->create([
            'condominium_id' => $condominiumId,
            'assembly_id' => $id,
            'title' => 'Atas da Assembleia: ' . $assembly['title'],
            'description' => 'Atas geradas automaticamente',
            'file_path' => $minutesFilename,
            'file_name' => 'atas_' . $id . '.html',
            'file_size' => filesize($filepath),
            'mime_type' => 'text/html',
            'visibility' => 'condominos',
            'document_type' => 'minutes',
            'uploaded_by' => $userId
        ]);

        $_SESSION['success'] = 'Atas geradas com sucesso!';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function generateMinutesTemplatePage(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        if ($assembly['status'] !== 'closed' && $assembly['status'] !== 'completed') {
            $_SESSION['error'] = 'Apenas assembleias encerradas podem ter templates gerados.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        try {
            $templateId = $this->generateMinutesTemplate($condominiumId, $id);
            if ($templateId) {
                $_SESSION['success'] = 'Template de atas gerado com sucesso!';
            } else {
                $_SESSION['error'] = 'Erro ao gerar template de atas.';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao gerar template: ' . $e->getMessage();
            error_log("Error generating minutes template: " . $e->getMessage());
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function generateMinutesTemplate(int $condominiumId, int $id): ?int
    {
        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            return null;
        }

        // Check if template already exists
        $documentModel = new \App\Models\Document();
        $existingTemplates = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes_template'
        ]);
        
        if (!empty($existingTemplates)) {
            // Template already exists, return its ID
            return (int)$existingTemplates[0]['id'];
        }

        // Get assembly data
        $attendees = $this->attendeeModel->getByAssembly($id);
        $topics = $this->topicModel->getByAssembly($id);
        
        // Get vote results for each topic
        $voteResults = [];
        foreach ($topics as $topic) {
            $res = $this->voteModel->calculateResults($topic['id']);
            $res['votes_by_fraction'] = $this->voteModel->getByTopic($topic['id']);
            $voteResults[$topic['id']] = $res;
        }

        // Populate template
        $populatedHtml = $this->pdfService->populateMinutesTemplate($assembly, $attendees, $topics, $voteResults);

        // Save template file
        $filename = 'minutes_template_' . $id . '_' . time() . '.html';
        $filepath = __DIR__ . '/../../storage/documents/' . $filename;
        
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($filepath, $populatedHtml);

        // Create document record
        $userId = AuthMiddleware::userId();
        
        $documentId = $documentModel->create([
            'condominium_id' => $condominiumId,
            'assembly_id' => $id,
            'title' => 'Template de Atas: ' . $assembly['title'],
            'description' => 'Template de atas gerado automaticamente',
            'file_path' => $filename,
            'file_name' => 'minutes_template_' . $id . '.html',
            'file_size' => filesize($filepath),
            'mime_type' => 'text/html',
            'visibility' => 'admin',
            'document_type' => 'minutes_template',
            'status' => 'draft',
            'uploaded_by' => $userId
        ]);

        return $documentId;
    }

    public function editMinutesTemplate(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        if ($assembly['status'] !== 'closed' && $assembly['status'] !== 'completed') {
            $_SESSION['error'] = 'Apenas assembleias encerradas podem ter templates editados.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        // Get template document
        $documentModel = new \App\Models\Document();
        $templates = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes_template'
        ]);

        if (empty($templates)) {
            $_SESSION['error'] = 'Template de atas não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $template = $templates[0];
        $templatePath = __DIR__ . '/../../storage/documents/' . $template['file_path'];
        
        if (!file_exists($templatePath)) {
            $_SESSION['error'] = 'Ficheiro do template não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $templateContent = file_get_contents($templatePath);

        // Get approved minutes if exists
        $approvedMinutes = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes',
            'status' => 'approved'
        ]);

        // Get condominium for sidebar
        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }

        $this->loadPageTranslations('assemblies');
        
        $this->data += [
            'viewName' => 'pages/assemblies/edit-minutes-template.html.twig',
            'page' => ['titulo' => 'Editar Template de Atas'],
            'condominium' => $condominium,
            'assembly' => $assembly,
            'template' => $template,
            'template_content' => $templateContent,
            'approved_minutes' => !empty($approvedMinutes) ? $approvedMinutes[0] : null,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];

        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function updateMinutesTemplate(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id . '/minutes-template/edit');
            exit;
        }

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Get template document
        $documentModel = new \App\Models\Document();
        $templates = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes_template'
        ]);

        if (empty($templates)) {
            $_SESSION['error'] = 'Template de atas não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $template = $templates[0];

        // Check if template is in draft status
        if ($template['status'] !== 'draft') {
            $_SESSION['error'] = 'Não é possível editar um template já aprovado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        // Get updated content
        $templateContent = $_POST['template_content'] ?? '';
        
        // Basic HTML sanitization (can be enhanced with HTMLPurifier)
        // For now, allow HTML but escape dangerous content
        $templateContent = htmlspecialchars_decode($templateContent, ENT_QUOTES);

        // Update file
        $templatePath = __DIR__ . '/../../storage/documents/' . $template['file_path'];
        file_put_contents($templatePath, $templateContent);

        // Update file size
        $documentModel->update($template['id'], [
            'file_size' => filesize($templatePath)
        ]);

        $_SESSION['success'] = 'Template de atas atualizado com sucesso!';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id . '/minutes-template/edit');
        exit;
    }

    public function approveMinutes(int $condominiumId, int $id)
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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Get template document (draft or in_review)
        $documentModel = new \App\Models\Document();
        $allTemplates = $documentModel->getByAssemblyId($id, ['document_type' => 'minutes_template']);
        $templates = array_filter($allTemplates, function ($t) {
            return in_array($t['status'] ?? 'draft', ['draft', 'in_review'], true);
        });
        $templates = array_values($templates);

        if (empty($templates)) {
            $_SESSION['error'] = 'Template de atas em rascunho ou em revisão não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $template = $templates[0];

        // If in_review: only allow approve after review_deadline
        if (!empty($template['review_sent_at']) && !empty($template['review_deadline'])) {
            $deadline = $template['review_deadline'];
            if (strtotime('today') <= strtotime($deadline)) {
                $_SESSION['error'] = 'Só pode aprovar após o prazo de revisão (' . (is_string($deadline) ? date('d/m/Y', strtotime($deadline)) : $deadline) . ').';
                header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
                exit;
            }
        }

        $templatePath = __DIR__ . '/../../storage/documents/' . $template['file_path'];
        
        if (!file_exists($templatePath)) {
            $_SESSION['error'] = 'Ficheiro do template não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        // Load template content
        $htmlContent = file_get_contents($templatePath);

        // Generate PDF
        $pdfFilename = $this->pdfService->generateMinutesPdf($htmlContent, $id);

        // Save approved minutes to documents
        $userId = AuthMiddleware::userId();
        
        $filePath = __DIR__ . '/../../storage/documents/' . $pdfFilename;
        $isPdf = pathinfo($pdfFilename, PATHINFO_EXTENSION) === 'pdf';
        
        $documentModel->create([
            'condominium_id' => $condominiumId,
            'assembly_id' => $id,
            'title' => 'Atas Aprovadas: ' . $assembly['title'],
            'description' => 'Atas aprovadas e geradas em PDF',
            'file_path' => $pdfFilename,
            'file_name' => 'atas_aprovadas_' . $id . ($isPdf ? '.pdf' : '.html'),
            'file_size' => file_exists($filePath) ? filesize($filePath) : 0,
            'mime_type' => $isPdf ? 'application/pdf' : 'text/html',
            'visibility' => 'condominos',
            'document_type' => 'minutes',
            'status' => 'approved',
            'uploaded_by' => $userId
        ]);

        // Update template status to approved
        $documentModel->update($template['id'], [
            'status' => 'approved'
        ]);

        $_SESSION['success'] = 'Atas aprovadas e PDF gerado com sucesso!';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function sendForReview(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        $reviewDeadline = $_POST['review_deadline'] ?? '';
        if (empty($reviewDeadline) || strtotime($reviewDeadline) < strtotime('today')) {
            $_SESSION['error'] = 'Indique um prazo de revisão válido (data de hoje ou posterior).';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }
        $documentModel = new \App\Models\Document();
        $templates = $documentModel->getByAssemblyId($id, ['document_type' => 'minutes_template', 'status' => 'draft']);
        if (empty($templates)) {
            $_SESSION['error'] = 'Template de atas em rascunho não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }
        $template = $templates[0];
        $documentModel->update($template['id'], [
            'review_deadline' => $reviewDeadline,
            'review_sent_at' => date('Y-m-d H:i:s'),
            'status' => 'in_review'
        ]);

        $presentFractionIds = $this->attendeeModel->getPresentFractions($id);
        if (!empty($presentFractionIds)) {
            $placeholders = implode(',', array_fill(0, count($presentFractionIds), '?'));
            $stmt = $GLOBALS['db']->prepare("
                SELECT DISTINCT cu.user_id FROM condominium_users cu
                WHERE cu.condominium_id = ? AND cu.fraction_id IN ({$placeholders})
                AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
            ");
            $stmt->execute(array_merge([$condominiumId], $presentFractionIds));
            $rows = $stmt->fetchAll() ?: [];
            $notified = [];
            foreach ($rows as $r) {
                $uid = (int)$r['user_id'];
                if ($uid && !isset($notified[$uid])) {
                    $notified[$uid] = true;
                    $this->notificationService->createNotification(
                        $uid,
                        $condominiumId,
                        'minutes_review',
                        'Ata em revisão',
                        'A ata da assembleia "' . $assembly['title'] . '" foi enviada para revisão (opcional). Prazo: ' . date('d/m/Y', strtotime($reviewDeadline)) . '. Pode enviar comentários ou marcar como revisão aceite.',
                        BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id
                    );
                }
            }
        }

        $_SESSION['success'] = 'Ata enviada para revisão. Prazo: ' . date('d/m/Y', strtotime($reviewDeadline)) . '.';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function cancelReview(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }
        $documentModel = new \App\Models\Document();
        $templates = $documentModel->getByAssemblyId($id, ['document_type' => 'minutes_template']);
        foreach ($templates as $t) {
            if (($t['status'] ?? '') === 'in_review') {
                $documentModel->update($t['id'], [
                    'status' => 'draft',
                    'review_deadline' => null,
                    'review_sent_at' => null
                ]);
                break;
            }
        }
        $_SESSION['success'] = 'Revisão cancelada. A ata voltou a rascunho.';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function submitRevision(int $condominiumId, int $id)
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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $documentId = (int)($_POST['document_id'] ?? 0);
        $fractionId = (int)($_POST['fraction_id'] ?? 0);
        $accepted = !empty($_POST['accepted']);
        $comment = trim((string)($_POST['comment'] ?? '')) ?: null;

        $documentModel = new \App\Models\Document();
        $templates = $documentModel->getByAssemblyId($id, ['document_type' => 'minutes_template']);
        $template = null;
        foreach ($templates as $t) {
            if (($t['status'] ?? '') === 'in_review') {
                $template = $t;
                if ($documentId && (int)$t['id'] !== $documentId) {
                    continue;
                }
                if ($documentId && (int)$t['id'] === $documentId) {
                    break;
                }
                if (!$documentId) {
                    $documentId = (int)$t['id'];
                    break;
                }
            }
        }
        if (!$template || !$documentId) {
            $_SESSION['error'] = 'Não existe ata em revisão para esta assembleia.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }
        $deadline = $template['review_deadline'] ?? null;
        if ($deadline && strtotime('today') > strtotime($deadline)) {
            $_SESSION['error'] = 'O prazo de revisão terminou.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        if ($fractionId <= 0) {
            $_SESSION['error'] = 'Fração inválida.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }
        $presentIds = (new \App\Models\AssemblyAttendee())->getPresentFractions($id);
        if (!in_array($fractionId, $presentIds)) {
            $_SESSION['error'] = 'Esta fração não esteve presente na assembleia.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $userId = AuthMiddleware::userId();
        $stmt = $GLOBALS['db']->prepare("
            SELECT 1 FROM condominium_users cu
            WHERE cu.condominium_id = ? AND cu.fraction_id = ? AND cu.user_id = ?
            AND (cu.ended_at IS NULL OR cu.ended_at > CURDATE())
        ");
        $stmt->execute([$condominiumId, $fractionId, $userId]);
        if (!$stmt->fetch()) {
            $_SESSION['error'] = 'Não tem permissão para submeter a revisão por esta fração.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $revModel = new \App\Models\MinutesRevision();
        $revModel->createOrUpdate($documentId, $id, $fractionId, $userId, $accepted, $comment);
        $_SESSION['success'] = 'Revisão registada.';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function revisions(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }
        $documentModel = new \App\Models\Document();
        $templates = $documentModel->getByAssemblyId($id, ['document_type' => 'minutes_template']);
        $template = null;
        foreach ($templates as $t) {
            if (in_array($t['status'] ?? '', ['draft', 'in_review', 'approved'])) {
                $template = $t;
                break;
            }
        }
        if (!$template) {
            $_SESSION['error'] = 'Template de atas não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $revModel = new \App\Models\MinutesRevision();
        $revisions = $revModel->getByDocument($template['id']);
        $stats = $revModel->getStats($template['id'], $id);

        $condominium = $this->condominiumModel->findById($condominiumId);
        if (!$condominium) {
            $_SESSION['error'] = 'Condomínio não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums');
            exit;
        }
        $this->loadPageTranslations('assemblies');
        $this->data += [
            'viewName' => 'pages/assemblies/revisions.html.twig',
            'page' => ['titulo' => 'Revisões da Ata'],
            'condominium' => $condominium,
            'assembly' => $assembly,
            'template' => $template,
            'revisions' => $revisions,
            'stats' => $stats,
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        unset($_SESSION['error'], $_SESSION['success']);
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function viewMinutes(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Get template document (prefer approved, fallback to draft)
        $documentModel = new \App\Models\Document();
        $approvedMinutes = $documentModel->getByAssemblyId($id, [
            'document_type' => 'minutes',
            'status' => 'approved'
        ]);
        
        $minutesTemplate = null;
        if (!empty($approvedMinutes)) {
            $minutesTemplate = $approvedMinutes[0];
        } else {
            $templates = $documentModel->getByAssemblyId($id, [
                'document_type' => 'minutes_template'
            ]);
            if (!empty($templates)) {
                $minutesTemplate = $templates[0];
            }
        }

        if (!$minutesTemplate) {
            $_SESSION['error'] = 'Ata não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $templatePath = __DIR__ . '/../../storage/documents/' . $minutesTemplate['file_path'];
        
        if (!file_exists($templatePath)) {
            $_SESSION['error'] = 'Ficheiro da ata não encontrado.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $templateContent = file_get_contents($templatePath);

        // Output HTML directly
        header('Content-Type: text/html; charset=UTF-8');
        echo $templateContent;
        exit;
    }

    public function edit(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Can only edit if not started or closed
        if ($assembly['status'] === 'in_progress' || $assembly['status'] === 'closed' || $assembly['status'] === 'completed') {
            $_SESSION['error'] = 'Não é possível editar uma assembleia em andamento ou encerrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $condominium = $this->condominiumModel->findById($condominiumId);

        $this->loadPageTranslations('assemblies');
        
        $this->data += [
            'viewName' => 'pages/assemblies/edit.html.twig',
            'page' => ['titulo' => 'Editar Assembleia'],
            'assembly' => $assembly,
            'condominium' => $condominium,
            'csrf_token' => Security::generateCSRFToken(),
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ];
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);

        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function update(int $condominiumId, int $id)
    {
        AuthMiddleware::require();
        RoleMiddleware::requireCondominiumAccess($condominiumId);
        RoleMiddleware::requireAdminInCondominium($condominiumId);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Security::verifyCSRFToken($csrfToken)) {
            $_SESSION['error'] = 'Token de segurança inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id . '/edit');
            exit;
        }

        $assembly = $this->assemblyModel->findById($id);
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        // Can only edit if not started or closed
        if ($assembly['status'] === 'in_progress' || $assembly['status'] === 'closed' || $assembly['status'] === 'completed') {
            $_SESSION['error'] = 'Não é possível editar uma assembleia em andamento ou encerrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        try {
            // Map type to database enum values
            $type = Security::sanitize($_POST['type'] ?? 'ordinary');
            if ($type === 'ordinary') {
                $type = 'ordinaria';
            } elseif ($type === 'extraordinary') {
                $type = 'extraordinaria';
            }
            
            $this->assemblyModel->update($id, [
                'title' => Security::sanitize($_POST['title'] ?? ''),
                'description' => Security::sanitize($_POST['description'] ?? ''),
                'type' => $type,
                'scheduled_date' => $_POST['scheduled_date'] . ' ' . ($_POST['scheduled_time'] ?? '20:00'),
                'location' => Security::sanitize($_POST['location'] ?? ''),
                'quorum_percentage' => (float)($_POST['quorum_percentage'] ?? 50)
            ]);

            $_SESSION['success'] = 'Assembleia atualizada com sucesso!';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Erro ao atualizar assembleia: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id . '/edit');
            exit;
        }
    }

    public function start(int $condominiumId, int $id)
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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        if ($assembly['status'] !== 'scheduled') {
            $_SESSION['error'] = 'Apenas assembleias agendadas podem ser iniciadas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        if ($this->assemblyModel->start($id)) {
            $_SESSION['success'] = 'Assembleia iniciada!';
        } else {
            $_SESSION['error'] = 'Erro ao iniciar assembleia.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function close(int $condominiumId, int $id)
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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        if ($assembly['status'] !== 'in_progress') {
            $_SESSION['error'] = 'Apenas assembleias em andamento podem ser encerradas.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        $minutes = Security::sanitize($_POST['minutes'] ?? '');

        if ($this->assemblyModel->close($id, $minutes)) {
            // Generate minutes template automatically
            try {
                $templateId = $this->generateMinutesTemplate($condominiumId, $id);
                if ($templateId) {
                    $_SESSION['success'] = 'Assembleia encerrada com sucesso! Template de atas gerado automaticamente.';
                } else {
                    $_SESSION['success'] = 'Assembleia encerrada com sucesso!';
                    $_SESSION['info'] = 'Não foi possível gerar o template automaticamente. Pode gerar manualmente usando o botão abaixo.';
                }
            } catch (\Exception $e) {
                // Log error but don't fail the close operation
                error_log("Error generating minutes template: " . $e->getMessage());
                $_SESSION['success'] = 'Assembleia encerrada com sucesso!';
                $_SESSION['info'] = 'Não foi possível gerar o template automaticamente. Pode gerar manualmente usando o botão abaixo.';
            }
        } else {
            $_SESSION['error'] = 'Erro ao encerrar assembleia.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }

    public function cancel(int $condominiumId, int $id)
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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        if ($assembly['status'] === 'closed' || $assembly['status'] === 'completed') {
            $_SESSION['error'] = 'Não é possível cancelar uma assembleia já encerrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        if ($this->assemblyModel->update($id, ['status' => 'canceled'])) {
            $_SESSION['success'] = 'Assembleia cancelada com sucesso!';
        } else {
            $_SESSION['error'] = 'Erro ao cancelar assembleia.';
        }

        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
        exit;
    }

    protected function getConvocationEmailBody(array $assembly): string
    {
        $condominium = $this->condominiumModel->findById($assembly['condominium_id']);
        $date = date('d/m/Y', strtotime($assembly['scheduled_date']));
        $time = date('H:i', strtotime($assembly['scheduled_date']));
        // Map type for display
        $type = ($assembly['type'] === 'extraordinary' || $assembly['type'] === 'extraordinaria') ? 'Extraordinária' : 'Ordinária';
        $quorum = $assembly['quorum_percentage'] ?? 50;
        $condominiumName = $condominium['name'] ?? 'Condomínio';
        
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
                    
                    <p>Informamos que foi agendada uma assembleia para o condomínio <strong>{$condominiumName}</strong>.</p>
                    
                    <div class='info-box'>
                        <h3 style='margin-top: 0;'>{$assembly['title']}</h3>
                        <p><strong>Tipo:</strong> {$type}</p>
                        <p><strong>Data:</strong> {$date}</p>
                        <p><strong>Hora:</strong> {$time}</p>
                        <p><strong>Local:</strong> " . ($assembly['location'] ?? 'A definir') . "</p>
                        <p><strong>Quórum necessário:</strong> {$quorum}%</p>
                    </div>
                    
                    <p><strong>Ordem de Trabalhos:</strong></p>
                    <p>" . nl2br(htmlspecialchars($assembly['description'] ?? $assembly['agenda'] ?? 'A definir na assembleia')) . "</p>
                    
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

    public function changeStatus(int $condominiumId, int $id)
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
        if (!$assembly || $assembly['condominium_id'] != $condominiumId) {
            $_SESSION['error'] = 'Assembleia não encontrada.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies');
            exit;
        }

        $newStatus = $_POST['status'] ?? '';
        $validStatuses = ['scheduled', 'in_progress', 'closed', 'completed', 'cancelled'];
        
        if (!in_array($newStatus, $validStatuses)) {
            $_SESSION['error'] = 'Status inválido.';
            header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
            exit;
        }

        // Update status
        $this->assemblyModel->update($id, ['status' => $newStatus]);

        $statusNames = [
            'scheduled' => 'Agendada',
            'in_progress' => 'Em Andamento',
            'closed' => 'Encerrada',
            'completed' => 'Concluída',
            'cancelled' => 'Cancelada'
        ];

        $_SESSION['success'] = 'Status da assembleia alterado para "' . ($statusNames[$newStatus] ?? $newStatus) . '" com sucesso!';
        header('Location: ' . BASE_URL . 'condominiums/' . $condominiumId . '/assemblies/' . $id);
        exit;
    }
}

