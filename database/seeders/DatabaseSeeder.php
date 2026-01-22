<?php

class DatabaseSeeder
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function run(): void
    {
        $this->seedPlans();
        $this->seedSuperAdmin();
        $this->seedPaymentMethods();
    }

    protected function seedPlans(): void
    {
        // Features comuns para todos os planos
        $commonFeatures = json_encode([
            'financas_completas' => true,
            'documentos' => true,
            'ocorrencias' => true,
            'votacoes_online' => true,
            'reservas_espacos' => true,
            'gestao_contratos' => true,
            'gestao_fornecedores' => true
        ]);

        $plans = [
            [
                'name' => 'START',
                'slug' => 'start',
                'description' => 'Plano ideal para pequenos condomínios',
                'price_monthly' => 9.90,
                'price_yearly' => 99.00,
                'limit_condominios' => 1,
                'limit_fracoes' => 20,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 1
            ],
            [
                'name' => 'PRO',
                'slug' => 'pro',
                'description' => 'Plano completo para gestão profissional',
                'price_monthly' => 29.90,
                'price_yearly' => 299.00,
                'limit_condominios' => 5,
                'limit_fracoes' => 150,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 2
            ],
            [
                'name' => 'BUSINESS',
                'slug' => 'business',
                'description' => 'Solução empresarial com todas as funcionalidades e frações extras disponíveis',
                'price_monthly' => 59.90,
                'price_yearly' => 599.00,
                'limit_condominios' => 10,
                'limit_fracoes' => null,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 3
            ]
        ];

        $stmt = $this->db->prepare("
            INSERT INTO plans (name, slug, description, price_monthly, price_yearly, limit_condominios, limit_fracoes, features, is_active, sort_order)
            VALUES (:name, :slug, :description, :price_monthly, :price_yearly, :limit_condominios, :limit_fracoes, :features, :is_active, :sort_order)
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                price_monthly = VALUES(price_monthly),
                price_yearly = VALUES(price_yearly),
                limit_condominios = VALUES(limit_condominios),
                limit_fracoes = VALUES(limit_fracoes),
                features = VALUES(features),
                is_active = VALUES(is_active),
                sort_order = VALUES(sort_order)
        ");

        foreach ($plans as $plan) {
            $stmt->execute($plan);
        }

        // Seed preços escalonados para condomínios extras do plano Business
        // Nota: Esta função será chamada após a migração 087 ser executada
        $this->seedBusinessExtraCondominiumsPricing();
    }

    /**
     * Seed preços escalonados para condomínios extras do plano Business
     * Deve ser chamado após a migração 087 ser executada
     */
    public function seedBusinessExtraCondominiumsPricing(): void
    {
        // Buscar o ID do plano Business
        $stmt = $this->db->prepare("SELECT id FROM plans WHERE slug = 'business' LIMIT 1");
        $stmt->execute();
        $businessPlan = $stmt->fetch();

        if (!$businessPlan) {
            return; // Plano Business não encontrado
        }

        $businessPlanId = $businessPlan['id'];

        // Verificar se a tabela existe
        $tableExists = false;
        try {
            $checkStmt = $this->db->query("SHOW TABLES LIKE 'plan_extra_condominiums_pricing'");
            $tableExists = $checkStmt->rowCount() > 0;
        } catch (\Exception $e) {
            // Tabela não existe ainda
            return;
        }

        if (!$tableExists) {
            return; // Tabela ainda não foi criada
        }

        // Preços escalonados: quanto mais condomínios, mais barato fica cada condomínio extra
        $pricingTiers = [
            [
                'plan_id' => $businessPlanId,
                'min_condominios' => 1,
                'max_condominios' => 10,
                'price_per_condominium' => 8.99,
                'is_active' => true,
                'sort_order' => 1
            ],
            [
                'plan_id' => $businessPlanId,
                'min_condominios' => 11,
                'max_condominios' => 20,
                'price_per_condominium' => 7.99,
                'is_active' => true,
                'sort_order' => 2
            ],
            [
                'plan_id' => $businessPlanId,
                'min_condominios' => 21,
                'max_condominios' => null,
                'price_per_condominium' => 6.49,
                'is_active' => true,
                'sort_order' => 3
            ]
        ];

        $checkStmt = $this->db->prepare("
            SELECT id FROM plan_extra_condominiums_pricing 
            WHERE plan_id = :plan_id AND min_condominios = :min_condominios 
            LIMIT 1
        ");
        
        $insertStmt = $this->db->prepare("
            INSERT INTO plan_extra_condominiums_pricing (
                plan_id, min_condominios, max_condominios, price_per_condominium, is_active, sort_order
            )
            VALUES (
                :plan_id, :min_condominios, :max_condominios, :price_per_condominium, :is_active, :sort_order
            )
        ");
        
        $updateStmt = $this->db->prepare("
            UPDATE plan_extra_condominiums_pricing SET
                max_condominios = :max_condominios,
                price_per_condominium = :price_per_condominium,
                is_active = :is_active,
                sort_order = :sort_order
            WHERE plan_id = :plan_id AND min_condominios = :min_condominios
        ");

        foreach ($pricingTiers as $tier) {
            $checkStmt->execute([
                ':plan_id' => $tier['plan_id'],
                ':min_condominios' => $tier['min_condominios']
            ]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                $updateStmt->execute($tier);
            } else {
                $insertStmt->execute($tier);
            }
        }
    }

    protected function seedSuperAdmin(): void
    {
        // Verificar se já existe super admin
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1");
        $stmt->execute();
        $existing = $stmt->fetch();

        if (!$existing) {
            // Criar super admin padrão
            // Password: Admin@2024 (deve ser alterado após primeiro login)
            $password = password_hash('Admin@2024', PASSWORD_ARGON2ID);
            
            $stmt = $this->db->prepare("
                INSERT INTO users (email, password, name, role, status, email_verified_at)
                VALUES (:email, :password, :name, 'super_admin', 'active', NOW())
            ");
            
            $stmt->execute([
                'email' => 'admin@predio.pt',
                'password' => $password,
                'name' => 'Super Administrador'
            ]);
        }
    }

    protected function seedPaymentMethods(): void
    {
        require __DIR__ . '/PaymentMethodsSeeder.php';
        $seeder = new PaymentMethodsSeeder($this->db);
        $seeder->run();
    }
}





