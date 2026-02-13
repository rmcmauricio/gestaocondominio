<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../database.php';

use App\Models\User;
use App\Models\Condominium;
use App\Models\Fraction;
use App\Models\CondominiumUser;
use App\Models\BankAccount;
use App\Models\FinancialTransaction;
use App\Models\Budget;
use App\Models\BudgetItem;
use App\Models\Supplier;
use App\Models\Assembly;
use App\Models\AssemblyAttendee;
use App\Models\VoteTopic;
use App\Models\Vote;
use App\Models\Space;
use App\Models\Reservation;
use App\Models\Occurrence;
use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\FractionAccount;
use App\Models\Message;
use App\Models\StandaloneVote;
use App\Models\StandaloneVoteResponse;
use App\Models\VoteOption;
use App\Models\Plan;
use App\Models\Subscription;
use App\Core\Security;
use App\Services\FeeService;
use App\Services\LiquidationService;
use App\Services\NotificationService;
use App\Services\SubscriptionService;

class DemoSeeder
{
    protected $db;
    protected $demoUserId;
    protected $demoCondominiumId;
    protected $demoCondominiumIds = []; // Array to store multiple demo condominiums
    protected $fractionIds = [];
    protected $supplierIds = [];
    protected $accountIds = [];
    protected $spaceIds = [];
    protected $savedDemoReceipts = []; // Store demo receipts data during restore to preserve them

    // ID tracking for all created records (for restore functionality)
    protected $createdIds = [
        'condominiums' => [],
        'fractions' => [],
        'condominium_users' => [],
        'bank_accounts' => [],
        'suppliers' => [],
        'budgets' => [],
        'budget_items' => [],
        'expense_categories' => [],
        'revenue_categories' => [],
        'expenses' => [],
        'fees' => [],
        'fee_payments' => [],
        'fee_payment_history' => [],
        'fraction_accounts' => [],
        'fraction_account_movements' => [],
        'financial_transactions' => [],
        'assemblies' => [],
        'assembly_attendees' => [],
        'assembly_agenda_points' => [],
        'assembly_vote_topics' => [],
        'assembly_votes' => [],
        'minutes_revisions' => [],
        'spaces' => [],
        'reservations' => [],
        'messages' => [],
        'occurrences' => [],
        'occurrence_comments' => [],
        'occurrence_history' => [],
        'standalone_votes' => [],
        'vote_options' => [],
        'standalone_vote_responses' => [],
        'receipts' => [],
        'notifications' => [],
        'revenues' => [],
        'documents' => [],
        'contracts' => [],
        'users' => [] // Fraction users (not demo user)
    ];

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function setDemoUserId(int $userId): void
    {
        $this->demoUserId = $userId;
    }

    public function setDemoCondominiumId(int $condominiumId): void
    {
        $this->demoCondominiumId = $condominiumId;
    }

    public function run(): void
    {
        echo "=== Demo Seeder ===\n";
        echo "Iniciando população de dados demo...\n\n";

        try {
            $this->db->beginTransaction();

            // 1. Get or create demo user
            $this->createDemoUser();

            // 1b. Ensure demo user has active subscription
            $this->ensureDemoUserSubscription();

            // 2. Create demo condominiums (2 distinct condominiums)
            $this->createDemoCondominiums();

            // 3. Populate data for each condominium
            foreach ($this->demoCondominiumIds as $index => $condominiumId) {
                $this->demoCondominiumId = $condominiumId;
                echo "\n--- Populando dados para Condomínio " . ($index + 1) . " (ID: {$condominiumId}) ---\n";

                // Reset arrays for each condominium
                $this->fractionIds = [];
                $this->supplierIds = [];
                $this->accountIds = [];
                $this->spaceIds = [];

                // 3. Create fractions
                $this->createFractions($index);

                // 4. Create users for fractions
                $this->createFractionUsers($index);

                // 5. Create bank accounts
                $this->createBankAccounts($index);

                // 6. Create suppliers
                $this->createSuppliers($index);

                // 7. Create budget 2025
                $this->createBudget2025($index);

                // 7b. Create expense categories
                $this->createExpenseCategories($index);

                // 7c. Create revenue categories
                $this->createRevenueCategories($index);

                // 8. Create expense transactions 2025 (financial_transactions)
                $this->createExpenseTransactions2025($index);

                // 9. Generate fees 2025
                $this->generateFees2025($index);

                // 10. Create financial transactions
                $this->createFinancialTransactions($index);

                // 11. Create assemblies
                $this->createAssemblies($index);

                // 12. Create spaces
                $this->createSpaces($index);

                // 13. Create reservations
                $this->createReservations($index);

                // 13b. Create messages 2025 (only for Condominium 1)
                if ($index === 0) {
                    $this->createMessages2025($index);
                }

                // 14. Create occurrences
                $this->createOccurrences($index);

                // 15a. Create budget 2026 (only for Condominium 1)
                if ($index === 0) {
                    $this->createBudget2026($index);
                }

                // 15b. Generate fees 2026 (only for Condominium 1)
                if ($index === 0) {
                    $this->generateFees2026($index);
                }

                // 15. Create receipts for demo payments
                $this->createReceiptsForDemoPayments($index);

                // 16. Create notifications
                $this->createNotifications($index);

                // 17. Create standalone votes (only for Condominium 1)
                if ($index === 0) {
                    $this->createStandaloneVotes($index);
                }
            }

            $this->db->commit();
            echo "\n=== Dados demo criados com sucesso! ===\n";
        } catch (\Exception $e) {
            $this->db->rollBack();
            echo "ERRO: " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
            throw $e;
        }
    }

    protected function createDemoUser(): void
    {
        echo "1. Criando utilizador demo...\n";

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = 'demo@predio.pt' LIMIT 1");
        $stmt->execute();
        $user = $stmt->fetch();

        if ($user) {
            $this->demoUserId = $user['id'];
            // Ensure demo user has is_demo flag set
            $updateStmt = $this->db->prepare("UPDATE users SET is_demo = TRUE WHERE id = :id");
            $updateStmt->execute([':id' => $this->demoUserId]);
            echo "   Utilizador demo já existe (ID: {$this->demoUserId}) - flag is_demo atualizada\n";
        } else {
            $userModel = new User();
            $this->demoUserId = $userModel->create([
                'email' => 'demo@predio.pt',
                'password' => 'Demo@2024',
                'name' => 'Utilizador Demo',
                'role' => 'admin',
                'status' => 'active'
            ]);
            // Mark as demo user
            $updateStmt = $this->db->prepare("UPDATE users SET is_demo = TRUE WHERE id = :id");
            $updateStmt->execute([':id' => $this->demoUserId]);
            echo "   Utilizador demo criado (ID: {$this->demoUserId}) - marcado como is_demo = TRUE\n";
        }
    }

