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
        $plans = [
            [
                'name' => 'START',
                'slug' => 'start',
                'description' => 'Plano ideal para pequenos condomínios',
                'price_monthly' => 9.90,
                'price_yearly' => 99.00,
                'limit_condominios' => 1,
                'limit_fracoes' => 20,
                'features' => json_encode([
                    'financas_basicas' => true,
                    'documentos' => true,
                    'ocorrencias_simples' => true,
                    'votacoes_online' => false,
                    'reservas_espacos' => false,
                    'gestao_contratos' => false,
                    'api' => false,
                    'branding_personalizado' => false,
                    'app_mobile' => false
                ]),
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
                'features' => json_encode([
                    'financas_completas' => true,
                    'votacoes_online' => true,
                    'reservas_espacos' => true,
                    'gestao_contratos' => true,
                    'gestao_fornecedores' => true,
                    'api' => false,
                    'branding_personalizado' => false,
                    'app_mobile' => false
                ]),
                'is_active' => true,
                'sort_order' => 2
            ],
            [
                'name' => 'BUSINESS',
                'slug' => 'business',
                'description' => 'Solução empresarial com todas as funcionalidades',
                'price_monthly' => 59.90,
                'price_yearly' => 599.00,
                'limit_condominios' => null,
                'limit_fracoes' => null,
                'features' => json_encode([
                    'todos_modulos' => true,
                    'api' => true,
                    'branding_personalizado' => true,
                    'app_mobile_premium' => true,
                    'suporte_prioritario' => true
                ]),
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





