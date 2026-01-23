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
        $this->seedPlanPricingTiers();
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

        // Novos planos baseados em licenças
        $plans = [
            [
                'name' => 'Condomínio',
                'slug' => 'condominio',
                'description' => 'Plano base ideal para pequenos condomínios',
                'price_monthly' => 9.99, // Preço base (será calculado por tier)
                'price_yearly' => 99.99,
                'plan_type' => 'condominio',
                'license_min' => 10,
                'license_limit' => null,
                'allow_multiple_condos' => false,
                'allow_overage' => false,
                'pricing_mode' => 'flat',
                'annual_discount_percentage' => 0,
                'limit_condominios' => 1, // Mantido para compatibilidade
                'limit_fracoes' => null, // Não usado no novo modelo
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 1
            ],
            [
                'name' => 'Professional',
                'slug' => 'professional',
                'description' => 'Plano completo para gestão profissional com múltiplos condomínios',
                'price_monthly' => 39.99, // Preço base (será calculado por tier)
                'price_yearly' => 399.99,
                'plan_type' => 'professional',
                'license_min' => 50,
                'license_limit' => null,
                'allow_multiple_condos' => true,
                'allow_overage' => false,
                'pricing_mode' => 'flat',
                'annual_discount_percentage' => 0,
                'limit_condominios' => null, // Ilimitado
                'limit_fracoes' => null,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 2
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Solução empresarial com todas as funcionalidades e suporte a overage',
                'price_monthly' => 169.99, // Preço base (será calculado por tier)
                'price_yearly' => 1699.99,
                'plan_type' => 'enterprise',
                'license_min' => 200,
                'license_limit' => null,
                'allow_multiple_condos' => true,
                'allow_overage' => true,
                'pricing_mode' => 'flat',
                'annual_discount_percentage' => 0,
                'limit_condominios' => null, // Ilimitado
                'limit_fracoes' => null,
                'features' => $commonFeatures,
                'is_active' => true,
                'sort_order' => 3
            ]
        ];

        // Verificar se colunas do novo modelo existem
        $checkStmt = $this->db->query("SHOW COLUMNS FROM plans LIKE 'plan_type'");
        $hasNewColumns = $checkStmt->rowCount() > 0;

        if ($hasNewColumns) {
            // Usar novo formato com campos de licenças
            $stmt = $this->db->prepare("
                INSERT INTO plans (
                    name, slug, description, price_monthly, price_yearly,
                    plan_type, license_min, license_limit, allow_multiple_condos,
                    allow_overage, pricing_mode, annual_discount_percentage,
                    limit_condominios, limit_fracoes, features, is_active, sort_order
                )
                VALUES (
                    :name, :slug, :description, :price_monthly, :price_yearly,
                    :plan_type, :license_min, :license_limit, :allow_multiple_condos,
                    :allow_overage, :pricing_mode, :annual_discount_percentage,
                    :limit_condominios, :limit_fracoes, :features, :is_active, :sort_order
                )
                ON DUPLICATE KEY UPDATE
                    description = VALUES(description),
                    price_monthly = VALUES(price_monthly),
                    price_yearly = VALUES(price_yearly),
                    plan_type = VALUES(plan_type),
                    license_min = VALUES(license_min),
                    license_limit = VALUES(license_limit),
                    allow_multiple_condos = VALUES(allow_multiple_condos),
                    allow_overage = VALUES(allow_overage),
                    pricing_mode = VALUES(pricing_mode),
                    annual_discount_percentage = VALUES(annual_discount_percentage),
                    limit_condominios = VALUES(limit_condominios),
                    limit_fracoes = VALUES(limit_fracoes),
                    features = VALUES(features),
                    is_active = VALUES(is_active),
                    sort_order = VALUES(sort_order)
            ");
        } else {
            // Fallback para formato antigo (compatibilidade)
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
        }

        foreach ($plans as $plan) {
            if ($hasNewColumns) {
                // Converter booleanos para inteiros (MySQL BOOLEAN = TINYINT)
                $planData = $plan;
                $planData['allow_multiple_condos'] = $plan['allow_multiple_condos'] ? 1 : 0;
                $planData['allow_overage'] = $plan['allow_overage'] ? 1 : 0;
                $planData['is_active'] = $plan['is_active'] ? 1 : 0;
                $stmt->execute($planData);
            } else {
                // Remover campos novos se não existirem
                $oldPlan = [
                    'name' => $plan['name'],
                    'slug' => $plan['slug'],
                    'description' => $plan['description'],
                    'price_monthly' => $plan['price_monthly'],
                    'price_yearly' => $plan['price_yearly'],
                    'limit_condominios' => $plan['limit_condominios'],
                    'limit_fracoes' => $plan['limit_fracoes'],
                    'features' => $plan['features'],
                    'is_active' => $plan['is_active'] ? 1 : 0,
                    'sort_order' => $plan['sort_order']
                ];
                $stmt->execute($oldPlan);
            }
        }

        // Seed preços escalonados para condomínios extras do plano Business (legado)
        // Nota: Esta função será chamada após a migração 087 ser executada
        $this->seedBusinessExtraCondominiumsPricing();
    }

    /**
     * Seed plan pricing tiers
     */
    protected function seedPlanPricingTiers(): void
    {
        // Verificar se a tabela existe
        $tableExists = false;
        try {
            $checkStmt = $this->db->query("SHOW TABLES LIKE 'plan_pricing_tiers'");
            $tableExists = $checkStmt->rowCount() > 0;
        } catch (\Exception $e) {
            // Tabela não existe ainda
            return;
        }

        if (!$tableExists) {
            return; // Tabela ainda não foi criada
        }

        require __DIR__ . '/PlanPricingTierSeeder.php';
        $seeder = new PlanPricingTierSeeder($this->db);
        $seeder->run();
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