    protected function ensureDemoUserSubscription(): void
    {
        echo "1b. Verificando subscrição do utilizador demo...\n";

        if (!$this->demoUserId) {
            echo "   Aviso: Utilizador demo não encontrado. Pulando criação de subscrição.\n";
            return;
        }

        $subscriptionModel = new Subscription();
        $subscriptionService = new SubscriptionService();

        // Try to find demo plan first (limit 2, inactive), then condominio plan
        $planModel = new Plan();

        // First, try to find demo plan (slug = 'demo' or limit_condominios = 2 and is_active = false)
        $demoPlanStmt = $this->db->prepare("
            SELECT * FROM plans
            WHERE (slug = 'demo' OR (limit_condominios = 2 AND is_active = FALSE))
            ORDER BY id ASC
            LIMIT 1
        ");
        $demoPlanStmt->execute();
        $demoPlan = $demoPlanStmt->fetch();

        $targetPlan = null;
        if ($demoPlan) {
            $targetPlan = $demoPlan;
            echo "   Plano demo encontrado (ID: {$targetPlan['id']}, Limite: {$targetPlan['limit_condominios']})\n";
        } else {
            // Try to find condominio plan (can use with override for demo)
            $condominioPlanStmt = $this->db->prepare("
                SELECT * FROM plans
                WHERE plan_type = 'condominio'
                ORDER BY id ASC
                LIMIT 1
            ");
            $condominioPlanStmt->execute();
            $condominioPlan = $condominioPlanStmt->fetch();

            if ($condominioPlan) {
                $targetPlan = $condominioPlan;
                echo "   Plano Condomínio encontrado (ID: {$targetPlan['id']}) - override para demo permitirá 2 condomínios\n";
            } else {
                echo "   Aviso: Nenhum plano demo ou condominio encontrado. Pulando atualização de subscrição.\n";
                return;
            }
        }

        // Check if demo user already has an active subscription
        $existingSubscription = $subscriptionModel->getActiveSubscription($this->demoUserId);

        if ($existingSubscription) {
            // Check if subscription is using the correct demo plan
            if ($existingSubscription['plan_id'] == $targetPlan['id']) {
                // Already using correct plan, just ensure it's active
                if ($existingSubscription['status'] === 'active') {
                    echo "   Utilizador demo já tem subscrição ativa com plano demo (ID: {$existingSubscription['id']}, Plano: {$targetPlan['name']})\n";
                    return;
                } else {
                    // Update to active
                    $now = date('Y-m-d H:i:s');
                    $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));
                    $subscriptionModel->update($existingSubscription['id'], [
                        'status' => 'active',
                        'trial_ends_at' => null,
                        'current_period_start' => $now,
                        'current_period_end' => $periodEnd
                    ]);
                    echo "   Subscrição demo atualizada para ativa (ID: {$existingSubscription['id']}, Plano: {$targetPlan['name']})\n";
                    return;
                }
            } else {
                // Subscription exists but using wrong plan - update to demo plan
                echo "   Subscrição existe mas está usando plano incorreto (Plano ID: {$existingSubscription['plan_id']}). Atualizando para plano demo...\n";
                $now = date('Y-m-d H:i:s');
                $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));
                $subscriptionModel->update($existingSubscription['id'], [
                    'plan_id' => $targetPlan['id'],
                    'status' => 'active',
                    'trial_ends_at' => null,
                    'current_period_start' => $now,
                    'current_period_end' => $periodEnd,
                    'payment_method' => 'demo'
                ]);
                echo "   Subscrição atualizada para plano demo (ID: {$existingSubscription['id']}, Plano: {$targetPlan['name']})\n";
                return;
            }
        }

        // If trial expired, update to active with demo plan
        $allSubscriptionsStmt = $this->db->prepare("
            SELECT * FROM subscriptions
            WHERE user_id = :user_id
            AND status = 'trial'
            ORDER BY id DESC
            LIMIT 1
        ");
        $allSubscriptionsStmt->execute([':user_id' => $this->demoUserId]);
        $trialSubscription = $allSubscriptionsStmt->fetch();

        if ($trialSubscription) {
            // Update trial subscription to active with demo plan
            $now = date('Y-m-d H:i:s');
            $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));
            $subscriptionModel->update($trialSubscription['id'], [
                'plan_id' => $targetPlan['id'],
                'status' => 'active',
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'payment_method' => 'demo'
            ]);
            echo "   Subscrição trial convertida para ativa com plano demo (ID: {$trialSubscription['id']}, Plano: {$targetPlan['name']})\n";
            return;
        }

        // Create active subscription for demo user with demo plan (valid for 1 year)
        $now = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime('+1 year'));

        $subscriptionId = $subscriptionModel->create([
            'user_id' => $this->demoUserId,
            'plan_id' => $targetPlan['id'],
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'payment_method' => 'demo'
        ]);

        echo "   Subscrição ativa criada para utilizador demo (ID: {$subscriptionId}, Plano: {$targetPlan['name']})\n";
    }

    protected function createDemoCondominiums(): void
    {
        echo "2. Criando condomínios demo...\n";

        // Check if demo condominiums exist
        $stmt = $this->db->prepare("SELECT id, name FROM condominiums WHERE is_demo = TRUE ORDER BY id ASC");
        $stmt->execute();
        $existingCondominiums = $stmt->fetchAll();

        // Define 2 distinct condominiums
        // Note: logo_path here is the source path in assets/images, it will be copied to storage
        $condominiumsData = [
            [
                'name' => 'Residencial Sol Nascente',
                'address' => 'Rua das Flores, 123',
                'postal_code' => '1000-001',
                'city' => 'Lisboa',
                'country' => 'Portugal',
                'nif' => '500000000',
                'total_fractions' => 10,
                'logo_path' => 'assets/images/2596845_condominium_400x267.jpg',
                'document_template' => null // Default template
            ],
            [
                'name' => 'Edifício Mar Atlântico',
                'address' => 'Avenida da Praia, 456',
                'postal_code' => '4100-200',
                'city' => 'Porto',
                'country' => 'Portugal',
                'nif' => '500000001',
                'total_fractions' => 8,
                'logo_path' => 'assets/images/77106082_modern-apartment-building_400x600.jpg',
                'document_template' => 11
            ]
        ];

        $condominiumModel = new Condominium();
        $this->demoCondominiumIds = [];

        // Map existing condominiums by name
        $existingByName = [];
        foreach ($existingCondominiums as $existing) {
            $existingByName[$existing['name']] = $existing['id'];
        }

        // Remove non-demo condominiums with same names that belong to demo user
        // This prevents duplicates if user created condominiums with demo names before
        echo "   Verificando condomínios não-demo com nomes duplicados...\n";
        foreach ($condominiumsData as $data) {
            $stmt = $this->db->prepare("
                SELECT id FROM condominiums
                WHERE name = :name
                AND user_id = :user_id
                AND (is_demo = FALSE OR is_demo IS NULL)
            ");
            $stmt->execute([
                ':name' => $data['name'],
                ':user_id' => $this->demoUserId
            ]);
            $duplicates = $stmt->fetchAll();

            if (!empty($duplicates)) {
                foreach ($duplicates as $dup) {
                    // Safety check: Only delete if it belongs to demo user and is not demo
                    $checkStmt = $this->db->prepare("SELECT user_id, is_demo FROM condominiums WHERE id = :id LIMIT 1");
                    $checkStmt->execute([':id' => $dup['id']]);
                    $check = $checkStmt->fetch();
                    if ($check && $check['user_id'] == $this->demoUserId && (!$check['is_demo'] || $check['is_demo'] == 0)) {
                        echo "   Removendo condomínio não-demo duplicado '{$data['name']}' (ID: {$dup['id']})...\n";
                        $this->deleteCondominiumData($dup['id']);
                        $this->db->exec("DELETE FROM condominiums WHERE id = {$dup['id']}");
                    }
                }
            }
        }

        // Create or reuse 2 distinct condominiums
        foreach ($condominiumsData as $index => $data) {
            $condominiumId = null;

            // Check if demo condominium with this name already exists
            if (isset($existingByName[$data['name']])) {
                // Reuse existing ID
                $condominiumId = $existingByName[$data['name']];
                echo "   Reutilizando condomínio demo '{$data['name']}' (ID: {$condominiumId})\n";

                // Copy logo to storage if provided
                $logoPath = null;
                if (!empty($data['logo_path'])) {
                    $logoPath = $this->copyLogoToStorage($condominiumId, $data['logo_path']);
                }

                // Update condominium data to ensure it's correct
                // Note: Data cleaning should be handled by deleteDemoData() before run() is called
                // We don't clean here to avoid double execution when restore-demo.php calls deleteDemoData() first
                $stmt = $this->db->prepare("
                    UPDATE condominiums
                    SET user_id = :user_id,
                        address = :address,
                        postal_code = :postal_code,
                        city = :city,
                        country = :country,
                        nif = :nif,
                        total_fractions = :total_fractions,
                        logo_path = :logo_path,
                        document_template = :document_template,
                        is_active = :is_active,
                        is_demo = :is_demo
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $condominiumId,
                    ':user_id' => $this->demoUserId,
                    ':address' => $data['address'],
                    ':postal_code' => $data['postal_code'],
                    ':city' => $data['city'],
                    ':country' => $data['country'],
                    ':nif' => $data['nif'],
                    ':total_fractions' => $data['total_fractions'],
                    ':logo_path' => $logoPath,
                    ':document_template' => $data['document_template'],
                    ':is_active' => 1,
                    ':is_demo' => 1
                ]);

                // Ensure owner has entry in condominium_users with admin role
                $this->ensureOwnerAdminRole($condominiumId, $this->demoUserId);
            } else {
                // Create new condominium
                $condominiumId = $condominiumModel->create([
                    'user_id' => $this->demoUserId,
                    'name' => $data['name'],
                    'address' => $data['address'],
                    'postal_code' => $data['postal_code'],
                    'city' => $data['city'],
                    'country' => $data['country'],
                    'nif' => $data['nif'],
                    'total_fractions' => $data['total_fractions'],
                    'is_active' => true,
                    'is_demo' => true
                ]);
                echo "   Condomínio demo '{$data['name']}' criado (ID: {$condominiumId})\n";

                // Copy logo to storage and update logo_path and template
                $logoPath = null;
                if (!empty($data['logo_path'])) {
                    $logoPath = $this->copyLogoToStorage($condominiumId, $data['logo_path']);
                }

                $updateStmt = $this->db->prepare("
                    UPDATE condominiums
                    SET logo_path = :logo_path,
                        document_template = :document_template
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id' => $condominiumId,
                    ':logo_path' => $logoPath,
                    ':document_template' => $data['document_template']
                ]);

                // Create entry in condominium_users with admin role for owner
                $this->ensureOwnerAdminRole($condominiumId, $this->demoUserId);
            }

            $this->demoCondominiumIds[] = $condominiumId;
        }

        // Delete any extra demo condominiums that shouldn't exist (if there are more than 2)
        if (count($existingCondominiums) > 2) {
            echo "   Removendo condomínios demo extras...\n";
            foreach ($existingCondominiums as $existing) {
            if (!in_array($existing['id'], $this->demoCondominiumIds)) {
                // Safety check: Only delete if it's actually a demo condominium
                $checkStmt = $this->db->prepare("SELECT is_demo FROM condominiums WHERE id = :id LIMIT 1");
                $checkStmt->execute([':id' => $existing['id']]);
                $check = $checkStmt->fetch();
                if ($check && $check['is_demo']) {
                    echo "   Removendo condomínio demo extra '{$existing['name']}' (ID: {$existing['id']})...\n";
                    $this->deleteCondominiumData($existing['id']);
                    $this->db->exec("DELETE FROM condominiums WHERE id = {$existing['id']}");
                }
            }
            }
        }

        // Set first condominium (most detailed) as default for demo user
        if (!empty($this->demoCondominiumIds)) {
            $userModel = new User();
            $userModel->setDefaultCondominium($this->demoUserId, $this->demoCondominiumIds[0]);
            $condominiumModel = new Condominium();
            $firstCondominium = $condominiumModel->findById($this->demoCondominiumIds[0]);
            $condominiumName = $firstCondominium ? $firstCondominium['name'] : 'Condomínio 1';
            echo "   Condomínio padrão definido: {$condominiumName} (ID: {$this->demoCondominiumIds[0]})\n";
        }

        // Associate demo user as condomino to both demo condominiums
        $this->associateDemoUserAsCondomino();
    }

    /**
     * Copy logo file from assets/images to storage/condominiums/{id}/logo/
     * @param int $condominiumId Condominium ID
     * @param string $sourcePath Source path relative to project root (e.g., 'assets/images/logo.jpg')
     * @return string|null Storage path relative to storage folder (e.g., 'condominiums/1/logo/logo.jpg') or null on error
     */
    protected function copyLogoToStorage(int $condominiumId, string $sourcePath): ?string
    {
        $projectRoot = __DIR__ . '/../..';
        $sourceFile = $projectRoot . '/' . $sourcePath;

        // Check if source file exists
        if (!file_exists($sourceFile)) {
            echo "   Aviso: Arquivo de logo não encontrado: {$sourcePath}\n";
            return null;
        }

        // Get file extension
        $extension = strtolower(pathinfo($sourceFile, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            echo "   Aviso: Extensão de arquivo não suportada: {$extension}\n";
            return null;
        }

        // Create storage directory structure: storage/condominiums/{condominium_id}/logo/
        $storageBasePath = $projectRoot . '/storage';
        $storagePath = 'condominiums/' . $condominiumId . '/logo/';
        $fullStoragePath = $storageBasePath . '/' . $storagePath;

        if (!is_dir($fullStoragePath)) {
            mkdir($fullStoragePath, 0755, true);
        }

        // Delete old logo if exists
        $oldLogoPath = $fullStoragePath . 'logo.*';
        $oldLogos = glob($oldLogoPath);
        foreach ($oldLogos as $oldLogo) {
            if (is_file($oldLogo)) {
                unlink($oldLogo);
            }
        }

        // Copy file to storage
        $filename = 'logo.' . $extension;
        $destinationFile = $fullStoragePath . $filename;

        if (!copy($sourceFile, $destinationFile)) {
            echo "   Aviso: Erro ao copiar logo para storage\n";
            return null;
        }

        return $storagePath . $filename;
    }

    protected function ensureOwnerAdminRole(int $condominiumId, int $userId): void
    {
        // Check if entry already exists
        $checkStmt = $this->db->prepare("
            SELECT id, role FROM condominium_users
            WHERE condominium_id = :condominium_id
            AND user_id = :user_id
            AND fraction_id IS NULL
            AND (ended_at IS NULL OR ended_at > CURDATE())
        ");
        $checkStmt->execute([
            ':condominium_id' => $condominiumId,
            ':user_id' => $userId
        ]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Update role to admin if not already
            if ($existing['role'] !== 'admin') {
                $updateStmt = $this->db->prepare("
                    UPDATE condominium_users
                    SET role = 'admin',
                        ended_at = NULL
                    WHERE id = :id
                ");
                $updateStmt->execute([':id' => $existing['id']]);
                echo "   Entrada atualizada para role 'admin' para dono do condomínio (ID: {$condominiumId})\n";
            }
            // Track ID for snapshot
            $this->trackId('condominium_users', $existing['id']);
        } else {
            // Create new entry with admin role
            $condominiumUserModel = new CondominiumUser();
            $entryId = $condominiumUserModel->associate([
                'condominium_id' => $condominiumId,
                'user_id' => $userId,
                'fraction_id' => null,
                'role' => 'admin',
                'can_view_finances' => true,
                'can_vote' => true,
                'is_primary' => true,
                'started_at' => date('Y-m-d')
            ]);
            echo "   Entrada criada com role 'admin' para dono do condomínio (ID: {$condominiumId})\n";
            // Track ID for snapshot
            $this->trackId('condominium_users', $entryId);
        }
    }

    protected function associateDemoUserAsCondomino(): void
    {
        echo "2b. Associando utilizador demo como condómino aos condomínios demo...\n";

        if (empty($this->demoCondominiumIds) || !$this->demoUserId) {
            echo "   Aviso: Não é possível associar utilizador demo (condomínios ou utilizador não encontrados).\n";
            return;
        }

        $condominiumUserModel = new CondominiumUser();

        foreach ($this->demoCondominiumIds as $index => $condominiumId) {
            // Get first fraction of this condominium
            $stmt = $this->db->prepare("
                SELECT id FROM fractions
                WHERE condominium_id = :condominium_id
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([':condominium_id' => $condominiumId]);
            $fraction = $stmt->fetch();

            if (!$fraction) {
                echo "   Aviso: Nenhuma fração encontrada para condomínio ID {$condominiumId}. Pulando associação.\n";
                continue;
            }

            $fractionId = $fraction['id'];

            // Check if demo user is already associated with this fraction
            // Note: User can be admin (owner) AND condomino (with fraction) in the same condominium
            $checkStmt = $this->db->prepare("
                SELECT id FROM condominium_users
                WHERE condominium_id = :condominium_id
                AND user_id = :user_id
                AND fraction_id = :fraction_id
            ");
            $checkStmt->execute([
                ':condominium_id' => $condominiumId,
                ':user_id' => $this->demoUserId,
                ':fraction_id' => $fractionId
            ]);
            $existingAssociation = $checkStmt->fetch();

            if ($existingAssociation) {
                // Update existing association (but keep it as condomino, not admin)
                $updateStmt = $this->db->prepare("
                    UPDATE condominium_users
                    SET role = 'proprietario',
                        is_primary = TRUE,
                        can_view_finances = TRUE,
                        can_vote = TRUE
                    WHERE id = :id
                ");
                $updateStmt->execute([':id' => $existingAssociation['id']]);
                echo "   Utilizador demo já associado como condómino ao condomínio ID {$condominiumId} (atualizado)\n";
            } else {
                // Associate demo user as condomino
                $entryId = $condominiumUserModel->associate([
                    'condominium_id' => $condominiumId,
                    'user_id' => $this->demoUserId,
                    'fraction_id' => $fractionId,
                    'role' => 'proprietario',
                    'is_primary' => true,
                    'can_view_finances' => true,
                    'can_vote' => true,
                    'started_at' => '2024-01-01'
                ]);
                echo "   Utilizador demo associado como condómino ao condomínio ID {$condominiumId}\n";
                // Track ID for snapshot
                $this->trackId('condominium_users', $entryId);
            }
        }
    }

    /**
     * Delete all data for a specific condominium (used for cleaning duplicates)
     */
    protected function deleteCondominiumData(int $condominiumId): void
    {
        // IMPORTANT: This method ONLY deletes data for DEMO condominiums
        // It is ONLY called for condominiums with is_demo = TRUE
        // It will NEVER affect non-demo condominiums or real user data

        // Verify this is a demo condominium (safety check)
        $checkStmt = $this->db->prepare("SELECT is_demo FROM condominiums WHERE id = :condominium_id LIMIT 1");
        $checkStmt->execute([':condominium_id' => $condominiumId]);
        $condominium = $checkStmt->fetch();
        if (!$condominium || !$condominium['is_demo']) {
            throw new \Exception("CRITICAL: Attempted to delete data from non-demo condominium ID {$condominiumId}. This should never happen!");
        }

        // Delete in correct order to respect foreign keys
        $this->db->exec("DELETE FROM minutes_revisions WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assembly_votes WHERE topic_id IN (SELECT id FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId}))");
        $this->db->exec("DELETE FROM assembly_agenda_points WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assembly_attendees WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assemblies WHERE condominium_id = {$condominiumId}");
        // Delete fee payment history first (references fee_payments)
        $this->db->exec("DELETE FROM fee_payment_history WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
        // Delete fraction account data (movements first, then accounts)
        $this->db->exec("DELETE FROM fraction_account_movements WHERE fraction_account_id IN (SELECT id FROM fraction_accounts WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM fraction_accounts WHERE condominium_id = {$condominiumId}");
        // Update fee_payments to remove foreign key constraint
        $this->db->exec("UPDATE fee_payments SET financial_transaction_id = NULL WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
        // Delete fee payments
        $this->db->exec("DELETE FROM fee_payments WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
        // Delete fees
        $this->db->exec("DELETE FROM fees WHERE condominium_id = {$condominiumId}");
        // Expense categories (before financial_transactions)
        $this->db->exec("DELETE FROM expense_categories WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM revenue_categories WHERE condominium_id = {$condominiumId}");
        // Delete financial transactions
        $this->db->exec("DELETE FROM financial_transactions WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM bank_accounts WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM reservations WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM spaces WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM occurrence_comments WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM occurrence_history WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM occurrences WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM budgets WHERE condominium_id = {$condominiumId}");
        // Delete contracts
        $this->db->exec("DELETE FROM contracts WHERE condominium_id = {$condominiumId}");

        // Delete suppliers
        $this->db->exec("DELETE FROM suppliers WHERE condominium_id = {$condominiumId}");

        // Delete condominium users
        $this->db->exec("DELETE FROM condominium_users WHERE condominium_id = {$condominiumId}");

        // Delete fractions
        $this->db->exec("DELETE FROM fractions WHERE condominium_id = {$condominiumId}");

        // IMPORTANT: Preserve demo receipts - we'll update their fee_id after recreating fees
        // First, save demo receipts info (we'll use this to match them to new fees later)
        $demoReceiptsData = [];
        if ($this->demoUserId) {
            $saveStmt = $this->db->prepare("
                SELECT r.id, r.fee_id, r.fraction_id, r.receipt_number, r.file_path,
                       f.period_year, f.period_month, fr.identifier as fraction_identifier
                FROM receipts r
                INNER JOIN fees f ON f.id = r.fee_id
                INNER JOIN fractions fr ON fr.id = r.fraction_id
                WHERE r.condominium_id = :condominium_id
                AND r.generated_by = :demo_user_id
                AND r.receipt_type = 'final'
            ");
            $saveStmt->execute([
                ':condominium_id' => $condominiumId,
                ':demo_user_id' => $this->demoUserId
            ]);
            $demoReceiptsData = $saveStmt->fetchAll();

            // Delete PDF files of demo receipts (we'll regenerate them if needed)
            foreach ($demoReceiptsData as $receipt) {
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = __DIR__ . '/../../storage/' . $filePath;
                    } else {
                        $fullPath = __DIR__ . '/../../storage/documents/' . $filePath;
                    }
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }

            // Set fee_id to NULL temporarily (we'll update after recreating fees)
            $this->db->exec("UPDATE receipts SET fee_id = NULL WHERE condominium_id = {$condominiumId} AND generated_by = {$this->demoUserId}");
        }

        // Delete receipts created by non-demo users (test users)
        if ($this->demoUserId) {
            $receiptsStmt = $this->db->prepare("
                SELECT file_path FROM receipts
                WHERE condominium_id = :condominium_id
                AND (generated_by IS NULL OR generated_by != :demo_user_id)
            ");
            $receiptsStmt->execute([
                ':condominium_id' => $condominiumId,
                ':demo_user_id' => $this->demoUserId
            ]);
            $receipts = $receiptsStmt->fetchAll();

            foreach ($receipts as $receipt) {
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = __DIR__ . '/../../storage/' . $filePath;
                    } else {
                        $fullPath = __DIR__ . '/../../storage/documents/' . $filePath;
                    }
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            // Delete receipts created by non-demo users
            $deleteStmt = $this->db->prepare("DELETE FROM receipts WHERE condominium_id = :condominium_id AND (generated_by IS NULL OR generated_by != :demo_user_id)");
            $deleteStmt->execute([
                ':condominium_id' => $condominiumId,
                ':demo_user_id' => $this->demoUserId
            ]);

            // Delete document entries for receipts created by non-demo users
            $deleteDocsStmt = $this->db->prepare("
                DELETE FROM documents 
                WHERE condominium_id = :condominium_id 
                AND document_type = 'receipt' 
                AND (uploaded_by IS NULL OR uploaded_by != :demo_user_id)
            ");
            $deleteDocsStmt->execute([
                ':condominium_id' => $condominiumId,
                ':demo_user_id' => $this->demoUserId
            ]);
        } else {
            // If no demo user ID, delete all receipts for this condominium
            $receiptsStmt = $this->db->prepare("SELECT file_path FROM receipts WHERE condominium_id = :condominium_id");
            $receiptsStmt->execute([':condominium_id' => $condominiumId]);
            $receipts = $receiptsStmt->fetchAll();

            foreach ($receipts as $receipt) {
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = __DIR__ . '/../../storage/' . $filePath;
                    } else {
                        $fullPath = __DIR__ . '/../../storage/documents/' . $filePath;
                    }
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $deleteStmt = $this->db->prepare("DELETE FROM receipts WHERE condominium_id = :condominium_id");
            $deleteStmt->execute([':condominium_id' => $condominiumId]);

            // Delete all document entries for receipts
            $deleteDocsStmt = $this->db->prepare("DELETE FROM documents WHERE condominium_id = :condominium_id AND document_type = 'receipt'");
            $deleteDocsStmt->execute([':condominium_id' => $condominiumId]);
        }

        // Store demo receipts data for later matching (if we have it)
        if (!empty($demoReceiptsData) && $this->demoUserId) {
            // Store in a class property to use later
            if (!isset($this->savedDemoReceipts)) {
                $this->savedDemoReceipts = [];
            }
            $this->savedDemoReceipts[$condominiumId] = $demoReceiptsData;
        }

        // Delete message attachments (files and database records)
        $messageAttachmentsStmt = $this->db->prepare("SELECT file_path FROM message_attachments WHERE condominium_id = :condominium_id");
        $messageAttachmentsStmt->execute([':condominium_id' => $condominiumId]);
        $messageAttachments = $messageAttachmentsStmt->fetchAll();
        foreach ($messageAttachments as $attachment) {
            if (!empty($attachment['file_path'])) {
                $fullPath = __DIR__ . '/../../storage/' . $attachment['file_path'];
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }
        $this->db->exec("DELETE FROM message_attachments WHERE condominium_id = {$condominiumId}");

        // Delete occurrence attachments (files and database records)
        $occurrenceAttachmentsStmt = $this->db->prepare("SELECT file_path FROM occurrence_attachments WHERE condominium_id = :condominium_id");
        $occurrenceAttachmentsStmt->execute([':condominium_id' => $condominiumId]);
        $occurrenceAttachments = $occurrenceAttachmentsStmt->fetchAll();
        foreach ($occurrenceAttachments as $attachment) {
            if (!empty($attachment['file_path'])) {
                $fullPath = __DIR__ . '/../../storage/' . $attachment['file_path'];
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }
        $this->db->exec("DELETE FROM occurrence_attachments WHERE condominium_id = {$condominiumId}");

        // Delete messages
        $this->db->exec("DELETE FROM messages WHERE condominium_id = {$condominiumId}");

        // Delete fees for 2026
        $this->db->exec("DELETE FROM fees WHERE condominium_id = {$condominiumId} AND period_year = 2026");

        // Delete budget 2026
        $stmt = $this->db->prepare("DELETE FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id = :condominium_id AND year = 2026)");
        $stmt->execute([':condominium_id' => $condominiumId]);
        $this->db->exec("DELETE FROM budgets WHERE condominium_id = {$condominiumId} AND year = 2026");

        // Delete revenues
        $this->db->exec("DELETE FROM revenues WHERE condominium_id = {$condominiumId}");

        // Delete documents (but preserve receipt documents from demo user)
        if ($this->demoUserId) {
            // Delete document files except those from demo receipts
            $documentsStmt = $this->db->prepare("
                SELECT file_path FROM documents 
                WHERE condominium_id = :condominium_id 
                AND (document_type != 'receipt' OR uploaded_by != :demo_user_id)
            ");
            $documentsStmt->execute([
                ':condominium_id' => $condominiumId,
                ':demo_user_id' => $this->demoUserId
            ]);
            $documents = $documentsStmt->fetchAll();
            foreach ($documents as $document) {
                if (!empty($document['file_path'])) {
                    $fullPath = __DIR__ . '/../../storage/' . $document['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            // Delete documents except receipt documents from demo user
            $this->db->exec("DELETE FROM documents WHERE condominium_id = {$condominiumId} AND (document_type != 'receipt' OR uploaded_by != {$this->demoUserId})");
        } else {
            // If no demo user ID, delete all documents
            $documentsStmt = $this->db->prepare("SELECT file_path FROM documents WHERE condominium_id = :condominium_id");
            $documentsStmt->execute([':condominium_id' => $condominiumId]);
            $documents = $documentsStmt->fetchAll();
            foreach ($documents as $document) {
                if (!empty($document['file_path'])) {
                    $fullPath = __DIR__ . '/../../storage/' . $document['file_path'];
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                }
            }
            $this->db->exec("DELETE FROM documents WHERE condominium_id = {$condominiumId}");
        }

        // Delete notifications (only for this demo condominium)
        $this->db->exec("DELETE FROM notifications WHERE condominium_id = {$condominiumId}");

        // Delete standalone votes and related data
        $this->db->exec("DELETE FROM standalone_vote_responses WHERE standalone_vote_id IN (SELECT id FROM standalone_votes WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM standalone_votes WHERE condominium_id = {$condominiumId}");
        // Note: vote_options are kept as they might be shared, but we'll clean up orphaned ones in createStandaloneVotes if needed

        // Delete users associated with this condominium (but not demo user)
        // IMPORTANT: Only delete users that are ONLY associated with demo condominiums
        $stmt = $this->db->prepare("SELECT user_id FROM condominium_users WHERE condominium_id = {$condominiumId}");
        $stmt->execute();
        $userIds = $stmt->fetchAll();
        foreach ($userIds as $row) {
            if ($row['user_id'] != $this->demoUserId) {
                // Check if user is associated with any non-demo condominiums
                $checkStmt = $this->db->prepare("
                    SELECT COUNT(*) as count
                    FROM condominium_users cu
                    INNER JOIN condominiums c ON c.id = cu.condominium_id
                    WHERE cu.user_id = :user_id
                    AND (c.is_demo = FALSE OR c.is_demo IS NULL)
                ");
                $checkStmt->execute([':user_id' => $row['user_id']]);
                $check = $checkStmt->fetch();

                // Only delete if user is NOT associated with any non-demo condominiums
                if ($check && $check['count'] == 0) {
                    $this->db->exec("DELETE FROM users WHERE id = {$row['user_id']} AND is_demo = FALSE");
                }
            }
        }
    }

    public function deleteDemoData(): void
    {
        echo "   Removendo dados demo existentes...\n";

        // Get all demo condominiums
        $stmt = $this->db->prepare("SELECT id FROM condominiums WHERE is_demo = TRUE");
        $stmt->execute();
        $demoCondominiums = $stmt->fetchAll();

        foreach ($demoCondominiums as $condominium) {
            $condominiumId = $condominium['id'];
            $this->deleteCondominiumData($condominiumId);
        }
    }

    protected function createFractions(int $condominiumIndex = 0): void
    {
        echo "3. Criando frações...\n";

        // Different fractions for each condominium
        // Permillages are adjusted to sum exactly 1000‰ (100%)
        $fractionsData = [
            // Condominium 0: Residencial Sol Nascente (10 fractions) - Total: 1000‰
            [
                ['identifier' => '1A', 'permillage' => 67, 'floor' => 1, 'typology' => 'T2', 'area' => 85],
                ['identifier' => '1B', 'permillage' => 67, 'floor' => 1, 'typology' => 'T2', 'area' => 85],
                ['identifier' => '2A', 'permillage' => 83, 'floor' => 2, 'typology' => 'T3', 'area' => 110],
                ['identifier' => '2B', 'permillage' => 83, 'floor' => 2, 'typology' => 'T3', 'area' => 110],
                ['identifier' => '3A', 'permillage' => 100, 'floor' => 3, 'typology' => 'T3', 'area' => 120],
                ['identifier' => '3B', 'permillage' => 100, 'floor' => 3, 'typology' => 'T3', 'area' => 120],
                ['identifier' => '4A', 'permillage' => 111, 'floor' => 4, 'typology' => 'T4', 'area' => 140],
                ['identifier' => '4B', 'permillage' => 111, 'floor' => 4, 'typology' => 'T4', 'area' => 140],
                ['identifier' => '5A', 'permillage' => 139, 'floor' => 5, 'typology' => 'T4', 'area' => 160],
                ['identifier' => '5B', 'permillage' => 139, 'floor' => 5, 'typology' => 'T4', 'area' => 160],
            ],
            // Condominium 1: Edifício Mar Atlântico (8 fractions) - Total: 1000‰
            [
                ['identifier' => 'A1', 'permillage' => 83, 'floor' => 0, 'typology' => 'T1', 'area' => 65],
                ['identifier' => 'A2', 'permillage' => 83, 'floor' => 0, 'typology' => 'T1', 'area' => 65],
                ['identifier' => 'B1', 'permillage' => 108, 'floor' => 1, 'typology' => 'T2', 'area' => 90],
                ['identifier' => 'B2', 'permillage' => 108, 'floor' => 1, 'typology' => 'T2', 'area' => 90],
                ['identifier' => 'C1', 'permillage' => 133, 'floor' => 2, 'typology' => 'T3', 'area' => 115],
                ['identifier' => 'C2', 'permillage' => 133, 'floor' => 2, 'typology' => 'T3', 'area' => 115],
                ['identifier' => 'D1', 'permillage' => 176, 'floor' => 3, 'typology' => 'T4', 'area' => 150],
                ['identifier' => 'D2', 'permillage' => 176, 'floor' => 3, 'typology' => 'T4', 'area' => 150],
            ]
        ];

        $fractions = $fractionsData[$condominiumIndex] ?? $fractionsData[0];

        $fractionModel = new Fraction();
        $this->fractionIds = [];

        foreach ($fractions as $fraction) {
            // Check if fraction already exists
            $stmt = $this->db->prepare("
                SELECT id FROM fractions
                WHERE condominium_id = :condominium_id
                AND identifier = :identifier
            ");
            $stmt->execute([
                ':condominium_id' => $this->demoCondominiumId,
                ':identifier' => $fraction['identifier']
            ]);
            $existing = $stmt->fetch();

            if ($existing) {
                // Reuse existing fraction
                $fractionId = (int)$existing['id'];
                // Update fraction data to ensure it's correct
                $updateStmt = $this->db->prepare("
                    UPDATE fractions
                    SET permillage = :permillage,
                        floor = :floor,
                        typology = :typology,
                        area = :area,
                        is_active = 1
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id' => $fractionId,
                    ':permillage' => $fraction['permillage'],
                    ':floor' => $fraction['floor'],
                    ':typology' => $fraction['typology'],
                    ':area' => $fraction['area']
                ]);
                echo "   Fração {$fraction['identifier']} reutilizada (ID: {$fractionId})\n";
            } else {
                // Create new fraction
                $fractionId = $fractionModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'identifier' => $fraction['identifier'],
                    'permillage' => $fraction['permillage'],
                    'floor' => $fraction['floor'],
                    'typology' => $fraction['typology'],
                    'area' => $fraction['area']
                ]);
                echo "   Fração {$fraction['identifier']} criada (ID: {$fractionId})\n";
            }

            $this->fractionIds[$fraction['identifier']] = $fractionId;
        }
    }

    protected function createFractionUsers(int $condominiumIndex = 0): void
    {
        echo "4. Criando utilizadores das frações...\n";

        // Different users for each condominium
        $usersData = [
            // Condominium 0: Residencial Sol Nascente
            [
                ['name' => 'Maria Silva', 'email' => 'maria.silva@email.com', 'phone' => '+351912345678', 'fraction' => '1A', 'role' => 'proprietario'],
                ['name' => 'João Santos', 'email' => 'joao.santos@email.com', 'phone' => '+351912345679', 'fraction' => '1B', 'role' => 'proprietario'],
                ['name' => 'Ana Costa', 'email' => 'ana.costa@email.com', 'phone' => '+351912345680', 'fraction' => '2A', 'role' => 'arrendatario'],
                ['name' => 'Pedro Oliveira', 'email' => 'pedro.oliveira@email.com', 'phone' => '+351912345681', 'fraction' => '2B', 'role' => 'proprietario'],
                ['name' => 'Sofia Martins', 'email' => 'sofia.martins@email.com', 'phone' => '+351912345682', 'fraction' => '3A', 'role' => 'proprietario'],
                ['name' => 'Carlos Ferreira', 'email' => 'carlos.ferreira@email.com', 'phone' => '+351912345683', 'fraction' => '3B', 'role' => 'proprietario'],
                ['name' => 'Rita Alves', 'email' => 'rita.alves@email.com', 'phone' => '+351912345684', 'fraction' => '4A', 'role' => 'arrendatario'],
                ['name' => 'Miguel Rodrigues', 'email' => 'miguel.rodrigues@email.com', 'phone' => '+351912345685', 'fraction' => '4B', 'role' => 'proprietario'],
                ['name' => 'Inês Pereira', 'email' => 'ines.pereira@email.com', 'phone' => '+351912345686', 'fraction' => '5A', 'role' => 'proprietario'],
                ['name' => 'Tiago Gomes', 'email' => 'tiago.gomes@email.com', 'phone' => '+351912345687', 'fraction' => '5B', 'role' => 'proprietario'],
            ],
            // Condominium 1: Edifício Mar Atlântico
            [
                ['name' => 'Luísa Mendes', 'email' => 'luisa.mendes@email.com', 'phone' => '+351912345700', 'fraction' => 'A1', 'role' => 'proprietario'],
                ['name' => 'Ricardo Sousa', 'email' => 'ricardo.sousa@email.com', 'phone' => '+351912345701', 'fraction' => 'A2', 'role' => 'arrendatario'],
                ['name' => 'Catarina Lopes', 'email' => 'catarina.lopes@email.com', 'phone' => '+351912345702', 'fraction' => 'B1', 'role' => 'proprietario'],
                ['name' => 'Bruno Teixeira', 'email' => 'bruno.teixeira@email.com', 'phone' => '+351912345703', 'fraction' => 'B2', 'role' => 'proprietario'],
                ['name' => 'Patrícia Rocha', 'email' => 'patricia.rocha@email.com', 'phone' => '+351912345704', 'fraction' => 'C1', 'role' => 'proprietario'],
                ['name' => 'Nuno Barros', 'email' => 'nuno.barros@email.com', 'phone' => '+351912345705', 'fraction' => 'C2', 'role' => 'arrendatario'],
                ['name' => 'Cristina Nunes', 'email' => 'cristina.nunes@email.com', 'phone' => '+351912345706', 'fraction' => 'D1', 'role' => 'proprietario'],
                ['name' => 'André Monteiro', 'email' => 'andre.monteiro@email.com', 'phone' => '+351912345707', 'fraction' => 'D2', 'role' => 'proprietario'],
            ]
        ];

        $users = $usersData[$condominiumIndex] ?? $usersData[0];

        $userModel = new User();
        $condominiumUserModel = new CondominiumUser();

        foreach ($users as $userData) {
            // Check if user already exists
            $existingUser = $userModel->findByEmail($userData['email']);

            if ($existingUser) {
                $userId = $existingUser['id'];
                echo "   Utilizador {$userData['name']} já existe (ID: {$userId})\n";
                // Update password to ensure it's 'demo' for demo users
                $userModel->update($userId, [
                    'password' => 'demo',
                    'name' => $userData['name'],
                    'phone' => $userData['phone'],
                    'status' => 'active'
                ]);
            } else {
                // Create user
                $userId = $userModel->create([
                    'email' => $userData['email'],
                    'password' => 'demo',
                    'name' => $userData['name'],
                    'role' => 'condomino',
                    'phone' => $userData['phone'],
                    'status' => 'active'
                ]);
            }

            // Mark all users associated with demo condominiums as demo users
            $updateDemoStmt = $this->db->prepare("UPDATE users SET is_demo = TRUE WHERE id = :user_id");
            $updateDemoStmt->execute([':user_id' => $userId]);

            // Check if association already exists
            $fractionId = $this->fractionIds[$userData['fraction']];
            $checkStmt = $this->db->prepare("
                SELECT id FROM condominium_users
                WHERE condominium_id = :condominium_id
                AND user_id = :user_id
                AND fraction_id = :fraction_id
            ");
            $checkStmt->execute([
                ':condominium_id' => $this->demoCondominiumId,
                ':user_id' => $userId,
                ':fraction_id' => $fractionId
            ]);
            $existingAssociation = $checkStmt->fetch();

            if ($existingAssociation) {
                // Update existing association
                $updateStmt = $this->db->prepare("
                    UPDATE condominium_users
                    SET role = :role,
                        is_primary = :is_primary,
                        can_view_finances = :can_view_finances,
                        can_vote = :can_vote
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    ':id' => $existingAssociation['id'],
                    ':role' => $userData['role'],
                    ':is_primary' => true,
                    ':can_view_finances' => true,
                    ':can_vote' => true
                ]);
                echo "   Utilizador {$userData['name']} associado e atualizado à fração {$userData['fraction']}\n";
            } else {
                // Associate with fraction
                $condominiumUserModel->associate([
                    'condominium_id' => $this->demoCondominiumId,
                    'user_id' => $userId,
                    'fraction_id' => $fractionId,
                    'role' => $userData['role'],
                    'is_primary' => true,
                    'can_view_finances' => true,
                    'can_vote' => true,
                    'started_at' => '2024-01-01'
                ]);
                echo "   Utilizador {$userData['name']} criado e associado à fração {$userData['fraction']}\n";
            }
        }
    }

    protected function createBankAccounts(int $condominiumIndex = 0): void
    {
        echo "5. Criando contas bancárias...\n";

        $bankAccountModel = new BankAccount();

        // Check if accounts already exist for this condominium
        $existingAccounts = $bankAccountModel->getByCondominium($this->demoCondominiumId);

        if (!empty($existingAccounts)) {
            echo "   Contas já existem para este condomínio, a saltar criação.\n";
            $this->accountIds = array_column($existingAccounts, 'id');
            return;
        }

        // Different bank accounts for each condominium
        // Initial balances set to ensure positive balance after all expenses
        $accountsData = [
            // Condominium 0: Residencial Sol Nascente
            [
                [
                    'name' => 'Conta Principal',
                    'account_type' => 'bank',
                    'bank_name' => 'Banco BPI',
                    'iban' => 'PT50000000000000000000001',
                    'swift' => 'BBPIPTPL',
                    'initial_balance' => 10000.00
                ],
                [
                    'name' => 'Poupança',
                    'account_type' => 'bank',
                    'bank_name' => 'Banco BPI',
                    'iban' => 'PT50000000000000000000002',
                    'swift' => 'BBPIPTPL',
                    'initial_balance' => 10000.00
                ]
            ],
            // Condominium 1: Edifício Mar Atlântico
            [
                [
                    'name' => 'Conta Principal',
                    'account_type' => 'bank',
                    'bank_name' => 'Caixa Geral de Depósitos',
                    'iban' => 'PT50000000000000000000011',
                    'swift' => 'CGDIPTPL',
                    'initial_balance' => 15000.00
                ],
                [
                    'name' => 'Poupança',
                    'account_type' => 'bank',
                    'bank_name' => 'Caixa Geral de Depósitos',
                    'iban' => 'PT50000000000000000000012',
                    'swift' => 'CGDIPTPL',
                    'initial_balance' => 15000.00
                ]
            ]
        ];

        $accounts = $accountsData[$condominiumIndex] ?? $accountsData[0];

        $this->accountIds = [];

        foreach ($accounts as $account) {
            $accountId = $bankAccountModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'name' => $account['name'],
                'account_type' => $account['account_type'],
                'bank_name' => $account['bank_name'],
                'iban' => $account['iban'],
                'swift' => $account['swift'],
                'initial_balance' => $account['initial_balance'],
                'current_balance' => $account['initial_balance'],
                'is_active' => true
            ]);
            $this->accountIds[] = $accountId;
            echo "   Conta {$account['name']} criada (ID: {$accountId})\n";
        }
    }

    protected function createSuppliers(int $condominiumIndex = 0): void
    {
        echo "6. Criando fornecedores...\n";

        // Check if suppliers already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM suppliers WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingSuppliers = $stmt->fetchAll();

        if (!empty($existingSuppliers)) {
            echo "   Fornecedores já existem para este condomínio, a saltar criação.\n";
            $this->supplierIds = array_column($existingSuppliers, 'id');
            return;
        }

        // Different suppliers for each condominium
        $suppliersData = [
            // Condominium 0: Residencial Sol Nascente
            [
                [
                    'name' => 'Águas de Portugal',
                    'nif' => '500000000',
                    'email' => 'geral@aguas.pt',
                    'phone' => '+351800200002',
                    'address' => 'Rua do Empreendimento, 10',
                    'area' => 'Água e Saneamento'
                ],
                [
                    'name' => 'EDP Comercial',
                    'nif' => '500000001',
                    'email' => 'comercial@edp.pt',
                    'phone' => '+351800100100',
                    'address' => 'Av. 24 de Julho, 12',
                    'area' => 'Energia'
                ],
                [
                    'name' => 'Seguros XYZ',
                    'nif' => '500000002',
                    'email' => 'geral@segurosxyz.pt',
                    'phone' => '+351213456789',
                    'address' => 'Rua dos Seguros, 5',
                    'area' => 'Seguros'
                ],
                [
                    'name' => 'Limpeza ABC',
                    'nif' => '500000003',
                    'email' => 'geral@limpezabc.pt',
                    'phone' => '+351912000001',
                    'address' => 'Rua da Limpeza, 20',
                    'area' => 'Limpeza'
                ],
                [
                    'name' => 'Manutenção DEF',
                    'nif' => '500000004',
                    'email' => 'geral@manutencao.pt',
                        'phone' => '+351912000002',
                    'address' => 'Rua da Manutenção, 15',
                    'area' => 'Manutenção'
                ]
            ],
            // Condominium 1: Edifício Mar Atlântico
            [
                [
                    'name' => 'Águas do Douro e Paiva',
                    'nif' => '500000010',
                    'email' => 'geral@aguasdouro.pt',
                    'phone' => '+351800200003',
                    'address' => 'Rua do Porto, 25',
                    'area' => 'Água e Saneamento'
                ],
                [
                    'name' => 'Galp Energia',
                    'nif' => '500000011',
                    'email' => 'comercial@galp.pt',
                    'phone' => '+351800100200',
                    'address' => 'Av. da República, 50',
                    'area' => 'Energia'
                ],
                [
                    'name' => 'Seguros Porto',
                    'nif' => '500000012',
                    'email' => 'geral@segurosporto.pt',
                    'phone' => '+351223456789',
                    'address' => 'Rua dos Seguros Porto, 8',
                    'area' => 'Seguros'
                ],
                [
                    'name' => 'Limpeza Norte',
                    'nif' => '500000013',
                    'email' => 'geral@limpezanorte.pt',
                    'phone' => '+351912000010',
                    'address' => 'Rua da Limpeza Norte, 30',
                    'area' => 'Limpeza'
                ],
                [
                    'name' => 'Manutenção Porto',
                    'nif' => '500000014',
                    'email' => 'geral@manutencaoporto.pt',
                    'phone' => '+351912000011',
                    'address' => 'Rua da Manutenção Porto, 22',
                    'area' => 'Manutenção'
                ]
            ]
        ];

        $suppliers = $suppliersData[$condominiumIndex] ?? $suppliersData[0];

        $supplierModel = new Supplier();
        $this->supplierIds = [];

        foreach ($suppliers as $supplier) {
            $supplierId = $supplierModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'name' => $supplier['name'],
                'nif' => $supplier['nif'],
                'email' => $supplier['email'],
                'phone' => $supplier['phone'],
                'address' => $supplier['address'],
                'area' => $supplier['area'],
                'is_active' => true
            ]);
            $this->supplierIds[] = $supplierId;
            echo "   Fornecedor {$supplier['name']} criado (ID: {$supplierId})\n";
        }
    }

    protected function createBudget2025(int $condominiumIndex = 0): void
    {
        echo "7. Criando orçamento 2025...\n";

        $budgetModel = new Budget();
        $budgetItemModel = new BudgetItem();

        // Different budgets for each condominium
        // Revenue adjusted to generate fees between 40€ and 80€ per fraction
        $budgetsData = [
            // Condominium 0: Residencial Sol Nascente (10 fractions)
            // Target: ~60€/month per fraction average = 600€/month total = 7200€/year
            [
                'revenue' => [
                    ['category' => 'Receita: Quotas Mensais', 'amount' => 7200.00, 'description' => 'Receita anual de quotas'],
                ],
                'expenses' => [
                    ['category' => 'Despesa: Água', 'amount' => 480.00, 'description' => 'Água mensal'],
                    ['category' => 'Despesa: Energia', 'amount' => 720.00, 'description' => 'Eletricidade mensal'],
                    ['category' => 'Despesa: Seguro', 'amount' => 600.00, 'description' => 'Seguro anual'],
                    ['category' => 'Despesa: Limpeza', 'amount' => 720.00, 'description' => 'Limpeza mensal'],
                    ['category' => 'Despesa: Manutenção', 'amount' => 480.00, 'description' => 'Manutenção variada'],
                    ['category' => 'Despesa: Administração', 'amount' => 240.00, 'description' => 'Taxa de administração'],
                ]
            ],
            // Condominium 1: Edifício Mar Atlântico (8 fractions)
            // Target: ~60€/month per fraction average = 480€/month total = 5760€/year
            [
                'revenue' => [
                    ['category' => 'Receita: Quotas Mensais', 'amount' => 5760.00, 'description' => 'Receita anual de quotas'],
                ],
                'expenses' => [
                    ['category' => 'Despesa: Água', 'amount' => 384.00, 'description' => 'Água mensal'],
                    ['category' => 'Despesa: Energia', 'amount' => 576.00, 'description' => 'Eletricidade mensal'],
                    ['category' => 'Despesa: Seguro', 'amount' => 480.00, 'description' => 'Seguro anual'],
                    ['category' => 'Despesa: Limpeza', 'amount' => 576.00, 'description' => 'Limpeza mensal'],
                    ['category' => 'Despesa: Manutenção', 'amount' => 384.00, 'description' => 'Manutenção variada'],
                    ['category' => 'Despesa: Administração', 'amount' => 192.00, 'description' => 'Taxa de administração'],
                ]
            ]
        ];

        $budgetData = $budgetsData[$condominiumIndex] ?? $budgetsData[0];
        $revenueItems = $budgetData['revenue'];
        $expenseItems = $budgetData['expenses'];

        // Check if budget exists
        $existingBudget = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2025);

        if ($existingBudget) {
            $budgetId = $existingBudget['id'];
            echo "   Orçamento 2025 já existe (ID: {$budgetId})\n";
        } else {
            // Calculate total amount from items first
            $totalRevenue = array_sum(array_column($revenueItems, 'amount'));
            $totalExpenses = array_sum(array_column($expenseItems, 'amount'));
            $totalAmount = $totalRevenue - $totalExpenses;

            $budgetId = $budgetModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'year' => 2025,
                'status' => 'approved',
                'total_amount' => $totalAmount,
                'notes' => 'Orçamento demo 2025'
            ]);
            echo "   Orçamento 2025 criado (ID: {$budgetId})\n";
        }

        // Delete existing items
        $this->db->exec("DELETE FROM budget_items WHERE budget_id = {$budgetId}");

        foreach ($revenueItems as $item) {
            $budgetItemModel->create([
                'budget_id' => $budgetId,
                'category' => $item['category'],
                'amount' => $item['amount'],
                'description' => $item['description']
            ]);
        }

        foreach ($expenseItems as $item) {
            $budgetItemModel->create([
                'budget_id' => $budgetId,
                'category' => $item['category'],
                'amount' => $item['amount'],
                'description' => $item['description']
            ]);
        }

        echo "   Itens do orçamento criados\n";
    }

    protected function createExpenseCategories(int $condominiumIndex = 0): void
    {
        echo "7b. Criando categorias de despesas...\n";

        $categories = ['Água', 'Energia', 'Limpeza', 'Manutenção', 'Seguro'];

        $expenseCategoryModel = new \App\Models\ExpenseCategory();
        foreach ($categories as $name) {
            $existing = $expenseCategoryModel->getByName($this->demoCondominiumId, $name);
            if (!$existing) {
                $id = $expenseCategoryModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'name' => $name
                ]);
                $this->trackId('expense_categories', $id);
            }
        }

        echo "   " . count($categories) . " categorias criadas\n";
    }

    protected function createRevenueCategories(int $condominiumIndex = 0): void
    {
        echo "7c. Criando categorias de receitas...\n";

        $categories = ['Quotas', 'Áreas comuns', 'Juros', 'Outras receitas'];

        $revenueCategoryModel = new \App\Models\RevenueCategory();
        foreach ($categories as $name) {
            $existing = $revenueCategoryModel->getByName($this->demoCondominiumId, $name);
            if (!$existing) {
                $id = $revenueCategoryModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'name' => $name
                ]);
                $this->trackId('revenue_categories', $id);
            }
        }

        echo "   " . count($categories) . " categorias criadas\n";
    }

    protected function createExpenseTransactions2025(int $condominiumIndex = 0): void
    {
        echo "8. Criando despesas 2025 (movimentos financeiros)...\n";

        $transactionModel = new FinancialTransaction();
        $bankAccountId = $this->accountIds[0] ?? null;
        if (!$bankAccountId) {
            echo "   Aviso: Sem conta bancária, despesas não criadas\n";
            return;
        }
        $count = 0;
        $skipped = 0;

        $createExpense = function (string $description, string $category, float $amount, string $date) use (&$count, &$skipped, $transactionModel, $bankAccountId) {
            $checkStmt = $this->db->prepare("
                SELECT id FROM financial_transactions
                WHERE condominium_id = :condominium_id AND transaction_type = 'expense'
                AND description = :description AND transaction_date = :transaction_date AND category = :category
                LIMIT 1
            ");
            $checkStmt->execute([
                ':condominium_id' => $this->demoCondominiumId,
                ':description' => $description,
                ':transaction_date' => $date,
                ':category' => $category
            ]);
            if ($checkStmt->fetch()) {
                $skipped++;
                return;
            }
            $transactionModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'bank_account_id' => $bankAccountId,
                'transaction_type' => 'expense',
                'amount' => $amount,
                'transaction_date' => $date,
                'description' => $description,
                'category' => $category,
                'related_type' => 'manual',
                'created_by' => $this->demoUserId
            ]);
            $count++;
        };

        // Monthly expenses
        // Use dates later in the month (20-28) to ensure they are paid after quota payments (5-25)
        // This helps maintain positive balance in demo
        for ($month = 1; $month <= 12; $month++) {
            // Use later dates (20-28) so expenses are paid after quota payments
            $expenseDay = 20 + ($month % 9); // Varies between 20-28
            $date = "2025-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-" . str_pad($expenseDay, 2, '0', STR_PAD_LEFT);

            $createExpense("Fatura de água - {$month}/2025", 'Água', 150 + rand(0, 30), $date);

            $createExpense("Fatura de energia - {$month}/2025", 'Energia', 200 + rand(0, 50), $date);

            $createExpense("Serviço de limpeza - {$month}/2025", 'Limpeza', 300, $date);

            if ($month % 3 == 0) {
                $createExpense("Manutenção trimestral - {$month}/2025", 'Manutenção', 200 + rand(0, 200), $date);
            }
        }

        $createExpense('Seguro anual 2025', 'Seguro', 600, '2025-01-25');

        $bankAccountModel = new BankAccount();
        $bankAccountModel->updateBalance($bankAccountId);

        if ($skipped > 0) {
            echo "   {$count} despesas criadas, {$skipped} duplicadas ignoradas\n";
        } else {
            echo "   {$count} despesas criadas\n";
        }
    }

    protected function ensureFractionAccounts(): void
    {
        $faModel = new FractionAccount();
        $stmt = $this->db->prepare("SELECT id FROM fractions WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $faModel->getOrCreate((int)$r['id'], $this->demoCondominiumId);
        }
    }

    protected function generateFees2025(int $condominiumIndex = 0): void
    {
        echo "9. Gerando quotas 2025...\n";

        $this->ensureFractionAccounts();

        $feeService = new FeeService();
        $feeModel = new Fee();
        $feePaymentModel = new FeePayment();
        $transactionModel = new FinancialTransaction();

        // For Condominium 1 (index 0): Create historical debts for fraction 1A in 2024
        if ($condominiumIndex === 0) {
            echo "   Criando dívidas históricas 2024 para fração 1A...\n";

            // Create budget for 2024 if it doesn't exist
            $budgetModel = new Budget();
            $existingBudget2024 = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2024);

            if (!$existingBudget2024) {
                // Create a simple budget for 2024
                $budget2024Id = $budgetModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'year' => 2024,
                    'status' => 'approved',
                    'total_amount' => 60000.00,
                    'notes' => 'Orçamento demo 2024'
                ]);

                // Add revenue item
                $budgetItemModel = new BudgetItem();
                $budgetItemModel->create([
                    'budget_id' => $budget2024Id,
                    'category' => 'Receita: Quotas Mensais',
                    'amount' => 60000.00,
                    'description' => 'Receita anual de quotas'
                ]);
            }

            // Get fraction 1A ID
            $fraction1AId = $this->fractionIds['1A'] ?? null;

            if ($fraction1AId) {
                // Generate fees for fraction 1A for 6 months in 2024 (historical debts)
                $historicalMonths = [1, 2, 3, 7, 8, 9]; // Some months unpaid
                foreach ($historicalMonths as $month) {
                    try {
                        // Check if historical fee already exists
                        $checkStmt = $this->db->prepare("
                            SELECT id FROM fees
                            WHERE condominium_id = :condominium_id
                            AND fraction_id = :fraction_id
                            AND period_year = 2024
                            AND period_month = :month
                            AND COALESCE(is_historical, 0) = 1
                        ");
                        $checkStmt->execute([
                            ':condominium_id' => $this->demoCondominiumId,
                            ':fraction_id' => $fraction1AId,
                            ':month' => $month
                        ]);
                        $existingFee = $checkStmt->fetch();

                        if ($existingFee) {
                            // Update existing historical fee to ensure it's marked as historical
                            $updateStmt = $this->db->prepare("
                                UPDATE fees
                                SET is_historical = 1,
                                    status = 'overdue',
                                    fee_type = 'regular'
                                WHERE id = :id
                            ");
                            $updateStmt->execute([':id' => $existingFee['id']]);
                            continue;
                        }

                        // Generate fee for this specific fraction
                        $fractions = $this->db->query("SELECT * FROM fractions WHERE id = {$fraction1AId}")->fetchAll();
                        if (!empty($fractions)) {
                            $fraction = $fractions[0];
                            $budget2024 = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2024);
                            if ($budget2024) {
                                $budgetItems = $this->db->query("SELECT * FROM budget_items WHERE budget_id = {$budget2024['id']}")->fetchAll();
                                $revenueItems = array_filter($budgetItems, function($item) {
                                    return strpos($item['category'], 'Receita:') === 0;
                                });
                                $totalRevenue = array_sum(array_column($revenueItems, 'amount'));
                                $monthlyAmount = $totalRevenue / 12;
                                $totalPermillage = 1000; // Assuming 1000‰ total
                                $feeAmount = ($monthlyAmount * (float)$fraction['permillage']) / $totalPermillage;

                                $dueDate = date('Y-m-d', strtotime("2024-{$month}-10"));
                                $reference = sprintf('Q%03d-%02d-%04d%02d', $this->demoCondominiumId, $fraction1AId, 2024, str_pad($month, 2, '0', STR_PAD_LEFT));

                                $feeId = $feeModel->create([
                                    'condominium_id' => $this->demoCondominiumId,
                                    'fraction_id' => $fraction1AId,
                                    'period_type' => 'monthly',
                                    'fee_type' => 'regular',
                                    'period_year' => 2024,
                                    'period_month' => $month,
                                    'amount' => round($feeAmount, 2),
                                    'base_amount' => round($feeAmount, 2),
                                    'status' => 'overdue',
                                    'due_date' => $dueDate,
                                    'reference' => $reference,
                                    'is_historical' => 1
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        // Log error but continue
                        echo "   Aviso: Erro ao criar dívida histórica 2024/{$month} para fração 1A: " . $e->getMessage() . "\n";
                    }
                }
                echo "   Dívidas históricas 2024 criadas/atualizadas para fração 1A\n";
            }
        }

        // Generate fees for all months in 2025
        $feesGenerated = false;
        for ($month = 1; $month <= 12; $month++) {
            try {
                $feeService->generateMonthlyFees($this->demoCondominiumId, 2025, $month);
                $feesGenerated = true;
            } catch (\Exception $e) {
                // If fees already exist, continue
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }

        // Mark annual fees as generated for 2025 budget
        if ($feesGenerated) {
            $budgetModel = new Budget();
            $budget2025 = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2025);
            if ($budget2025) {
                $budgetModel->markAnnualFeesGenerated($budget2025['id']);
                echo "   Quotas anuais marcadas como geradas para o orçamento 2025\n";
            }
        }

        // Get all fees for 2025
        $stmt = $this->db->prepare("
            SELECT f.*, fr.identifier as fraction_identifier FROM fees f
            LEFT JOIN fractions fr ON fr.id = f.fraction_id
            WHERE f.condominium_id = :condominium_id
            AND f.period_year = 2025
            ORDER BY f.period_month ASC, f.fraction_id ASC
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $fees = $stmt->fetchAll();

        $paidCount = 0;
        $totalFees = count($fees);

        // Group fees by fraction and sort by month to ensure paid fees are always first
        $feesByFraction = [];
        foreach ($fees as $fee) {
            $fractionId = (int)$fee['fraction_id'];
            if (!isset($feesByFraction[$fractionId])) {
                $feesByFraction[$fractionId] = [];
            }
            $feesByFraction[$fractionId][] = $fee;
        }

        // Sort each fraction's fees by month
        foreach ($feesByFraction as $fractionId => $fractionFees) {
            usort($feesByFraction[$fractionId], function($a, $b) {
                return $a['period_month'] <=> $b['period_month'];
            });
        }

        $faModel = new FractionAccount();
        $liquidationService = new LiquidationService();

        if ($condominiumIndex === 0) {
            // Condominium 1: Fraction 1A: no payments; 2A/2B: 6 months; 1B,3A,3B,4A,4B: 100%; others: 75%
            $fraction1AId = $this->fractionIds['1A'] ?? null;
            $fraction1BId = $this->fractionIds['1B'] ?? null;
            $fraction2AId = $this->fractionIds['2A'] ?? null;
            $fraction2BId = $this->fractionIds['2B'] ?? null;
            $fullyPaidFractionIds = [
                $fraction1BId, $this->fractionIds['3A'] ?? null, $this->fractionIds['3B'] ?? null,
                $this->fractionIds['4A'] ?? null, $this->fractionIds['4B'] ?? null
            ];
        }

        foreach ($feesByFraction as $fractionId => $fractionFees) {
            $totalFeesForFraction = count($fractionFees);

            if ($condominiumIndex === 0) {
                if ($fractionId == ($this->fractionIds['1A'] ?? null)) {
                    $paidCountForFraction = 0;
                } elseif ($fractionId == ($this->fractionIds['2A'] ?? null) || $fractionId == ($this->fractionIds['2B'] ?? null)) {
                    $paidCountForFraction = 6;
                } elseif (in_array($fractionId, $fullyPaidFractionIds ?? [])) {
                    $paidCountForFraction = $totalFeesForFraction;
                } else {
                    $paidCountForFraction = (int)($totalFeesForFraction * 0.75);
                }
            } else {
                $paidCountForFraction = (int)($totalFeesForFraction * 0.75);
            }

            if ($paidCountForFraction <= 0) {
                continue;
            }

            $feesToPay = [];
            $totalAmount = 0.0;
            for ($i = 0; $i < $paidCountForFraction && $i < $totalFeesForFraction; $i++) {
                $fee = $fractionFees[$i];
                $remaining = (float)$fee['amount'] - $feePaymentModel->getTotalPaid($fee['id']);
                if ($remaining > 0) {
                    $feesToPay[] = $fee;
                    $totalAmount += $remaining;
                }
            }

            if ($totalAmount <= 0 || empty($feesToPay)) {
                $paidCount += $paidCountForFraction;
                continue;
            }

            $firstFee = $feesToPay[0];
            $paymentDate = "2025-" . str_pad($firstFee['period_month'], 2, '0', STR_PAD_LEFT) . "-" . rand(5, 25);

            $account = $faModel->getOrCreate($fractionId, $this->demoCondominiumId);
            $accountId = (int)$account['id'];

            $lastFee = $feesToPay[count($feesToPay) - 1];
            $monthsRange = count($feesToPay) === 1
                ? (int)$firstFee['period_month']
                : (int)$firstFee['period_month'] . '-' . (int)$lastFee['period_month'];
            $desc = "Pagamento quotas 2025 (meses {$monthsRange})";

            $transactionId = $transactionModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'bank_account_id' => $this->accountIds[0],
                'fraction_id' => $fractionId,
                'transaction_type' => 'income',
                'amount' => $totalAmount,
                'transaction_date' => $paymentDate,
                'description' => $desc,
                'category' => 'Quotas',
                'income_entry_type' => 'quota',
                'reference' => 'REF' . $this->demoCondominiumId . $fractionId . rand(1000, 9999),
                'related_type' => 'fraction_account',
                'related_id' => $accountId,
                'created_by' => $this->demoUserId
            ]);

            $faModel->addCredit($accountId, $totalAmount, 'quota_payment', $transactionId, $desc);
            $liquidationService->liquidate($fractionId, $this->demoUserId, $paymentDate, $transactionId);

            $paidCount += count($feesToPay);
        }

        // Update account balances
        $bankAccountModel = new BankAccount();
        foreach ($this->accountIds as $accountId) {
            $bankAccountModel->updateBalance($accountId);
        }

        echo "   {$totalFees} quotas geradas, {$paidCount} pagas\n";
    }

    protected function createFinancialTransactions(int $condominiumIndex = 0): void
    {
        echo "10. Atualizando saldos das contas...\n";

        $bankAccountModel = new BankAccount();
        foreach ($this->accountIds as $accountId) {
            $bankAccountModel->updateBalance($accountId);
        }

        echo "   Saldos atualizados\n";
    }

    protected function createAssemblies(int $condominiumIndex = 0): void
    {
        echo "11. Criando assembleias...\n";

        // Check if assemblies already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM assemblies WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingAssemblies = $stmt->fetchAll();

        if (!empty($existingAssemblies)) {
            echo "   Assembleias já existem para este condomínio, a saltar criação.\n";
            return;
        }

        $assemblyModel = new Assembly();
        $attendeeModel = new AssemblyAttendee();
        $topicModel = new VoteTopic();
        $voteModel = new Vote();

        // Different assemblies for each condominium
        $assembliesData = [
            // Condominium 0: Residencial Sol Nascente
            [
                [
                    'title' => 'Assembleia Geral - Aprovação de Orçamento 2025',
                    'type' => 'ordinary',
                    'scheduled_date' => '2025-01-15 20:00:00',
                    'location' => 'Salão de Festas',
                    'agenda' => "1. Aprovação de atas da assembleia anterior\n2. Aprovação do orçamento para 2025\n3. Assuntos gerais",
                    'status' => 'completed'
                ],
                [
                    'title' => 'Assembleia Geral - Assuntos Gerais',
                    'type' => 'ordinary',
                    'scheduled_date' => '2025-06-20 20:00:00',
                    'location' => 'Salão de Festas',
                    'agenda' => "1. Aprovação de atas\n2. Assuntos gerais\n3. Questões dos condóminos",
                    'status' => 'scheduled'
                ]
            ],
            // Condominium 1: Edifício Mar Atlântico
            [
                [
                    'title' => 'Assembleia Geral - Aprovação de Orçamento e Regulamento',
                    'type' => 'ordinary',
                    'scheduled_date' => '2025-01-20 19:30:00',
                    'location' => 'Sala de Reuniões',
                    'agenda' => "1. Aprovação de atas\n2. Aprovação do orçamento 2025\n3. Discussão do regulamento interno\n4. Assuntos gerais",
                    'status' => 'completed'
                ],
                [
                    'title' => 'Assembleia Geral - Manutenções e Melhorias',
                    'type' => 'ordinary',
                    'scheduled_date' => '2025-07-10 19:30:00',
                    'location' => 'Sala de Reuniões',
                    'agenda' => "1. Aprovação de atas\n2. Plano de manutenções\n3. Melhorias propostas\n4. Questões dos condóminos",
                    'status' => 'scheduled'
                ]
            ]
        ];

        $assemblies = $assembliesData[$condominiumIndex] ?? $assembliesData[0];

        // Assembly 1: First assembly
        $assembly1Id = $assemblyModel->create([
            'condominium_id' => $this->demoCondominiumId,
            'title' => $assemblies[0]['title'],
            'type' => $assemblies[0]['type'],
            'scheduled_date' => $assemblies[0]['scheduled_date'],
            'location' => $assemblies[0]['location'],
            'agenda' => $assemblies[0]['agenda'],
            'status' => $assemblies[0]['status'],
            'created_by' => $this->demoUserId
        ]);

        // Add attendees (7 present)
        // Get user IDs for fractions
        $stmt = $this->db->prepare("
            SELECT cu.fraction_id, cu.user_id
            FROM condominium_users cu
            WHERE cu.condominium_id = {$this->demoCondominiumId}
            AND cu.is_primary = TRUE
            ORDER BY cu.fraction_id ASC
        ");
        $stmt->execute();
        $fractionUsers = $stmt->fetchAll();
        $fractionUserMap = [];
        foreach ($fractionUsers as $fu) {
            $fractionUserMap[$fu['fraction_id']] = $fu['user_id'];
        }

        // Only add attendees and votes if assembly is completed
        if ($assemblies[0]['status'] === 'completed') {
            $numAttendees = min(7, count($this->fractionIds));
            $presentFractionIds = array_slice(array_values($this->fractionIds), 0, $numAttendees);
            foreach ($presentFractionIds as $fractionId) {
                $attendeeModel->register([
                    'assembly_id' => $assembly1Id,
                    'fraction_id' => $fractionId,
                    'user_id' => $fractionUserMap[$fractionId] ?? $this->demoUserId,
                    'attendance_type' => 'present',
                    'notes' => null
                ]);
            }

            // Add vote topic
            $topicId = $topicModel->create([
                'assembly_id' => $assembly1Id,
                'title' => 'Aprovação do Orçamento 2025',
                'description' => 'Votação para aprovação do orçamento anual',
                'voting_type' => 'yes_no',
                'is_active' => false,
                'voting_started_at' => date('Y-m-d H:i:s', strtotime($assemblies[0]['scheduled_date']) + 1800),
                'voting_ended_at' => date('Y-m-d H:i:s', strtotime($assemblies[0]['scheduled_date']) + 2700),
                'created_by' => $this->demoUserId
            ]);

            // Add votes (all yes)
            foreach ($presentFractionIds as $fractionId) {
                $voteModel->create([
                    'assembly_id' => $assembly1Id,
                    'topic_id' => $topicId,
                    'fraction_id' => $fractionId,
                    'user_id' => $fractionUserMap[$fractionId] ?? $this->demoUserId,
                    'vote_option' => 'yes',
                    'vote_item' => 'yes',
                    'created_by' => $fractionUserMap[$fractionId] ?? $this->demoUserId
                ]);
            }
        }

        echo "   Assembleia 1 criada (ID: {$assembly1Id}) - {$assemblies[0]['status']}\n";

        // Assembly 2: Second assembly
        $assembly2Id = $assemblyModel->create([
            'condominium_id' => $this->demoCondominiumId,
            'title' => $assemblies[1]['title'],
            'type' => $assemblies[1]['type'],
            'scheduled_date' => $assemblies[1]['scheduled_date'],
            'location' => $assemblies[1]['location'],
            'agenda' => $assemblies[1]['agenda'],
            'status' => $assemblies[1]['status'],
            'created_by' => $this->demoUserId
        ]);

        echo "   Assembleia 2 criada (ID: {$assembly2Id}) - {$assemblies[1]['status']}\n";
    }

    protected function createSpaces(int $condominiumIndex = 0): void
    {
        echo "12. Criando espaços...\n";

        // Check if spaces already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM spaces WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingSpaces = $stmt->fetchAll();

        if (!empty($existingSpaces)) {
            echo "   Espaços já existem para este condomínio, a saltar criação.\n";
            $this->spaceIds = array_column($existingSpaces, 'id');
            return;
        }

        $spaceModel = new Space();

        // Different spaces for each condominium
        $spacesData = [
            // Condominium 0: Residencial Sol Nascente
            [
                [
                    'name' => 'Salão de Festas',
                    'description' => 'Salão para eventos e celebrações',
                    'type' => 'hall',
                    'capacity' => 50,
                    'price_per_hour' => 20.00,
                    'price_per_day' => 150.00,
                    'requires_approval' => true
                ],
                [
                    'name' => 'Piscina',
                    'description' => 'Piscina comunitária',
                    'type' => 'pool',
                    'capacity' => 20,
                    'price_per_hour' => 10.00,
                    'price_per_day' => 80.00,
                    'requires_approval' => false
                ],
                [
                    'name' => 'Campo de Ténis',
                    'description' => 'Campo de ténis ao ar livre',
                    'type' => 'sports',
                    'capacity' => 4,
                    'price_per_hour' => 15.00,
                    'price_per_day' => 100.00,
                    'requires_approval' => false
                ]
            ],
            // Condominium 1: Edifício Mar Atlântico
            [
                [
                    'name' => 'Sala de Reuniões',
                    'description' => 'Sala para reuniões e eventos',
                    'type' => 'hall',
                    'capacity' => 30,
                    'price_per_hour' => 15.00,
                    'price_per_day' => 120.00,
                    'requires_approval' => true
                ],
                [
                    'name' => 'Ginásio',
                    'description' => 'Ginásio comunitário',
                    'type' => 'sports',
                    'capacity' => 10,
                    'price_per_hour' => 12.00,
                    'price_per_day' => 90.00,
                    'requires_approval' => false
                ],
                [
                    'name' => 'Terraço',
                    'description' => 'Terraço com vista para o mar',
                    'type' => 'outdoor',
                    'capacity' => 25,
                    'price_per_hour' => 8.00,
                    'price_per_day' => 60.00,
                    'requires_approval' => false
                ]
            ]
        ];

        $spaces = $spacesData[$condominiumIndex] ?? $spacesData[0];

        $this->spaceIds = [];

        foreach ($spaces as $space) {
            $spaceId = $spaceModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'name' => $space['name'],
                'description' => $space['description'],
                'type' => $space['type'],
                'capacity' => $space['capacity'],
                'price_per_hour' => $space['price_per_hour'],
                'price_per_day' => $space['price_per_day'],
                'requires_approval' => $space['requires_approval'],
                'is_active' => true
            ]);
            $this->spaceIds[] = $spaceId;
            echo "   Espaço {$space['name']} criado (ID: {$spaceId})\n";
        }
    }

    protected function createReservations(int $condominiumIndex = 0): void
    {
        echo "13. Criando reservas...\n";

        // Check if reservations already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM reservations WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingReservations = $stmt->fetchAll();

        if (!empty($existingReservations)) {
            echo "   Reservas já existem para este condomínio, a saltar criação.\n";
            return;
        }

        $reservationModel = new Reservation();
        $count = 0;

        // Get fraction users (only those with fraction_id, not admin entries)
        $stmt = $this->db->prepare("
            SELECT cu.user_id, cu.fraction_id
            FROM condominium_users cu
            WHERE cu.condominium_id = {$this->demoCondominiumId}
            AND cu.is_primary = TRUE
            AND cu.fraction_id IS NOT NULL
        ");
        $stmt->execute();
        $fractionUsers = $stmt->fetchAll();

        if (empty($fractionUsers)) {
            echo "   Aviso: Nenhum utilizador com fração encontrado. Pulando criação de reservas.\n";
            return;
        }

        // Create reservations throughout 2025
        // More reservations for Condominium 1 (index 0)
        if ($condominiumIndex === 0) {
            $reservations = [
                ['space_index' => 0, 'month' => 1, 'day' => 15, 'status' => 'approved'], // Salão - January
                ['space_index' => 0, 'month' => 2, 'day' => 14, 'status' => 'approved'], // Salão - Valentine
                ['space_index' => 2, 'month' => 2, 'day' => 20, 'status' => 'approved'], // Ténis - February
                ['space_index' => 0, 'month' => 3, 'day' => 8, 'status' => 'approved'], // Salão - March
                ['space_index' => 2, 'month' => 4, 'day' => 20, 'status' => 'approved'], // Ténis - April
                ['space_index' => 0, 'month' => 5, 'day' => 1, 'status' => 'approved'], // Salão - Labor Day
                ['space_index' => 1, 'month' => 5, 'day' => 25, 'status' => 'pending'], // Piscina - May (pending)
                ['space_index' => 2, 'month' => 6, 'day' => 10, 'status' => 'approved'], // Ténis - June
                ['space_index' => 1, 'month' => 7, 'day' => 15, 'status' => 'approved'], // Piscina - Summer
                ['space_index' => 1, 'month' => 7, 'day' => 28, 'status' => 'approved'], // Piscina - July
                ['space_index' => 1, 'month' => 8, 'day' => 10, 'status' => 'pending'], // Piscina - August (pending)
                ['space_index' => 0, 'month' => 9, 'day' => 15, 'status' => 'approved'], // Salão - September
                ['space_index' => 0, 'month' => 10, 'day' => 5, 'status' => 'canceled'], // Salão - October (canceled)
                ['space_index' => 0, 'month' => 11, 'day' => 20, 'status' => 'approved'], // Salão - November
                ['space_index' => 0, 'month' => 12, 'day' => 25, 'status' => 'pending'], // Salão - Christmas
            ];
        } else {
            // Condominium 2: Keep original reservations
            $reservations = [
                ['space_index' => 0, 'month' => 2, 'day' => 14, 'status' => 'approved'], // Salão - Valentine
                ['space_index' => 0, 'month' => 5, 'day' => 1, 'status' => 'approved'], // Salão - Labor Day
                ['space_index' => 1, 'month' => 7, 'day' => 15, 'status' => 'approved'], // Piscina - Summer
                ['space_index' => 1, 'month' => 8, 'day' => 10, 'status' => 'pending'], // Piscina - Pending
                ['space_index' => 2, 'month' => 4, 'day' => 20, 'status' => 'approved'], // Ténis
                ['space_index' => 2, 'month' => 6, 'day' => 10, 'status' => 'approved'], // Ténis
                ['space_index' => 0, 'month' => 12, 'day' => 25, 'status' => 'pending'], // Salão - Christmas
            ];
        }

        foreach ($reservations as $res) {
            $userIndex = rand(0, count($fractionUsers) - 1);
            $user = $fractionUsers[$userIndex];

            $reservationModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'space_id' => $this->spaceIds[$res['space_index']],
                'fraction_id' => $user['fraction_id'],
                'user_id' => $user['user_id'],
                'start_date' => "2025-" . str_pad($res['month'], 2, '0', STR_PAD_LEFT) . "-" . str_pad($res['day'], 2, '0', STR_PAD_LEFT),
                'end_date' => "2025-" . str_pad($res['month'], 2, '0', STR_PAD_LEFT) . "-" . str_pad($res['day'], 2, '0', STR_PAD_LEFT),
                'start_time' => '14:00:00',
                'end_time' => '18:00:00',
                'status' => $res['status'],
                'notes' => 'Reserva demo'
            ]);
            $count++;
        }

        echo "   {$count} reservas criadas\n";
    }

    protected function createMessages2025(int $condominiumIndex = 0): void
    {
        echo "13b. Criando mensagens 2025...\n";

        // Only for Condominium 1
        if ($condominiumIndex !== 0) {
            return;
        }

        // Check if messages already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM messages WHERE condominium_id = :condominium_id LIMIT 1");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingMessage = $stmt->fetch();

        if ($existingMessage) {
            echo "   Mensagens já existem para este condomínio, a saltar criação.\n";
            return;
        }

        $messageModel = new Message();
        $count = 0;

        // Get fraction users (only those with fraction_id, not admin entries)
        $stmt = $this->db->prepare("
            SELECT cu.user_id, cu.fraction_id, fr.identifier as fraction_identifier
            FROM condominium_users cu
            LEFT JOIN fractions fr ON fr.id = cu.fraction_id
            WHERE cu.condominium_id = {$this->demoCondominiumId}
            AND cu.is_primary = TRUE
            AND cu.fraction_id IS NOT NULL
            ORDER BY cu.fraction_id ASC
        ");
        $stmt->execute();
        $fractionUsers = $stmt->fetchAll();

        if (empty($fractionUsers)) {
            echo "   Nenhum utilizador encontrado para criar mensagens\n";
            return;
        }

        // Messages from admin to all (announcements)
        $announcements = [
            [
                'subject' => 'Aviso: Manutenção do Elevador',
                'message' => '<p>Informamos que o <strong>elevador</strong> estará em manutenção no dia 15 de março entre as 9h e as 12h.</p><p>Pedimos a vossa compreensão.</p>',
                'month' => 2,
                'day' => 10,
                'is_read' => false
            ],
            [
                'subject' => 'Assembleia Geral - Convocatória',
                'message' => '<p>Convocamos todos os condóminos para a <strong>Assembleia Geral</strong> que se realizará no dia 20 de abril às 19h.</p><ul><li>Ponto 1: Aprovação de orçamento</li><li>Ponto 2: Obras de renovação</li></ul>',
                'month' => 3,
                'day' => 15,
                'is_read' => true
            ],
            [
                'subject' => 'Lembretes: Pagamento de Quotas',
                'message' => '<p>Lembramos que as <strong>quotas do mês de maio</strong> devem ser pagas até ao dia 10.</p><p>Obrigado pela vossa colaboração.</p>',
                'month' => 4,
                'day' => 28,
                'is_read' => false
            ],
            [
                'subject' => 'Obras de Renovação - Informação',
                'message' => '<p>Informamos que as <strong>obras de renovação</strong> do edifício terão início em setembro.</p><p>Será criada uma quota extra para financiar estas obras.</p>',
                'month' => 6,
                'day' => 5,
                'is_read' => true
            ],
            [
                'subject' => 'Férias de Verão - Informação',
                'message' => '<p>Desejamos a todos umas <strong>excelentes férias de verão</strong>!</p><p>O condomínio continuará a funcionar normalmente.</p>',
                'month' => 7,
                'day' => 1,
                'is_read' => false
            ],
            [
                'subject' => 'Reunião de Condomínio - Outubro',
                'message' => '<p>Convocamos reunião para o dia <strong>15 de outubro</strong> para discutir questões importantes.</p>',
                'month' => 9,
                'day' => 20,
                'is_read' => true
            ],
            [
                'subject' => 'Festa de Natal',
                'message' => '<p>Estamos a organizar uma <strong>festa de Natal</strong> no salão de festas no dia 20 de dezembro.</p><p>Contamos com a vossa presença!</p>',
                'month' => 11,
                'day' => 10,
                'is_read' => false
            ]
        ];

        $natalMessageId = null; // Store ID of "Festa de Natal" message

        foreach ($announcements as $announcement) {
            $createdAt = "2025-" . str_pad($announcement['month'], 2, '0', STR_PAD_LEFT) . "-" . str_pad($announcement['day'], 2, '0', STR_PAD_LEFT) . " " . rand(9, 18) . ":" . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ":00";

            $messageId = $messageModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'from_user_id' => $this->demoUserId,
                'to_user_id' => null, // Announcement to all
                'thread_id' => null,
                'subject' => $announcement['subject'],
                'message' => $announcement['message']
            ]);

            // Store ID of "Festa de Natal" message for later replies
            if ($announcement['subject'] === 'Festa de Natal') {
                $natalMessageId = $messageId;
            }

            // Update created_at
            $this->db->exec("UPDATE messages SET created_at = '{$createdAt}' WHERE id = {$messageId}");

            // For announcements, mark as read if specified (announcements are read by default for some users)
            if ($announcement['is_read']) {
                $readAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . rand(1, 5) . ' hours'));
                $this->db->exec("
                    UPDATE messages
                    SET is_read = TRUE, read_at = '{$readAt}'
                    WHERE id = {$messageId}
                ");
            }

            $count++;
        }

        // Private messages from admin to specific fractions
        $privateMessages = [
            [
                'fraction_index' => 0, // 1A
                'subject' => 'Lembrete: Quotas em Atraso',
                'message' => '<p>Bom dia,</p><p>Lembramos que existem <strong>quotas em atraso</strong> da fração 1A.</p><p>Por favor, regularize a situação.</p>',
                'month' => 3,
                'day' => 5,
                'is_read' => false
            ],
            [
                'fraction_index' => 2, // 2A
                'subject' => 'Reserva do Salão de Festas',
                'message' => '<p>A vossa reserva do <strong>Salão de Festas</strong> para o dia 14 de fevereiro foi aprovada.</p><p>Bom evento!</p>',
                'month' => 1,
                'day' => 25,
                'is_read' => true
            ],
            [
                'fraction_index' => 3, // 2B
                'subject' => 'Ocorrência Reportada',
                'message' => '<p>Recebemos a vossa ocorrência sobre o <strong>ruído</strong>.</p><p>Estamos a investigar a situação.</p>',
                'month' => 5,
                'day' => 12,
                'is_read' => true
            ],
            [
                'fraction_index' => 4, // 3A
                'subject' => 'Informação sobre Obras',
                'message' => '<p>Informamos que as obras de renovação podem afetar temporariamente o acesso à vossa fração.</p><p>Será avisado com antecedência.</p>',
                'month' => 8,
                'day' => 15,
                'is_read' => false
            ],
            [
                'fraction_index' => 0, // 1A - Another message
                'subject' => 'Urgente: Regularização de Dívidas',
                'message' => '<p>É urgente a regularização das <strong>dívidas acumuladas</strong>.</p><p>Por favor, contacte-nos o mais breve possível.</p>',
                'month' => 9,
                'day' => 1,
                'is_read' => false
            ]
        ];

        foreach ($privateMessages as $msg) {
            if (!isset($fractionUsers[$msg['fraction_index']])) {
                continue;
            }

            $user = $fractionUsers[$msg['fraction_index']];
            $createdAt = "2025-" . str_pad($msg['month'], 2, '0', STR_PAD_LEFT) . "-" . str_pad($msg['day'], 2, '0', STR_PAD_LEFT) . " " . rand(9, 18) . ":" . str_pad(rand(0, 59), 2, '0', STR_PAD_LEFT) . ":00";

            $messageId = $messageModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'from_user_id' => $this->demoUserId,
                'to_user_id' => $user['user_id'],
                'thread_id' => null,
                'subject' => $msg['subject'],
                'message' => $msg['message']
            ]);

            // Update created_at
            $this->db->exec("UPDATE messages SET created_at = '{$createdAt}' WHERE id = {$messageId}");

            // Mark as read if specified
            if ($msg['is_read']) {
                $readAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . rand(1, 5) . ' hours'));
                $this->db->exec("UPDATE messages SET is_read = TRUE, read_at = '{$readAt}' WHERE id = {$messageId}");
            }

            $count++;
        }

        // Create replies to "Festa de Natal" message from multiple residents
        if ($natalMessageId) {
            // Get several fraction users to reply (different fractions)
            $stmt = $this->db->prepare("
                SELECT DISTINCT cu.user_id, cu.fraction_id
                FROM condominium_users cu
                WHERE cu.condominium_id = {$this->demoCondominiumId}
                AND cu.is_primary = TRUE
                AND cu.user_id != {$this->demoUserId}
                ORDER BY cu.fraction_id ASC
                LIMIT 5
            ");
            $stmt->execute();
            $replyUsers = $stmt->fetchAll();

            // Get the original message creation date
            $stmt = $this->db->prepare("SELECT created_at FROM messages WHERE id = {$natalMessageId}");
            $stmt->execute();
            $natalCreatedAt = $stmt->fetchColumn();

            // Reply messages from residents confirming attendance
            $residentReplies = [
                '<p>Excelente ideia! <strong>Confirmamos a nossa presença</strong>.</p><p>Contamos com a festa!</p>',
                '<p>Adoramos a ideia! <strong>Estaremos presentes</strong> com toda a família.</p><p>Obrigado pela organização!</p>',
                '<p>Confirmamos a nossa presença! <strong>Vamos adorar participar</strong>.</p><p>Se precisarem de ajuda, disponham!</p>',
                '<p>Que iniciativa fantástica! <strong>Confirmamos presença</strong>.</p><p>Estamos ansiosos!</p>',
                '<p>Perfeito! <strong>Confirmamos a nossa presença</strong>.</p><p>Será uma excelente oportunidade para convivermos!</p>'
            ];

            $replyIndex = 0;
            foreach ($replyUsers as $replyUser) {
                if ($replyIndex >= count($residentReplies)) {
                    break;
                }

                // Reply created 1-3 days after the original message
                $replyCreatedAt = date('Y-m-d H:i:s', strtotime($natalCreatedAt . ' +' . ($replyIndex + 1) . ' days ' . rand(10, 18) . ' hours'));

                $replyId = $messageModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'from_user_id' => $replyUser['user_id'], // Reply from resident
                    'to_user_id' => $this->demoUserId, // Reply to admin
                    'thread_id' => $natalMessageId,
                    'subject' => 'Re: Festa de Natal',
                    'message' => $residentReplies[$replyIndex]
                ]);

                // Update created_at
                $this->db->exec("UPDATE messages SET created_at = '{$replyCreatedAt}' WHERE id = {$replyId}");

                $count++;
                $replyIndex++;
            }

            // Admin's reply thanking everyone (created after all resident replies)
            if ($replyIndex > 0) {
                $adminReplyCreatedAt = date('Y-m-d H:i:s', strtotime($natalCreatedAt . ' +' . ($replyIndex + 1) . ' days ' . rand(9, 12) . ' hours'));

                $adminReplyId = $messageModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'from_user_id' => $this->demoUserId, // Reply from admin
                    'to_user_id' => null, // Reply to all (announcement reply)
                    'thread_id' => $natalMessageId,
                    'subject' => 'Re: Festa de Natal',
                    'message' => '<p>Muito obrigado a todos pelas <strong>confirmações de presença</strong>!</p><p>Ficamos muito contentes com a adesão. A festa promete ser um sucesso!</p><p>Mais informações serão enviadas em breve.</p><p>Até breve!</p>'
                ]);

                // Update created_at
                $this->db->exec("UPDATE messages SET created_at = '{$adminReplyCreatedAt}' WHERE id = {$adminReplyId}");

                $count++;
            }
        }

        // Create some replies (thread messages) for private messages
        // Get first private message to create a reply
        $stmt = $this->db->prepare("
            SELECT id, to_user_id, created_at FROM messages
            WHERE condominium_id = {$this->demoCondominiumId}
            AND to_user_id IS NOT NULL
            AND thread_id IS NULL
            ORDER BY created_at ASC
            LIMIT 2
        ");
        $stmt->execute();
        $parentMessages = $stmt->fetchAll();

        foreach ($parentMessages as $parent) {
            $replyCreatedAt = date('Y-m-d H:i:s', strtotime($parent['created_at'] . ' +' . rand(1, 3) . ' days'));

            $parentSubject = $this->db->query("SELECT subject FROM messages WHERE id = {$parent['id']}")->fetchColumn();

            $replyId = $messageModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'from_user_id' => $parent['to_user_id'], // Reply from recipient
                'to_user_id' => $this->demoUserId, // Reply to admin
                'thread_id' => $parent['id'],
                'subject' => 'Re: ' . $parentSubject,
                'message' => '<p>Obrigado pela informação!</p><p>Ficamos a aguardar mais detalhes.</p>'
            ]);

            // Update created_at
            $this->db->exec("UPDATE messages SET created_at = '{$replyCreatedAt}' WHERE id = {$replyId}");

            $count++;
        }

        echo "   {$count} mensagens criadas\n";
    }

    protected function createOccurrences(int $condominiumIndex = 0): void
    {
        echo "14. Criando ocorrências...\n";

        // Check if occurrences already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM occurrences WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingOccurrences = $stmt->fetchAll();

        if (!empty($existingOccurrences)) {
            echo "   Ocorrências já existem para este condomínio, a saltar criação.\n";
            return;
        }

        $occurrenceModel = new Occurrence();
        $count = 0;

        // Get fraction users (only those with fraction_id, not admin entries)
        $stmt = $this->db->prepare("
            SELECT cu.user_id, cu.fraction_id
            FROM condominium_users cu
            WHERE cu.condominium_id = {$this->demoCondominiumId}
            AND cu.is_primary = TRUE
            AND cu.fraction_id IS NOT NULL
        ");
        $stmt->execute();
        $fractionUsers = $stmt->fetchAll();

        if (empty($fractionUsers)) {
            echo "   Aviso: Nenhum utilizador com fração encontrado. Pulando criação de ocorrências.\n";
            return;
        }

        // Different occurrences for each condominium
        $occurrencesData = [
            // Condominium 0: Residencial Sol Nascente
            [
                [
                    'title' => 'Vazamento no corredor do 1º andar',
                    'description' => 'Foi detetado um vazamento de água no corredor',
                    'category' => 'Água',
                    'priority' => 'high',
                    'status' => 'completed',
                    'reported_by_index' => 0,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-01-10'
                ],
                [
                    'title' => 'Lâmpada fundida no elevador',
                    'description' => 'A lâmpada do elevador precisa ser substituída',
                    'category' => 'Eletricidade',
                    'priority' => 'medium',
                    'status' => 'in_progress',
                    'reported_by_index' => 2,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-02-15'
                ],
                [
                    'title' => 'Porta do estacionamento não fecha',
                    'description' => 'A porta automática não está a funcionar corretamente',
                    'category' => 'Manutenção',
                    'priority' => 'high',
                    'status' => 'open',
                    'reported_by_index' => 5,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-03-05'
                ],
                [
                    'title' => 'Limpeza insuficiente nas escadas',
                    'description' => 'As escadas não estão a ser limpas adequadamente',
                    'category' => 'Limpeza',
                    'priority' => 'low',
                    'status' => 'completed',
                    'reported_by_index' => 3,
                    'supplier_index' => 3,
                    'fraction_id' => null,
                    'reported_date' => '2025-01-20'
                ],
                [
                    'title' => 'Ruído excessivo na fração 2A',
                    'description' => 'Queixa sobre ruído vindo da fração 2A',
                    'category' => 'Ruído',
                    'priority' => 'medium',
                    'status' => 'open',
                    'reported_by_index' => 1,
                    'supplier_index' => null,
                    'fraction_id' => '2A',
                    'reported_date' => '2025-04-10'
                ],
                [
                    'title' => 'Piscina com água turva',
                    'description' => 'A água da piscina está turva e precisa de tratamento',
                    'category' => 'Manutenção',
                    'priority' => 'medium',
                    'status' => 'in_progress',
                    'reported_by_index' => 7,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-05-15'
                ],
                [
                    'title' => 'Jardim precisa de poda',
                    'description' => 'As árvores do jardim precisam ser podadas',
                    'category' => 'Manutenção',
                    'priority' => 'low',
                    'status' => 'open',
                    'reported_by_index' => 4,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-06-01'
                ],
                [
                    'title' => 'Interruptor avariado no hall',
                    'description' => 'O interruptor do hall principal não funciona',
                    'category' => 'Eletricidade',
                    'priority' => 'medium',
                    'status' => 'completed',
                    'reported_by_index' => 6,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-02-28'
                ]
            ],
            // Condominium 1: Edifício Mar Atlântico
            [
                [
                    'title' => 'Vazamento no terraço',
                    'description' => 'Foi detetado um vazamento de água no terraço',
                    'category' => 'Água',
                    'priority' => 'high',
                    'status' => 'completed',
                    'reported_by_index' => 0,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-01-15'
                ],
                [
                    'title' => 'Porta do ginásio não abre',
                    'description' => 'A porta do ginásio está com problemas no fecho',
                    'category' => 'Manutenção',
                    'priority' => 'medium',
                    'status' => 'in_progress',
                    'reported_by_index' => 2,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-02-20'
                ],
                [
                    'title' => 'Iluminação deficiente no estacionamento',
                    'description' => 'A iluminação do estacionamento está muito fraca',
                    'category' => 'Eletricidade',
                    'priority' => 'medium',
                    'status' => 'open',
                    'reported_by_index' => 3,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-03-10'
                ],
                [
                    'title' => 'Ruído excessivo na fração B1',
                    'description' => 'Queixa sobre ruído vindo da fração B1',
                    'category' => 'Ruído',
                    'priority' => 'medium',
                    'status' => 'open',
                    'reported_by_index' => 1,
                    'supplier_index' => null,
                    'fraction_id' => 'B1',
                    'reported_date' => '2025-04-15'
                ],
                [
                    'title' => 'Equipamento do ginásio avariado',
                    'description' => 'Uma máquina do ginásio precisa de reparação',
                    'category' => 'Manutenção',
                    'priority' => 'medium',
                    'status' => 'in_progress',
                    'reported_by_index' => 5,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-05-20'
                ],
                [
                    'title' => 'Terraço precisa de limpeza',
                    'description' => 'O terraço precisa de uma limpeza mais profunda',
                    'category' => 'Limpeza',
                    'priority' => 'low',
                    'status' => 'completed',
                    'reported_by_index' => 4,
                    'supplier_index' => 3,
                    'fraction_id' => null,
                    'reported_date' => '2025-01-25'
                ],
                [
                    'title' => 'Vidro partido na entrada',
                    'description' => 'Um vidro da entrada principal está partido',
                    'category' => 'Manutenção',
                    'priority' => 'high',
                    'status' => 'completed',
                    'reported_by_index' => 6,
                    'supplier_index' => 4,
                    'fraction_id' => null,
                    'reported_date' => '2025-02-05'
                ]
            ]
        ];

        $occurrences = $occurrencesData[$condominiumIndex] ?? $occurrencesData[0];

        foreach ($occurrences as $occ) {
            $reportedBy = $fractionUsers[$occ['reported_by_index']]['user_id'];
            $fractionId = null;
            if ($occ['fraction_id'] !== null && isset($this->fractionIds[$occ['fraction_id']])) {
                $fractionId = $this->fractionIds[$occ['fraction_id']];
            }

            $occurrenceModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'fraction_id' => $fractionId,
                'reported_by' => $reportedBy,
                'assigned_to' => $occ['supplier_index'] !== null ? null : null,
                'supplier_id' => $occ['supplier_index'] !== null ? $this->supplierIds[$occ['supplier_index']] : null,
                'title' => $occ['title'],
                'description' => $occ['description'],
                'category' => $occ['category'],
                'priority' => $occ['priority'],
                'status' => $occ['status'],
                'reported_at' => $occ['reported_date'] . ' 10:00:00'
            ]);
            $count++;
        }

        echo "   {$count} ocorrências criadas\n";
    }

    protected function createNotifications(int $condominiumIndex = 0): void
    {
        echo "15. Criando notificações...\n";

        // Check if notifications already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM notifications WHERE condominium_id = :condominium_id LIMIT 1");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingNotification = $stmt->fetch();

        if ($existingNotification) {
            echo "   Notificações já existem para este condomínio, a saltar criação.\n";
            return;
        }

        $notificationService = new NotificationService();
        $count = 0;

        // Get all users associated with this condominium (including fraction users)
        $stmt = $this->db->prepare("
            SELECT DISTINCT cu.user_id, u.name, u.email, u.role
            FROM condominium_users cu
            INNER JOIN users u ON u.id = cu.user_id
            WHERE cu.condominium_id = :condominium_id
            ORDER BY cu.user_id ASC
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $fractionUsers = $stmt->fetchAll();

        // Always include demo user (admin) - owner of condominium
        $allUsers = [];
        if ($this->demoUserId) {
            $stmt = $this->db->prepare("SELECT id, name, email, role FROM users WHERE id = :user_id LIMIT 1");
            $stmt->execute([':user_id' => $this->demoUserId]);
            $demoUserData = $stmt->fetch();
            if ($demoUserData) {
                $allUsers[] = [
                    'user_id' => $demoUserData['id'],
                    'name' => $demoUserData['name'],
                    'email' => $demoUserData['email'],
                    'role' => $demoUserData['role']
                ];
            }
        }

        // Add fraction users (avoid duplicates)
        foreach ($fractionUsers as $user) {
            $exists = false;
            foreach ($allUsers as $existing) {
                if ($existing['user_id'] == $user['user_id']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $allUsers[] = $user;
            }
        }

        if (empty($allUsers)) {
            echo "   Nenhum utilizador encontrado para criar notificações\n";
            return;
        }

        // Get occurrences for this condominium
        $stmt = $this->db->prepare("
            SELECT id, title, reported_by, created_at
            FROM occurrences
            WHERE condominium_id = :condominium_id
            ORDER BY created_at DESC
            LIMIT 5
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $occurrences = $stmt->fetchAll();

        // Get assemblies for this condominium
        $stmt = $this->db->prepare("
            SELECT id, title, scheduled_date, status
            FROM assemblies
            WHERE condominium_id = :condominium_id
            ORDER BY scheduled_date DESC
            LIMIT 2
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $assemblies = $stmt->fetchAll();

        // Get overdue fees
        $stmt = $this->db->prepare("
            SELECT DISTINCT f.id, f.fraction_id, cu.user_id, f.due_date, f.amount
            FROM fees f
            INNER JOIN condominium_users cu ON cu.fraction_id = f.fraction_id AND cu.condominium_id = f.condominium_id
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'pending'
            AND f.due_date < CURDATE()
            AND COALESCE(f.is_historical, 0) = 0
            LIMIT 3
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $overdueFees = $stmt->fetchAll();

        // Find demo user (admin) from all users
        $demoUser = null;
        foreach ($allUsers as $user) {
            if ($user['role'] === 'admin' && $user['user_id'] == $this->demoUserId) {
                $demoUser = $user;
                break;
            }
        }

        // 1. Notifications for admin about occurrences
        // Always notify demo user (admin) about occurrences, even if not in condominium_users
        if ($this->demoUserId && !empty($occurrences)) {
            foreach (array_slice($occurrences, 0, 3) as $occurrence) {
                // Only notify if occurrence was reported by someone else
                if ($occurrence['reported_by'] != $this->demoUserId) {
                    $notificationService->createNotification(
                        $this->demoUserId,
                        $this->demoCondominiumId,
                        'occurrence',
                        'Nova Ocorrência',
                        'Uma nova ocorrência foi reportada: ' . $occurrence['title'],
                        BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/occurrences/' . $occurrence['id']
                    );
                    $count++;
                }
            }
        }

        // 2. Notifications for all users about assemblies
        if (!empty($assemblies)) {
            foreach ($assemblies as $assembly) {
                // Notify all users about scheduled assemblies
                if ($assembly['status'] === 'scheduled') {
                    foreach ($allUsers as $user) {
                        $notificationService->createNotification(
                            $user['user_id'],
                            $this->demoCondominiumId,
                            'assembly',
                            'Nova Assembleia Agendada',
                            'Uma assembleia foi agendada: ' . $assembly['title'],
                            BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/assemblies/' . $assembly['id']
                        );
                        $count++;
                    }
                }
            }
        }

        // 3. Notifications for users with overdue fees
        foreach ($overdueFees as $fee) {
            $notificationService->createNotification(
                $fee['user_id'],
                $this->demoCondominiumId,
                'fee_overdue',
                'Quota em Atraso',
                'Tem uma quota em atraso. Valor: €' . number_format($fee['amount'], 2, ',', '.'),
                BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/fees'
            );
            $count++;
        }

        // 4. Create some random notifications for variety
        $notificationTypes = [
            [
                'type' => 'message',
                'title' => 'Nova Mensagem',
                'message' => 'Recebeu uma nova mensagem do administrador do condomínio.',
                'link' => BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/messages'
            ],
            [
                'type' => 'occurrence_comment',
                'title' => 'Novo Comentário em Ocorrência',
                'message' => 'Um novo comentário foi adicionado a uma ocorrência que reportou.',
                'link' => BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/occurrences'
            ]
        ];

        // Add a few random notifications to some users (including demo user)
        $randomUsers = array_slice($allUsers, 0, min(3, count($allUsers)));
        foreach ($randomUsers as $user) {
            $notificationType = $notificationTypes[array_rand($notificationTypes)];
            $notificationService->createNotification(
                $user['user_id'],
                $this->demoCondominiumId,
                $notificationType['type'],
                $notificationType['title'],
                $notificationType['message'],
                $notificationType['link']
            );
            $count++;
        }

        // 5. Create some read notifications (to show variety)
        // Always create read notifications for demo user (admin)
        if ($this->demoUserId) {
            // Create a few read notifications for the admin
            $readNotifications = [
                [
                    'type' => 'assembly',
                    'title' => 'Assembleia Concluída',
                    'message' => 'A assembleia geral foi concluída com sucesso.',
                    'link' => BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/assemblies'
                ],
                [
                    'type' => 'occurrence',
                    'title' => 'Ocorrência Resolvida',
                    'message' => 'Uma ocorrência foi marcada como resolvida.',
                    'link' => BASE_URL . 'condominiums/' . $this->demoCondominiumId . '/occurrences'
                ]
            ];

            foreach ($readNotifications as $notif) {
                $stmt = $this->db->prepare("
                    INSERT INTO notifications (user_id, condominium_id, type, title, message, link, is_read, read_at, created_at)
                    VALUES (:user_id, :condominium_id, :type, :title, :message, :link, TRUE, DATE_SUB(NOW(), INTERVAL :days_ago DAY), DATE_SUB(NOW(), INTERVAL :days_ago DAY))
                ");
                $daysAgo = rand(1, 7);
                $stmt->execute([
                    ':user_id' => $this->demoUserId,
                    ':condominium_id' => $this->demoCondominiumId,
                    ':type' => $notif['type'],
                    ':title' => $notif['title'],
                    ':message' => $notif['message'],
                    ':link' => $notif['link'],
                    ':days_ago' => $daysAgo
                ]);
                $count++;
            }
        }

        echo "   {$count} notificações criadas\n";
    }

    protected function createBudget2026(int $condominiumIndex = 0): void
    {
        echo "15a. Criando orçamento 2026...\n";

        // Only for Condominium 1
        if ($condominiumIndex !== 0) {
            return;
        }

        $budgetModel = new Budget();
        $budgetItemModel = new BudgetItem();

        // Check if budget exists
        $existingBudget = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2026);

        if ($existingBudget) {
            $budgetId = $existingBudget['id'];
            echo "   Orçamento 2026 já existe (ID: {$budgetId})\n";
        } else {
            // Get 2025 budget as base
            $budget2025 = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2025);

            if (!$budget2025) {
                echo "   Erro: Orçamento 2025 não encontrado. Não é possível criar orçamento 2026.\n";
                return;
            }

            // Get 2025 budget items
            $items2025 = $budgetItemModel->getByBudget($budget2025['id']);

            // Calculate totals with 7% increase
            $totalRevenue = 0;
            $totalExpenses = 0;

            foreach ($items2025 as $item) {
                if (strpos($item['category'], 'Receita:') === 0) {
                    $totalRevenue += $item['amount'] * 1.07; // 7% increase
                } else {
                    $totalExpenses += $item['amount'] * 1.07; // 7% increase
                }
            }

            $totalAmount = $totalRevenue - $totalExpenses;

            $budgetId = $budgetModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'year' => 2026,
                'status' => 'approved',
                'total_amount' => $totalAmount,
                'notes' => 'Orçamento demo 2026 - Aprovado'
            ]);
            echo "   Orçamento 2026 criado (ID: {$budgetId})\n";
        }

        // Delete existing items
        $this->db->exec("DELETE FROM budget_items WHERE budget_id = {$budgetId}");

        // Get 2025 budget items
        $budget2025 = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2025);
        $items2025 = $budgetItemModel->getByBudget($budget2025['id']);

        // Create 2026 items with 7% increase
        foreach ($items2025 as $item) {
            $budgetItemModel->create([
                'budget_id' => $budgetId,
                'category' => $item['category'],
                'amount' => round($item['amount'] * 1.07, 2),
                'description' => $item['description'] . ' (2026)'
            ]);
        }

        // Add revenue item for works quota extra
        $budgetItemModel->create([
            'budget_id' => $budgetId,
            'category' => 'Receita: Quota Extra - Obras',
            'amount' => 1666.67,
            'description' => 'Receita da quota extra para obras de renovação'
        ]);

        // Update budget total amount to include works revenue
        $stmt = $this->db->prepare("
            SELECT
                SUM(CASE WHEN category LIKE 'Receita:%' THEN amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN category NOT LIKE 'Receita:%' THEN amount ELSE 0 END) as total_expenses
            FROM budget_items
            WHERE budget_id = :budget_id
        ");
        $stmt->execute([':budget_id' => $budgetId]);
        $totals = $stmt->fetch();

        if ($totals) {
            $newTotalAmount = $totals['total_revenue'] - $totals['total_expenses'];
            $this->db->exec("UPDATE budgets SET total_amount = {$newTotalAmount} WHERE id = {$budgetId}");
        }

        echo "   Itens do orçamento 2026 criados\n";
    }

    protected function generateFees2026(int $condominiumIndex = 0): void
    {
        echo "15b. Gerando quotas 2026...\n";

        // Only for Condominium 1
        if ($condominiumIndex !== 0) {
            return;
        }

        $feeService = new FeeService();
        $feeModel = new Fee();
        $feePaymentModel = new FeePayment();
        $transactionModel = new FinancialTransaction();
        $faModel = new FractionAccount();
        $liquidationService = new LiquidationService();

        // Generate regular fees for all months in 2026
        $feesGenerated = false;
        for ($month = 1; $month <= 12; $month++) {
            try {
                $feeService->generateMonthlyFees($this->demoCondominiumId, 2026, $month);
                $feesGenerated = true;
            } catch (\Exception $e) {
                // If fees already exist, continue
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }

        // Mark annual fees as generated for 2026 budget
        if ($feesGenerated) {
            $budgetModel = new Budget();
            $budget2026 = $budgetModel->getByCondominiumAndYear($this->demoCondominiumId, 2026);
            if ($budget2026) {
                $budgetModel->markAnnualFeesGenerated($budget2026['id']);
                echo "   Quotas anuais marcadas como geradas para o orçamento 2026\n";
            }
        }

        // Get all regular fees for 2026
        $stmt = $this->db->prepare("
            SELECT f.* FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.period_year = 2026
            AND f.fee_type = 'regular'
            ORDER BY f.fraction_id ASC, f.period_month ASC
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $regularFees = $stmt->fetchAll();

        // Group regular fees by fraction and sort by month
        $regularFeesByFraction = [];
        foreach ($regularFees as $fee) {
            $fractionId = (int)$fee['fraction_id'];
            if (!isset($regularFeesByFraction[$fractionId])) {
                $regularFeesByFraction[$fractionId] = [];
            }
            $regularFeesByFraction[$fractionId][] = $fee;
        }

        // Sort each fraction's fees by month
        foreach ($regularFeesByFraction as $fractionId => $fractionFees) {
            usort($regularFeesByFraction[$fractionId], function($a, $b) {
                return $a['period_month'] <=> $b['period_month'];
            });
        }

        // Pay fees for 2026
        // IMPORTANT: Only pay 2026 fees if there are no pending debts from 2025 (sequential payment rule)
        // 5 fractions (1B, 3A, 3B, 4A, 4B) will have all 2026 fees paid up to current month if no debts from 2025
        // Get current month to limit payments
        $currentYear = (int)date('Y');
        $currentMonth = (int)date('n'); // 1-12

        $fraction1BId2026 = $this->fractionIds['1B'] ?? null;
        $fraction3AId2026 = $this->fractionIds['3A'] ?? null;
        $fraction3BId2026 = $this->fractionIds['3B'] ?? null;
        $fraction4AId2026 = $this->fractionIds['4A'] ?? null;
        $fraction4BId2026 = $this->fractionIds['4B'] ?? null;

        // 5 fractions with all fees paid: 1B, 3A, 3B, 4A, 4B
        $fullyPaidFractionIds2026 = [
            $fraction1BId2026,
            $fraction3AId2026,
            $fraction3BId2026,
            $fraction4AId2026,
            $fraction4BId2026
        ];

        $paidCount = 0;
        foreach ($regularFeesByFraction as $fractionId => $fractionFees) {
            // Check if this fraction has any pending debts from 2025
            $stmt = $this->db->prepare("
                SELECT f.id, f.amount, COALESCE(SUM(fp.amount), 0) as paid_amount
                FROM fees f
                LEFT JOIN fee_payments fp ON fp.fee_id = f.id
                WHERE f.condominium_id = :condominium_id
                AND f.fraction_id = :fraction_id
                AND f.period_year = 2025
                AND f.status != 'paid'
                GROUP BY f.id
                HAVING (f.amount - paid_amount) > 0
            ");
            $stmt->execute([
                ':condominium_id' => $this->demoCondominiumId,
                ':fraction_id' => $fractionId
            ]);
            $pendingDebts = $stmt->fetchAll();

            // Only pay 2026 fees if there are no pending debts from 2025
            if (empty($pendingDebts)) {
                // Determine how many months to pay
                if (in_array($fractionId, $fullyPaidFractionIds2026)) {
                    $monthsToPay = ($currentYear == 2026) ? min($currentMonth, count($fractionFees)) : count($fractionFees);
                } else {
                    $monthsToPay = ($currentYear == 2026) ? min(4, $currentMonth, count($fractionFees)) : min(4, count($fractionFees));
                }

                $feesToPay = [];
                $totalAmount = 0.0;
                for ($i = 0; $i < $monthsToPay && $i < count($fractionFees); $i++) {
                    $fee = $fractionFees[$i];
                    if ($currentYear == 2026 && $fee['period_month'] > $currentMonth) {
                        continue;
                    }
                    $remaining = (float)$fee['amount'] - $feePaymentModel->getTotalPaid($fee['id']);
                    if ($remaining > 0) {
                        $feesToPay[] = $fee;
                        $totalAmount += $remaining;
                    }
                }

                if ($totalAmount > 0 && !empty($feesToPay)) {
                    $firstFee = $feesToPay[0];
                    $lastFee = $feesToPay[count($feesToPay) - 1];
                    $paymentDate = "2026-" . str_pad($firstFee['period_month'], 2, '0', STR_PAD_LEFT) . "-" . rand(5, 25);
                    $monthsRange = count($feesToPay) === 1
                        ? (int)$firstFee['period_month']
                        : (int)$firstFee['period_month'] . '-' . (int)$lastFee['period_month'];
                    $desc = "Pagamento quotas regulares 2026 (meses {$monthsRange})";

                    $account = $faModel->getOrCreate($fractionId, $this->demoCondominiumId);
                    $accountId = (int)$account['id'];

                    $transactionId = $transactionModel->create([
                        'condominium_id' => $this->demoCondominiumId,
                        'bank_account_id' => $this->accountIds[0],
                        'fraction_id' => $fractionId,
                        'transaction_type' => 'income',
                        'amount' => $totalAmount,
                        'transaction_date' => $paymentDate,
                        'description' => $desc,
                        'category' => 'Quotas',
                        'income_entry_type' => 'quota',
                        'reference' => 'REF' . $this->demoCondominiumId . $fractionId . 'R' . rand(1000, 9999),
                        'related_type' => 'fraction_account',
                        'related_id' => $accountId,
                        'created_by' => $this->demoUserId
                    ]);

                    $faModel->addCredit($accountId, $totalAmount, 'quota_payment', $transactionId, $desc);
                    $liquidationService->liquidate($fractionId, $this->demoUserId, $paymentDate, $transactionId);
                    $paidCount += count($feesToPay);
                }
            } else {
                $fractionStmt = $this->db->prepare("SELECT identifier FROM fractions WHERE id = :fraction_id");
                $fractionStmt->execute([':fraction_id' => $fractionId]);
                $fraction = $fractionStmt->fetch();
                $fractionIdentifier = $fraction ? $fraction['identifier'] : "ID:{$fractionId}";
                echo "   Fração {$fractionIdentifier}: Não pagando quotas 2026 devido a dívidas pendentes de 2025\n";
            }
        }

        // Generate extra fee for works (€1666.67 total, distributed across all months)
        echo "   Gerando quota extra para obras...\n";
        try {
            $extraFeeIds = $feeService->generateExtraFees(
                $this->demoCondominiumId,
                2026,
                [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], // All months
                1666.67, // Total amount
                'Quota Extra - Obras de Renovação',
                [] // All fractions
            );

            echo "   " . count($extraFeeIds) . " quotas extras geradas\n";
        } catch (\Exception $e) {
            echo "   Aviso: Erro ao gerar quotas extras: " . $e->getMessage() . "\n";
        }

        // Get all extra fees for 2026
        $stmt = $this->db->prepare("
            SELECT f.* FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.period_year = 2026
            AND f.fee_type = 'extra'
            ORDER BY f.fraction_id ASC, f.period_month ASC
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $extraFees = $stmt->fetchAll();

        // Group extra fees by fraction and sort by month
        $extraFeesByFraction = [];
        foreach ($extraFees as $fee) {
            $fractionId = (int)$fee['fraction_id'];
            if (!isset($extraFeesByFraction[$fractionId])) {
                $extraFeesByFraction[$fractionId] = [];
            }
            $extraFeesByFraction[$fractionId][] = $fee;
        }

        // Sort each fraction's fees by month
        foreach ($extraFeesByFraction as $fractionId => $fractionFees) {
            usort($extraFeesByFraction[$fractionId], function($a, $b) {
                return $a['period_month'] <=> $b['period_month'];
            });
        }

        // Pay extra fees for 2026
        // IMPORTANT: Only pay 2026 extra fees if there are no pending debts from 2025 (sequential payment rule)
        // 5 fractions (1B, 3A, 3B, 4A, 4B) will have all extra fees paid up to current month if no debts from 2025
        $extraPaidCount = 0;
        foreach ($extraFeesByFraction as $fractionId => $fractionFees) {
            // Check if this fraction has any pending debts from 2025
            $stmt = $this->db->prepare("
                SELECT f.id, f.amount, COALESCE(SUM(fp.amount), 0) as paid_amount
                FROM fees f
                LEFT JOIN fee_payments fp ON fp.fee_id = f.id
                WHERE f.condominium_id = :condominium_id
                AND f.fraction_id = :fraction_id
                AND f.period_year = 2025
                AND f.status != 'paid'
                GROUP BY f.id
                HAVING (f.amount - paid_amount) > 0
            ");
            $stmt->execute([
                ':condominium_id' => $this->demoCondominiumId,
                ':fraction_id' => $fractionId
            ]);
            $pendingDebts = $stmt->fetchAll();

            // Only pay extra fees if there are no pending debts from 2025
            if (empty($pendingDebts)) {
                if (in_array($fractionId, $fullyPaidFractionIds2026)) {
                    $monthsToPay = ($currentYear == 2026) ? min($currentMonth, count($fractionFees)) : count($fractionFees);
                } else {
                    $monthsToPay = ($currentYear == 2026) ? min(3, $currentMonth, count($fractionFees)) : min(3, count($fractionFees));
                }

                $feesToPay = [];
                $totalAmount = 0.0;
                for ($i = 0; $i < $monthsToPay && $i < count($fractionFees); $i++) {
                    $fee = $fractionFees[$i];
                    if ($currentYear == 2026 && $fee['period_month'] > $currentMonth) {
                        continue;
                    }
                    $remaining = (float)$fee['amount'] - $feePaymentModel->getTotalPaid($fee['id']);
                    if ($remaining > 0) {
                        $feesToPay[] = $fee;
                        $totalAmount += $remaining;
                    }
                }

                if ($totalAmount > 0 && !empty($feesToPay)) {
                    $firstFee = $feesToPay[0];
                    $lastFee = $feesToPay[count($feesToPay) - 1];
                    $paymentDate = "2026-" . str_pad($firstFee['period_month'], 2, '0', STR_PAD_LEFT) . "-" . rand(5, 25);
                    $monthsRange = count($feesToPay) === 1
                        ? (int)$firstFee['period_month']
                        : (int)$firstFee['period_month'] . '-' . (int)$lastFee['period_month'];
                    $desc = "Pagamento quotas extras 2026 (meses {$monthsRange})";

                    $account = $faModel->getOrCreate($fractionId, $this->demoCondominiumId);
                    $accountId = (int)$account['id'];

                    $transactionId = $transactionModel->create([
                        'condominium_id' => $this->demoCondominiumId,
                        'bank_account_id' => $this->accountIds[0],
                        'fraction_id' => $fractionId,
                        'transaction_type' => 'income',
                        'amount' => $totalAmount,
                        'transaction_date' => $paymentDate,
                        'description' => $desc,
                        'category' => 'Quotas',
                        'income_entry_type' => 'quota',
                        'reference' => 'REF' . $this->demoCondominiumId . $fractionId . 'E' . rand(1000, 9999),
                        'related_type' => 'fraction_account',
                        'related_id' => $accountId,
                        'created_by' => $this->demoUserId
                    ]);

                    $faModel->addCredit($accountId, $totalAmount, 'quota_payment', $transactionId, $desc);
                    $liquidationService->liquidate($fractionId, $this->demoUserId, $paymentDate, $transactionId);
                    $extraPaidCount += count($feesToPay);
                }
            } else {
                $fractionStmt = $this->db->prepare("SELECT identifier FROM fractions WHERE id = :fraction_id");
                $fractionStmt->execute([':fraction_id' => $fractionId]);
                $fraction = $fractionStmt->fetch();
                $fractionIdentifier = $fraction ? $fraction['identifier'] : "ID:{$fractionId}";
                echo "   Fração {$fractionIdentifier}: Não pagando quotas extras 2026 devido a dívidas pendentes de 2025\n";
            }
        }

        // Update account balances
        $bankAccountModel = new BankAccount();
        foreach ($this->accountIds as $baId) {
            $bankAccountModel->updateBalance($baId);
        }

        echo "   {$paidCount} quotas regulares pagas, {$extraPaidCount} quotas extras pagas\n";
    }

    protected function createReceiptsForDemoPayments(int $condominiumIndex = 0): void
    {
        echo "15. Verificando recibos demo...\n";

        $receiptModel = new \App\Models\Receipt();
        $feeModel = new Fee();
        $feePaymentModel = new FeePayment();

        // Check which fees are fully paid and don't have receipts yet
        $stmt = $this->db->prepare("
            SELECT f.id as fee_id, f.condominium_id, f.fraction_id, f.period_year, f.period_month, f.amount,
                   fr.identifier as fraction_identifier
            FROM fees f
            INNER JOIN fractions fr ON fr.id = f.fraction_id
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'paid'
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $fees = $stmt->fetchAll();

        $receiptCount = 0;
        $existingCount = 0;
        $updatedCount = 0;

        // Get saved demo receipts for this condominium (if any)
        $savedReceipts = $this->savedDemoReceipts[$this->demoCondominiumId] ?? [];

        foreach ($fees as $feeData) {
            $feeId = $feeData['fee_id'];
            $fractionId = $feeData['fraction_id'];
            $fractionIdentifier = $feeData['fraction_identifier'];
            $periodYear = $feeData['period_year'];
            $periodMonth = $feeData['period_month'] ?? null;

            // First, try to match with a saved demo receipt (from before restore)
            // Match by fraction identifier + period (since IDs change after restore)
            $matchedReceipt = null;
            foreach ($savedReceipts as $savedReceipt) {
                if ($savedReceipt['fraction_identifier'] == $fractionIdentifier
                    && $savedReceipt['period_year'] == $periodYear
                    && ($savedReceipt['period_month'] ?? null) == $periodMonth) {
                    $matchedReceipt = $savedReceipt;
                    break;
                }
            }

            if ($matchedReceipt) {
                // Update existing receipt with new fee_id
                $updateStmt = $this->db->prepare("
                    UPDATE receipts
                    SET fee_id = :fee_id,
                        file_path = '',
                        file_name = '',
                        file_size = 0
                    WHERE id = :receipt_id
                ");
                $updateStmt->execute([
                    ':fee_id' => $feeId,
                    ':receipt_id' => $matchedReceipt['id']
                ]);

                // Regenerate PDF if file doesn't exist
                $filePath = $matchedReceipt['file_path'] ?? '';
                $fullPath = '';
                if ($filePath) {
                    if (strpos($filePath, 'condominiums/') === 0) {
                        $fullPath = __DIR__ . '/../../storage/' . $filePath;
                    } else {
                        $fullPath = __DIR__ . '/../../storage/documents/' . $filePath;
                    }
                }

                if (!$filePath || !file_exists($fullPath)) {
                    // Regenerate PDF
                    try {
                        $fee = $feeModel->findById($feeId);
                        if ($fee) {
                            $fractionModel = new Fraction();
                            $fraction = $fractionModel->findById($fee['fraction_id']);
                            if ($fraction) {
                                $condominiumModel = new Condominium();
                                $condominium = $condominiumModel->findById($this->demoCondominiumId);
                                if ($condominium) {
                                    $pdfService = new \App\Services\PdfService();
                                    $htmlContent = $pdfService->generateReceiptReceipt($fee, $fraction, $condominium, null, 'final');
                                    $periodYear = (int)$fee['period_year'];
                                    $fractionIdentifier = $fraction['identifier'] ?? '';
                                    $newFilePath = $pdfService->generateReceiptPdf($htmlContent, $matchedReceipt['id'], $matchedReceipt['receipt_number'], $this->demoCondominiumId, $fractionIdentifier, (string)$periodYear, $this->demoUserId);
                                    $newFullPath = __DIR__ . '/../../storage/' . $newFilePath;
                                    $fileSize = file_exists($newFullPath) ? filesize($newFullPath) : 0;
                                    $fileName = basename($newFilePath);

                                    $updateFileStmt = $this->db->prepare("
                                        UPDATE receipts
                                        SET file_path = :file_path, file_name = :file_name, file_size = :file_size
                                        WHERE id = :id
                                    ");
                                    $updateFileStmt->execute([
                                        ':file_path' => $newFilePath,
                                        ':file_name' => $fileName,
                                        ':file_size' => $fileSize,
                                        ':id' => $matchedReceipt['id']
                                    ]);

                                    // Create/update entry in documents table
                                    try {
                                        $receiptFolderService = new \App\Services\ReceiptFolderService();
                                        $folderPath = $receiptFolderService->ensureReceiptFolders($this->demoCondominiumId, (string)$periodYear, $fractionIdentifier, $this->demoUserId);

                                        // Check if document entry already exists
                                        $docStmt = $this->db->prepare("
                                            SELECT id FROM documents 
                                            WHERE condominium_id = :condominium_id 
                                            AND document_type = 'receipt' 
                                            AND file_path = :file_path
                                            LIMIT 1
                                        ");
                                        $docStmt->execute([
                                            ':condominium_id' => $this->demoCondominiumId,
                                            ':file_path' => $newFilePath
                                        ]);
                                        $existingDoc = $docStmt->fetch();

                                        if (!$existingDoc) {
                                            $documentModel = new \App\Models\Document();
                                            $documentModel->create([
                                                'condominium_id' => $this->demoCondominiumId,
                                                'fraction_id' => $fee['fraction_id'],
                                                'folder' => $folderPath,
                                                'title' => 'Recibo ' . $matchedReceipt['receipt_number'],
                                                'description' => 'Recibo demo - Fração: ' . $fractionIdentifier . ' - Ano: ' . $periodYear,
                                                'file_path' => $newFilePath,
                                                'file_name' => $fileName,
                                                'file_size' => $fileSize,
                                                'mime_type' => 'application/pdf',
                                                'visibility' => 'fraction',
                                                'document_type' => 'receipt',
                                                'uploaded_by' => $this->demoUserId
                                            ]);
                                        }
                                    } catch (\Exception $e) {
                                        echo "   Aviso: Erro ao criar entrada em documents para recibo {$matchedReceipt['id']}: " . $e->getMessage() . "\n";
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        echo "   Aviso: Erro ao regenerar PDF do recibo {$matchedReceipt['id']}: " . $e->getMessage() . "\n";
                    }
                }

                $updatedCount++;
                $existingCount++;
                continue;
            }

            // Check if receipt already exists for this fee (generated by demo user)
            $existingReceipts = $receiptModel->getByFee($feeId);
            $hasDemoReceipt = false;
            foreach ($existingReceipts as $r) {
                if ($r['receipt_type'] === 'final' && $r['generated_by'] == $this->demoUserId) {
                    $hasDemoReceipt = true;
                    $existingCount++;
                    break;
                }
            }

            // Only create receipt if it doesn't exist (generated by demo user)
            if (!$hasDemoReceipt) {
                // Verify fee is still fully paid
                $totalPaid = $feePaymentModel->getTotalPaid($feeId);
                if ($totalPaid >= (float)$feeData['amount']) {
                    try {
                        $fee = $feeModel->findById($feeId);
                        if (!$fee) {
                            continue;
                        }

                        $fractionModel = new Fraction();
                        $fraction = $fractionModel->findById($fee['fraction_id']);
                        if (!$fraction) {
                            continue;
                        }

                        $condominiumModel = new Condominium();
                        $condominium = $condominiumModel->findById($this->demoCondominiumId);
                        if (!$condominium) {
                            continue;
                        }

                        $pdfService = new \App\Services\PdfService();
                        $periodYear = (int)$fee['period_year'];
                        $receiptNumber = $receiptModel->generateReceiptNumber($this->demoCondominiumId, $periodYear);
                        $fractionIdentifier = $fraction['identifier'] ?? '';
                        $htmlContent = $pdfService->generateReceiptReceipt($fee, $fraction, $condominium, null, 'final');

                        // Create receipt record
                        $receiptId = $receiptModel->create([
                            'fee_id' => $feeId,
                            'fee_payment_id' => null,
                            'condominium_id' => $this->demoCondominiumId,
                            'fraction_id' => $fee['fraction_id'],
                            'receipt_number' => $receiptNumber,
                            'receipt_type' => 'final',
                            'amount' => $fee['amount'],
                            'file_path' => '',
                            'file_name' => '',
                            'file_size' => 0,
                            'generated_at' => date('Y-m-d H:i:s'),
                            'generated_by' => $this->demoUserId
                        ]);

                        // Generate PDF with folder structure
                        $filePath = $pdfService->generateReceiptPdf($htmlContent, $receiptId, $receiptNumber, $this->demoCondominiumId, $fractionIdentifier, (string)$periodYear, $this->demoUserId);
                        $fullPath = __DIR__ . '/../../storage/' . $filePath;
                        $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
                        $fileName = basename($filePath);

                        // Update receipt with file info
                        $stmt = $this->db->prepare("
                            UPDATE receipts
                            SET file_path = :file_path, file_name = :file_name, file_size = :file_size
                            WHERE id = :id
                        ");
                        $stmt->execute([
                            ':file_path' => $filePath,
                            ':file_name' => $fileName,
                            ':file_size' => $fileSize,
                            ':id' => $receiptId
                        ]);

                        // Create entry in documents table
                        try {
                            $receiptFolderService = new \App\Services\ReceiptFolderService();
                            $folderPath = $receiptFolderService->ensureReceiptFolders($this->demoCondominiumId, (string)$periodYear, $fractionIdentifier, $this->demoUserId);

                            $documentModel = new \App\Models\Document();
                            $documentModel->create([
                                'condominium_id' => $this->demoCondominiumId,
                                'fraction_id' => $fee['fraction_id'],
                                'folder' => $folderPath,
                                'title' => 'Recibo ' . $receiptNumber,
                                'description' => 'Recibo demo - Fração: ' . $fractionIdentifier . ' - Ano: ' . $periodYear,
                                'file_path' => $filePath,
                                'file_name' => $fileName,
                                'file_size' => $fileSize,
                                'mime_type' => 'application/pdf',
                                'visibility' => 'fraction',
                                'document_type' => 'receipt',
                                'uploaded_by' => $this->demoUserId
                            ]);
                        } catch (\Exception $e) {
                            echo "   Aviso: Erro ao criar entrada em documents para recibo {$receiptId}: " . $e->getMessage() . "\n";
                        }

                        $receiptCount++;
                    } catch (\Exception $e) {
                        echo "   Aviso: Erro ao criar recibo para quota {$feeId}: " . $e->getMessage() . "\n";
                    }
                }
            }
        }

        if ($receiptCount > 0 || $updatedCount > 0) {
            $message = [];
            if ($receiptCount > 0) {
                $message[] = "{$receiptCount} criados";
            }
            if ($updatedCount > 0) {
                $message[] = "{$updatedCount} atualizados";
            }
            if ($existingCount > 0) {
                $message[] = "{$existingCount} já existiam";
            }
            echo "   " . implode(", ", $message) . "\n";
        } else {
            echo "   {$existingCount} recibos demo já existem\n";
        }

        // Clear saved receipts for this condominium
        if (isset($this->savedDemoReceipts[$this->demoCondominiumId])) {
            unset($this->savedDemoReceipts[$this->demoCondominiumId]);
        }
    }

    protected function createStandaloneVotes(int $condominiumIndex = 0): void
    {
        echo "17. Criando votações standalone...\n";

        // Check if standalone votes already exist for this condominium
        $stmt = $this->db->prepare("SELECT id FROM standalone_votes WHERE condominium_id = :condominium_id");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $existingVotes = $stmt->fetchAll();

        if (!empty($existingVotes)) {
            echo "   Votações standalone já existem para este condomínio, a saltar criação.\n";
            return;
        }

        if (empty($this->fractionIds)) {
            echo "   Aviso: Nenhuma fração encontrada. Pulando criação de votações.\n";
            return;
        }

        $voteModel = new StandaloneVote();
        $responseModel = new StandaloneVoteResponse();
        $optionModel = new VoteOption();

        // Get vote options for this condominium
        $options = $optionModel->getByCondominium($this->demoCondominiumId);
        if (empty($options)) {
            echo "   Aviso: Nenhuma opção de voto encontrada. Criando opções padrão...\n";
            // Create default options
            $defaultOptions = [
                ['label' => 'A favor', 'order' => 1, 'is_default' => true],
                ['label' => 'Contra', 'order' => 2, 'is_default' => true],
                ['label' => 'Abstenção', 'order' => 3, 'is_default' => true]
            ];
            foreach ($defaultOptions as $opt) {
                $optionModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'option_label' => $opt['label'],
                    'order_index' => $opt['order'],
                    'is_default' => $opt['is_default'],
                    'is_active' => true
                ]);
            }
            $options = $optionModel->getByCondominium($this->demoCondominiumId);
        }

        $optionIds = array_column($options, 'id');
        $favorOptionId = $optionIds[0] ?? null;
        $contraOptionId = $optionIds[1] ?? null;
        $abstencaoOptionId = $optionIds[2] ?? null;

        if (!$favorOptionId || !$contraOptionId || !$abstencaoOptionId) {
            echo "   Aviso: Opções de voto insuficientes. Pulando criação de votações.\n";
            return;
        }

        // Get fractions with users
        $fractionsWithUsers = [];
        foreach ($this->fractionIds as $fractionId) {
            $stmt = $this->db->prepare("
                SELECT cu.user_id, cu.fraction_id, f.identifier
                FROM condominium_users cu
                INNER JOIN fractions f ON f.id = cu.fraction_id
                WHERE cu.condominium_id = :condominium_id AND cu.fraction_id = :fraction_id
                LIMIT 1
            ");
            $stmt->execute([
                ':condominium_id' => $this->demoCondominiumId,
                ':fraction_id' => $fractionId
            ]);
            $result = $stmt->fetch();
            if ($result) {
                $fractionsWithUsers[] = $result;
            }
        }

        if (empty($fractionsWithUsers)) {
            echo "   Aviso: Nenhuma fração com utilizador encontrada. Pulando criação de votações.\n";
            return;
        }

        // Vote 1: Closed - "Aprovação do Orçamento 2026"
        $vote1Id = $voteModel->create([
            'condominium_id' => $this->demoCondominiumId,
            'title' => 'Aprovação do Orçamento 2026',
            'description' => 'Votação para aprovação do orçamento anual de 2026, incluindo todas as despesas previstas e receitas estimadas.',
            'allowed_options' => $optionIds,
            'status' => 'closed',
            'created_by' => $this->demoUserId,
            'voting_started_at' => date('Y-m-d H:i:s', strtotime('-15 days')),
            'voting_ended_at' => date('Y-m-d H:i:s', strtotime('-5 days'))
        ]);

        // Add votes for vote 1 (mostly "A favor")
        $vote1Votes = 0;
        foreach ($fractionsWithUsers as $index => $fraction) {
            if ($vote1Votes >= 8) break; // Limit to 8 votes

            $optionId = ($index % 3 === 0) ? $contraOptionId : (($index % 3 === 1) ? $abstencaoOptionId : $favorOptionId);

            $responseModel->createOrUpdate([
                'standalone_vote_id' => $vote1Id,
                'fraction_id' => $fraction['fraction_id'],
                'user_id' => $fraction['user_id'],
                'vote_option_id' => $optionId,
                'weighted_value' => 1.0,
                'notes' => null
            ]);
            $vote1Votes++;
        }
        echo "   Votação 1 criada (Fechada): {$vote1Votes} votos\n";

        // Vote 2: Closed - "Renovação da Piscina"
        $vote2Id = $voteModel->create([
            'condominium_id' => $this->demoCondominiumId,
            'title' => 'Renovação da Piscina',
            'description' => 'Votação para aprovar a renovação completa da piscina, incluindo sistema de filtragem e revestimento.',
            'allowed_options' => $optionIds,
            'status' => 'closed',
            'created_by' => $this->demoUserId,
            'voting_started_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'voting_ended_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ]);

        // Add votes for vote 2 (mixed)
        $vote2Votes = 0;
        foreach ($fractionsWithUsers as $index => $fraction) {
            if ($vote2Votes >= 7) break; // Limit to 7 votes

            $optionId = ($index % 4 === 0) ? $contraOptionId : (($index % 4 === 1) ? $abstencaoOptionId : $favorOptionId);

            $responseModel->createOrUpdate([
                'standalone_vote_id' => $vote2Id,
                'fraction_id' => $fraction['fraction_id'],
                'user_id' => $fraction['user_id'],
                'vote_option_id' => $optionId,
                'weighted_value' => 1.0,
                'notes' => null
            ]);
            $vote2Votes++;
        }
        echo "   Votação 2 criada (Fechada): {$vote2Votes} votos\n";

        // Vote 3: Open - "Instalação de Painéis Solares"
        $vote3Id = $voteModel->create([
            'condominium_id' => $this->demoCondominiumId,
            'title' => 'Instalação de Painéis Solares',
            'description' => 'Votação para aprovar a instalação de painéis solares no telhado do edifício, visando reduzir os custos de energia elétrica.',
            'allowed_options' => $optionIds,
            'status' => 'open',
            'created_by' => $this->demoUserId,
            'voting_started_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'voting_ended_at' => null
        ]);

        // Add some votes for vote 3 (some users already voted)
        $vote3Votes = 0;
        foreach ($fractionsWithUsers as $index => $fraction) {
            if ($vote3Votes >= 5) break; // Limit to 5 votes (some users haven't voted yet)

            $optionId = ($index % 3 === 0) ? $contraOptionId : (($index % 3 === 1) ? $abstencaoOptionId : $favorOptionId);

            $responseModel->createOrUpdate([
                'standalone_vote_id' => $vote3Id,
                'fraction_id' => $fraction['fraction_id'],
                'user_id' => $fraction['user_id'],
                'vote_option_id' => $optionId,
                'weighted_value' => 1.0,
                'notes' => null
            ]);
            $vote3Votes++;
        }
        echo "   Votação 3 criada (Aberta): {$vote3Votes} votos\n";
    }

    /**
     * Track a created ID for a specific table
     */
    protected function trackId(string $table, int $id): void
    {
        if (isset($this->createdIds[$table])) {
            if (!in_array($id, $this->createdIds[$table])) {
                $this->createdIds[$table][] = $id;
            }
        }
    }

    /**
     * Get all created IDs for demo data
     * This method queries the database to capture all IDs related to demo condominiums
     *
     * @return array Array with table names as keys and arrays of IDs as values
     */
    public function getCreatedIds(): array
    {
        if (empty($this->demoCondominiumIds)) {
            return $this->createdIds;
        }

        $condominiumIdsList = implode(',', $this->demoCondominiumIds);

        // Query database to get all IDs for demo condominiums
        $ids = [
            'demo_user_id' => $this->demoUserId,
            'condominiums' => $this->demoCondominiumIds,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // Fractions
        $stmt = $this->db->prepare("SELECT id FROM fractions WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['fractions'] = array_column($stmt->fetchAll(), 'id');

        // Condominium users
        $stmt = $this->db->prepare("SELECT id FROM condominium_users WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['condominium_users'] = array_column($stmt->fetchAll(), 'id');

        // Bank accounts
        $stmt = $this->db->prepare("SELECT id FROM bank_accounts WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['bank_accounts'] = array_column($stmt->fetchAll(), 'id');

        // Suppliers
        $stmt = $this->db->prepare("SELECT id FROM suppliers WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['suppliers'] = array_column($stmt->fetchAll(), 'id');

        // Budgets
        $stmt = $this->db->prepare("SELECT id FROM budgets WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['budgets'] = array_column($stmt->fetchAll(), 'id');

        // Budget items
        $stmt = $this->db->prepare("SELECT id FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['budget_items'] = array_column($stmt->fetchAll(), 'id');

        // Fees
        $stmt = $this->db->prepare("SELECT id FROM fees WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['fees'] = array_column($stmt->fetchAll(), 'id');

        // Fee payments
        $stmt = $this->db->prepare("SELECT id FROM fee_payments WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['fee_payments'] = array_column($stmt->fetchAll(), 'id');

        // Fee payment history
        $stmt = $this->db->prepare("SELECT id FROM fee_payment_history WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['fee_payment_history'] = array_column($stmt->fetchAll(), 'id');

        // Financial transactions
        $stmt = $this->db->prepare("SELECT id FROM financial_transactions WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['financial_transactions'] = array_column($stmt->fetchAll(), 'id');

        // Assemblies
        $stmt = $this->db->prepare("SELECT id FROM assemblies WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['assemblies'] = array_column($stmt->fetchAll(), 'id');

        // Assembly attendees
        $stmt = $this->db->prepare("SELECT id FROM assembly_attendees WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['assembly_attendees'] = array_column($stmt->fetchAll(), 'id');

        // Assembly agenda points
        $stmt = $this->db->prepare("SELECT id FROM assembly_agenda_points WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['assembly_agenda_points'] = array_column($stmt->fetchAll(), 'id');

        // Assembly vote topics
        $stmt = $this->db->prepare("SELECT id FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['assembly_vote_topics'] = array_column($stmt->fetchAll(), 'id');

        // Assembly votes
        $stmt = $this->db->prepare("SELECT id FROM assembly_votes WHERE topic_id IN (SELECT id FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id IN ({$condominiumIdsList})))");
        $stmt->execute();
        $ids['assembly_votes'] = array_column($stmt->fetchAll(), 'id');

        // Minutes revisions
        $stmt = $this->db->prepare("SELECT id FROM minutes_revisions WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['minutes_revisions'] = array_column($stmt->fetchAll(), 'id');

        // Spaces
        $stmt = $this->db->prepare("SELECT id FROM spaces WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['spaces'] = array_column($stmt->fetchAll(), 'id');

        // Reservations
        $stmt = $this->db->prepare("SELECT id FROM reservations WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['reservations'] = array_column($stmt->fetchAll(), 'id');

        // Messages
        $stmt = $this->db->prepare("SELECT id FROM messages WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['messages'] = array_column($stmt->fetchAll(), 'id');

        // Occurrences
        $stmt = $this->db->prepare("SELECT id FROM occurrences WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['occurrences'] = array_column($stmt->fetchAll(), 'id');

        // Occurrence comments
        $stmt = $this->db->prepare("SELECT id FROM occurrence_comments WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['occurrence_comments'] = array_column($stmt->fetchAll(), 'id');

        // Occurrence history
        $stmt = $this->db->prepare("SELECT id FROM occurrence_history WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['occurrence_history'] = array_column($stmt->fetchAll(), 'id');

        // Standalone votes
        $stmt = $this->db->prepare("SELECT id FROM standalone_votes WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['standalone_votes'] = array_column($stmt->fetchAll(), 'id');

        // Vote options (all options for demo condominiums)
        $stmt = $this->db->prepare("SELECT id FROM vote_options WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['vote_options'] = array_column($stmt->fetchAll(), 'id');

        // Standalone vote responses
        $stmt = $this->db->prepare("SELECT id FROM standalone_vote_responses WHERE standalone_vote_id IN (SELECT id FROM standalone_votes WHERE condominium_id IN ({$condominiumIdsList}))");
        $stmt->execute();
        $ids['standalone_vote_responses'] = array_column($stmt->fetchAll(), 'id');

        // Receipts (only demo receipts)
        if ($this->demoUserId) {
            $stmt = $this->db->prepare("SELECT id FROM receipts WHERE condominium_id IN ({$condominiumIdsList}) AND generated_by = :demo_user_id");
            $stmt->execute([':demo_user_id' => $this->demoUserId]);
            $ids['receipts'] = array_column($stmt->fetchAll(), 'id');
        } else {
            $ids['receipts'] = [];
        }

        // Notifications
        $stmt = $this->db->prepare("SELECT id FROM notifications WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['notifications'] = array_column($stmt->fetchAll(), 'id');

        // Revenues
        $stmt = $this->db->prepare("SELECT id FROM revenues WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['revenues'] = array_column($stmt->fetchAll(), 'id');

        // Documents
        $stmt = $this->db->prepare("SELECT id FROM documents WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['documents'] = array_column($stmt->fetchAll(), 'id');

        // Contracts
        $stmt = $this->db->prepare("SELECT id FROM contracts WHERE condominium_id IN ({$condominiumIdsList})");
        $stmt->execute();
        $ids['contracts'] = array_column($stmt->fetchAll(), 'id');

        // Users (fraction users, not demo user)
        if ($this->demoUserId) {
            $stmt = $this->db->prepare("
                SELECT DISTINCT u.id
                FROM users u
                INNER JOIN condominium_users cu ON cu.user_id = u.id
                WHERE cu.condominium_id IN ({$condominiumIdsList})
                AND u.id != :demo_user_id
            ");
            $stmt->execute([':demo_user_id' => $this->demoUserId]);
            $ids['users'] = array_column($stmt->fetchAll(), 'id');
        } else {
            $ids['users'] = [];
        }

        return $ids;
    }
}

// Run seeder if executed directly
if (php_sapi_name() === 'cli') {
    global $db;
    $seeder = new DemoSeeder($db);
    $seeder->run();
}
