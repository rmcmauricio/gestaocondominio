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
use App\Models\Expense;
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
use App\Core\Security;
use App\Services\FeeService;
use App\Services\NotificationService;

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

                // 8. Create expenses 2025
                $this->createExpenses2025($index);

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

                // 14. Create occurrences
                $this->createOccurrences($index);

                // 15. Create receipts for demo payments
                $this->createReceiptsForDemoPayments($index);

                // 16. Create notifications
                $this->createNotifications($index);
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
            echo "   Utilizador demo já existe (ID: {$this->demoUserId})\n";
        } else {
            $userModel = new User();
            $this->demoUserId = $userModel->create([
                'email' => 'demo@predio.pt',
                'password' => 'Demo@2024',
                'name' => 'Utilizador Demo',
                'role' => 'admin',
                'status' => 'active'
            ]);
            echo "   Utilizador demo criado (ID: {$this->demoUserId})\n";
        }
    }

    protected function createDemoCondominiums(): void
    {
        echo "2. Criando condomínios demo...\n";

        // Check if demo condominiums exist
        $stmt = $this->db->prepare("SELECT id FROM condominiums WHERE is_demo = TRUE ORDER BY id ASC");
        $stmt->execute();
        $existingCondominiums = $stmt->fetchAll();

        // Define 2 distinct condominiums
        $condominiumsData = [
            [
                'name' => 'Residencial Sol Nascente',
                'address' => 'Rua das Flores, 123',
                'postal_code' => '1000-001',
                'city' => 'Lisboa',
                'country' => 'Portugal',
                'nif' => '500000000',
                'total_fractions' => 10
            ],
            [
                'name' => 'Edifício Mar Atlântico',
                'address' => 'Avenida da Praia, 456',
                'postal_code' => '4100-200',
                'city' => 'Porto',
                'country' => 'Portugal',
                'nif' => '500000001',
                'total_fractions' => 8
            ]
        ];

        $condominiumModel = new Condominium();
        $this->demoCondominiumIds = [];

        // Delete existing demo condominiums and recreate
        if (!empty($existingCondominiums)) {
            echo "   Removendo condomínios demo existentes...\n";
            foreach ($existingCondominiums as $existing) {
                $this->deleteCondominiumData($existing['id']);
                $this->db->exec("DELETE FROM condominiums WHERE id = {$existing['id']}");
            }
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
                    echo "   Removendo condomínio não-demo duplicado '{$data['name']}' (ID: {$dup['id']})...\n";
                    $this->deleteCondominiumData($dup['id']);
                    $this->db->exec("DELETE FROM condominiums WHERE id = {$dup['id']}");
                }
            }
        }

        // Create 2 distinct condominiums
        foreach ($condominiumsData as $index => $data) {
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
            $this->demoCondominiumIds[] = $condominiumId;
            echo "   Condomínio demo '{$data['name']}' criado (ID: {$condominiumId})\n";
        }
        
        // Set first condominium as default for demo user
        if (!empty($this->demoCondominiumIds)) {
            $userModel = new User();
            $userModel->setDefaultCondominium($this->demoUserId, $this->demoCondominiumIds[0]);
            echo "   Condomínio padrão definido para o utilizador demo (ID: {$this->demoCondominiumIds[0]})\n";
        }
    }

    /**
     * Delete all data for a specific condominium (used for cleaning duplicates)
     */
    protected function deleteCondominiumData(int $condominiumId): void
    {
        // Delete in correct order to respect foreign keys
        $this->db->exec("DELETE FROM minutes_signatures WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assembly_votes WHERE topic_id IN (SELECT id FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId}))");
        $this->db->exec("DELETE FROM assembly_vote_topics WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assembly_attendees WHERE assembly_id IN (SELECT id FROM assemblies WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM assemblies WHERE condominium_id = {$condominiumId}");
        $this->db->exec("UPDATE fee_payments SET financial_transaction_id = NULL WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM fee_payments WHERE fee_id IN (SELECT id FROM fees WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM fees WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM financial_transactions WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM bank_accounts WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM reservations WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM spaces WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM occurrence_comments WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM occurrence_history WHERE occurrence_id IN (SELECT id FROM occurrences WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM occurrences WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM expenses WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id = {$condominiumId})");
        $this->db->exec("DELETE FROM budgets WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM suppliers WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM condominium_users WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM fractions WHERE condominium_id = {$condominiumId}");
        // Delete receipts created by non-demo users (keep demo receipts)
        // Only delete receipts where generated_by is not the demo user
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
                    $filePath = __DIR__ . '/../../storage/documents/' . $receipt['file_path'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
            // Only delete receipts created by non-demo users
            $deleteStmt = $this->db->prepare("DELETE FROM receipts WHERE condominium_id = :condominium_id AND (generated_by IS NULL OR generated_by != :demo_user_id)");
            $deleteStmt->execute([
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
                    $filePath = __DIR__ . '/../../storage/documents/' . $receipt['file_path'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
            $deleteStmt = $this->db->prepare("DELETE FROM receipts WHERE condominium_id = :condominium_id");
            $deleteStmt->execute([':condominium_id' => $condominiumId]);
        }
        
        $this->db->exec("DELETE FROM documents WHERE condominium_id = {$condominiumId}");
        $this->db->exec("DELETE FROM notifications WHERE condominium_id = {$condominiumId}");

        // Delete users associated with this condominium (but not demo user)
        $stmt = $this->db->prepare("SELECT user_id FROM condominium_users WHERE condominium_id = {$condominiumId}");
        $stmt->execute();
        $userIds = $stmt->fetchAll();
        foreach ($userIds as $row) {
            if ($row['user_id'] != $this->demoUserId) {
                $this->db->exec("DELETE FROM users WHERE id = {$row['user_id']} AND is_demo = FALSE");
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
            $fractionId = $fractionModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'identifier' => $fraction['identifier'],
                'permillage' => $fraction['permillage'],
                'floor' => $fraction['floor'],
                'typology' => $fraction['typology'],
                'area' => $fraction['area']
            ]);
            $this->fractionIds[$fraction['identifier']] = $fractionId;
            echo "   Fração {$fraction['identifier']} criada (ID: {$fractionId})\n";
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

            // Associate with fraction
            $condominiumUserModel->associate([
                'condominium_id' => $this->demoCondominiumId,
                'user_id' => $userId,
                'fraction_id' => $this->fractionIds[$userData['fraction']],
                'role' => $userData['role'],
                'is_primary' => true,
                'can_view_finances' => true,
                'can_vote' => true,
                'started_at' => '2024-01-01'
            ]);

            echo "   Utilizador {$userData['name']} criado e associado à fração {$userData['fraction']}\n";
        }
    }

    protected function createBankAccounts(int $condominiumIndex = 0): void
    {
        echo "5. Criando contas bancárias...\n";

        // Different bank accounts for each condominium
        $accountsData = [
            // Condominium 0: Residencial Sol Nascente
            [
                [
                    'name' => 'Conta Principal',
                    'account_type' => 'bank',
                    'bank_name' => 'Banco BPI',
                    'iban' => 'PT50000000000000000000001',
                    'swift' => 'BBPIPTPL',
                    'initial_balance' => 5000.00
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
                    'name' => 'Conta Corrente',
                    'account_type' => 'bank',
                    'bank_name' => 'Caixa Geral de Depósitos',
                    'iban' => 'PT50000000000000000000011',
                    'swift' => 'CGDIPTPL',
                    'initial_balance' => 7500.00
                ],
                [
                    'name' => 'Conta Poupança',
                    'account_type' => 'bank',
                    'bank_name' => 'Caixa Geral de Depósitos',
                    'iban' => 'PT50000000000000000000012',
                    'swift' => 'CGDIPTPL',
                    'initial_balance' => 15000.00
                ]
            ]
        ];

        $accounts = $accountsData[$condominiumIndex] ?? $accountsData[0];

        $bankAccountModel = new BankAccount();
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

    protected function createExpenses2025(int $condominiumIndex = 0): void
    {
        echo "8. Criando despesas 2025...\n";

        $expenseModel = new Expense();
        $count = 0;

        // Monthly expenses
        for ($month = 1; $month <= 12; $month++) {
            $date = "2025-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-15";

            // Water (supplier 0)
            $expenseModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'supplier_id' => $this->supplierIds[0],
                'category' => 'Água',
                'type' => 'ordinaria',
                'amount' => 150 + rand(0, 50),
                'expense_date' => $date,
                'description' => "Fatura de água - {$month}/2025",
                'payment_method' => 'transfer',
                'is_paid' => true,
                'created_by' => $this->demoUserId
            ]);
            $count++;

            // Energy (supplier 1)
            $expenseModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'supplier_id' => $this->supplierIds[1],
                'category' => 'Energia',
                'type' => 'ordinaria',
                'amount' => 200 + rand(0, 100),
                'expense_date' => $date,
                'description' => "Fatura de energia - {$month}/2025",
                'payment_method' => 'transfer',
                'is_paid' => true,
                'created_by' => $this->demoUserId
            ]);
            $count++;

            // Cleaning (supplier 3)
            $expenseModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'supplier_id' => $this->supplierIds[3],
                'category' => 'Limpeza',
                'type' => 'ordinaria',
                'amount' => 300,
                'expense_date' => $date,
                'description' => "Serviço de limpeza - {$month}/2025",
                'payment_method' => 'transfer',
                'is_paid' => true,
                'created_by' => $this->demoUserId
            ]);
            $count++;

            // Maintenance (occasional)
            if ($month % 3 == 0) {
                $expenseModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'supplier_id' => $this->supplierIds[4],
                    'category' => 'Manutenção',
                    'type' => 'ordinaria',
                    'amount' => 200 + rand(0, 300),
                    'expense_date' => $date,
                    'description' => "Manutenção trimestral - {$month}/2025",
                    'payment_method' => 'transfer',
                    'is_paid' => true,
                    'created_by' => $this->demoUserId
                ]);
                $count++;
            }
        }

        // Annual insurance (supplier 2)
        $expenseModel->create([
            'condominium_id' => $this->demoCondominiumId,
            'supplier_id' => $this->supplierIds[2],
            'category' => 'Seguro',
            'type' => 'ordinaria',
            'amount' => 600,
            'expense_date' => '2025-01-10',
            'description' => 'Seguro anual 2025',
            'payment_method' => 'transfer',
            'is_paid' => true,
            'created_by' => $this->demoUserId
        ]);
        $count++;

        echo "   {$count} despesas criadas\n";
    }

    protected function generateFees2025(int $condominiumIndex = 0): void
    {
        echo "9. Gerando quotas 2025...\n";

        $feeService = new FeeService();
        $feeModel = new Fee();
        $feePaymentModel = new FeePayment();
        $transactionModel = new FinancialTransaction();

        // Generate fees for all months
        for ($month = 1; $month <= 12; $month++) {
            try {
                $feeService->generateMonthlyFees($this->demoCondominiumId, 2025, $month);
            } catch (\Exception $e) {
                // If fees already exist, continue
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }

        // Get all fees for 2025
        $stmt = $this->db->prepare("
            SELECT f.* FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.period_year = 2025
            ORDER BY f.period_month ASC, f.fraction_id ASC
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $fees = $stmt->fetchAll();

        $paidCount = 0;
        $totalFees = count($fees);
        $targetPaid = (int)($totalFees * 0.75); // 75% paid

        foreach ($fees as $index => $fee) {
            $shouldPay = $index < $targetPaid;
            $paymentDate = $shouldPay ? "2025-" . str_pad($fee['period_month'], 2, '0', STR_PAD_LEFT) . "-" . rand(5, 25) : null;

            if ($shouldPay && $paymentDate) {
                // Create payment
                $paymentId = $feePaymentModel->create([
                    'fee_id' => $fee['id'],
                    'amount' => $fee['amount'],
                    'payment_method' => ['multibanco', 'transfer', 'mbway'][rand(0, 2)],
                    'payment_date' => $paymentDate,
                    'reference' => 'REF' . rand(100000, 999999),
                    'created_by' => $this->demoUserId,
                    'financial_transaction_id' => null
                ]);

                // Create financial transaction
                $transactionId = $transactionModel->create([
                    'condominium_id' => $this->demoCondominiumId,
                    'bank_account_id' => $this->accountIds[0], // Main account
                    'transaction_type' => 'income',
                    'amount' => $fee['amount'],
                    'transaction_date' => $paymentDate,
                    'description' => "Pagamento quota {$fee['reference']}",
                    'category' => 'Quotas',
                    'reference' => 'REF' . rand(100000, 999999),
                    'related_type' => 'fee_payment',
                    'related_id' => $paymentId,
                    'created_by' => $this->demoUserId
                ]);

                // Update payment with transaction
                $this->db->exec("UPDATE fee_payments SET financial_transaction_id = {$transactionId} WHERE id = {$paymentId}");

                // Mark fee as paid
                $feeModel->markAsPaid($fee['id']);
                $paidCount++;
            }
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
        echo "10. Criando movimentos financeiros adicionais...\n";

        $transactionModel = new FinancialTransaction();
        $count = 0;

        // Add some expense transactions
        $expenses = $this->db->query("
            SELECT id, amount, expense_date, description
            FROM expenses
            WHERE condominium_id = {$this->demoCondominiumId}
            ORDER BY expense_date ASC
        ")->fetchAll();

        foreach ($expenses as $expense) {
            $transactionModel->create([
                'condominium_id' => $this->demoCondominiumId,
                'bank_account_id' => $this->accountIds[0],
                'transaction_type' => 'expense',
                'amount' => $expense['amount'],
                'transaction_date' => $expense['expense_date'],
                'description' => $expense['description'],
                'category' => 'Despesas',
                'related_type' => 'expense',
                'related_id' => $expense['id'],
                'created_by' => $this->demoUserId
            ]);
            $count++;
        }

        // Update account balances
        $bankAccountModel = new BankAccount();
        foreach ($this->accountIds as $accountId) {
            $bankAccountModel->updateBalance($accountId);
        }

        echo "   {$count} movimentos financeiros criados\n";
    }

    protected function createAssemblies(int $condominiumIndex = 0): void
    {
        echo "11. Criando assembleias...\n";

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

        $reservationModel = new Reservation();
        $count = 0;

        // Get fraction users
        $stmt = $this->db->prepare("
            SELECT cu.user_id, cu.fraction_id
            FROM condominium_users cu
            WHERE cu.condominium_id = {$this->demoCondominiumId}
            AND cu.is_primary = TRUE
        ");
        $stmt->execute();
        $fractionUsers = $stmt->fetchAll();

        // Create reservations throughout 2025
        $reservations = [
            ['space_index' => 0, 'month' => 2, 'day' => 14, 'status' => 'approved'], // Salão - Valentine
            ['space_index' => 0, 'month' => 5, 'day' => 1, 'status' => 'approved'], // Salão - Labor Day
            ['space_index' => 1, 'month' => 7, 'day' => 15, 'status' => 'approved'], // Piscina - Summer
            ['space_index' => 1, 'month' => 8, 'day' => 10, 'status' => 'pending'], // Piscina - Pending
            ['space_index' => 2, 'month' => 4, 'day' => 20, 'status' => 'approved'], // Ténis
            ['space_index' => 2, 'month' => 6, 'day' => 10, 'status' => 'approved'], // Ténis
            ['space_index' => 0, 'month' => 12, 'day' => 25, 'status' => 'pending'], // Salão - Christmas
        ];

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

    protected function createOccurrences(int $condominiumIndex = 0): void
    {
        echo "14. Criando ocorrências...\n";

        $occurrenceModel = new Occurrence();
        $count = 0;

        // Get fraction users
        $stmt = $this->db->prepare("
            SELECT cu.user_id, cu.fraction_id
            FROM condominium_users cu
            WHERE cu.condominium_id = {$this->demoCondominiumId}
            AND cu.is_primary = TRUE
        ");
        $stmt->execute();
        $fractionUsers = $stmt->fetchAll();

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

    protected function createReceiptsForDemoPayments(int $condominiumIndex = 0): void
    {
        echo "15. Verificando recibos demo...\n";

        $receiptModel = new \App\Models\Receipt();
        $feeModel = new Fee();
        $feePaymentModel = new FeePayment();

        // Check which fees are fully paid and don't have receipts yet
        $stmt = $this->db->prepare("
            SELECT f.id as fee_id, f.condominium_id, f.fraction_id, f.period_year, f.amount
            FROM fees f
            WHERE f.condominium_id = :condominium_id
            AND f.status = 'paid'
        ");
        $stmt->execute([':condominium_id' => $this->demoCondominiumId]);
        $fees = $stmt->fetchAll();

        $receiptCount = 0;
        $existingCount = 0;

        foreach ($fees as $feeData) {
            $feeId = $feeData['fee_id'];
            
            // Check if receipt already exists for this fee
            $existingReceipts = $receiptModel->getByFee($feeId);
            $hasReceipt = false;
            foreach ($existingReceipts as $r) {
                if ($r['receipt_type'] === 'final' && $r['generated_by'] == $this->demoUserId) {
                    $hasReceipt = true;
                    $existingCount++;
                    break;
                }
            }

            // Only create receipt if it doesn't exist and was generated by demo user
            if (!$hasReceipt) {
                // Verify fee is still fully paid
                $totalPaid = $feePaymentModel->getTotalPaid($feeId);
                if ($totalPaid >= (float)$feeData['amount']) {
                    // Receipt will be created by the system when payment is registered
                    // For demo, we'll create them only if missing
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
                        $receiptNumber = $receiptModel->generateReceiptNumber($this->demoCondominiumId, (int)$fee['period_year']);
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

                        // Generate PDF
                        $filePath = $pdfService->generateReceiptPdf($htmlContent, $receiptId, $receiptNumber);
                        $fullPath = __DIR__ . '/../../storage/documents/' . $filePath;
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

                        $receiptCount++;
                    } catch (\Exception $e) {
                        echo "   Aviso: Erro ao criar recibo para quota {$feeId}: " . $e->getMessage() . "\n";
                    }
                }
            }
        }

        if ($receiptCount > 0) {
            echo "   {$receiptCount} recibos criados, {$existingCount} já existiam\n";
        } else {
            echo "   {$existingCount} recibos demo já existem\n";
        }
    }
}

// Run seeder if executed directly
if (php_sapi_name() === 'cli') {
    global $db;
    $seeder = new DemoSeeder($db);
    $seeder->run();
}
