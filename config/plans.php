<?php

return [
    'start' => [
        'name' => 'START',
        'price_monthly' => 9.90,
        'price_yearly' => 99.00,
        'limit_condominios' => 1,
        'limit_fracoes' => 20,
        'features' => [
            'financas_basicas' => true,
            'documentos' => true,
            'ocorrencias_simples' => true,
            'votacoes_online' => false,
            'reservas_espacos' => false,
            'gestao_contratos' => false,
            'api' => false,
            'branding_personalizado' => false,
            'app_mobile' => false
        ]
    ],
    'pro' => [
        'name' => 'PRO',
        'price_monthly' => 29.90,
        'price_yearly' => 299.00,
        'limit_condominios' => 5,
        'limit_fracoes' => 150,
        'features' => [
            'financas_completas' => true,
            'votacoes_online' => true,
            'reservas_espacos' => true,
            'gestao_contratos' => true,
            'gestao_fornecedores' => true,
            'api' => false,
            'branding_personalizado' => false,
            'app_mobile' => false
        ]
    ],
    'business' => [
        'name' => 'BUSINESS',
        'price_monthly' => 59.90,
        'price_yearly' => 599.00,
        'limit_condominios' => null, // unlimited
        'limit_fracoes' => null, // unlimited
        'features' => [
            'todos_modulos' => true,
            'api' => true,
            'branding_personalizado' => true,
            'app_mobile_premium' => true,
            'suporte_prioritario' => true
        ]
    ]
];





