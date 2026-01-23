<?php

class PaymentMethodsSeeder
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function run(): void
    {
        $methods = [
            [
                'method_key' => 'multibanco',
                'enabled' => 1,
                'config_data' => json_encode([
                    'name' => 'Multibanco',
                    'icon' => 'bi bi-bank',
                    'description' => 'Pague com referência Multibanco'
                ])
            ],
            [
                'method_key' => 'mbway',
                'enabled' => 1,
                'config_data' => json_encode([
                    'name' => 'MBWay',
                    'icon' => 'bi bi-phone',
                    'description' => 'Pague com MBWay'
                ])
            ],
            [
                'method_key' => 'direct_debit',
                'enabled' => 1,
                'config_data' => json_encode([
                    'name' => 'Débito Direto IfthenPay',
                    'icon' => 'bi bi-arrow-repeat',
                    'description' => 'Débito direto via IfthenPay'
                ])
            ],
            [
                'method_key' => 'card',
                'enabled' => 0,
                'config_data' => json_encode([
                    'name' => 'Cartão de Crédito/Débito',
                    'icon' => 'bi bi-credit-card',
                    'description' => 'Pague com cartão'
                ])
            ]
        ];

        $stmt = $this->db->prepare("
            INSERT INTO payment_methods_settings (method_key, enabled, config_data)
            VALUES (:method_key, :enabled, :config_data)
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                config_data = VALUES(config_data)
        ");

        foreach ($methods as $method) {
            $stmt->execute($method);
        }
    }
}
