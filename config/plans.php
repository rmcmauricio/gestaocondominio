<?php

return [
    'start' => [
        'name' => 'START',
        'price_monthly' => 9.90,
        'price_yearly' => 99.00,
        'limit_condominios' => 1,
        'limit_fracoes' => 20,
        'features' => [
            'financas_completas' => true,
            'documentos' => true,
            'ocorrencias' => true,
            'votacoes_online' => true,
            'reservas_espacos' => true,
            'gestao_contratos' => true,
            'gestao_fornecedores' => true
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
            'documentos' => true,
            'ocorrencias' => true,
            'votacoes_online' => true,
            'reservas_espacos' => true,
            'gestao_contratos' => true,
            'gestao_fornecedores' => true
        ]
    ],
    'business' => [
        'name' => 'BUSINESS',
        'price_monthly' => 59.90,
        'price_yearly' => 599.00,
        'limit_condominios' => null, // unlimited
        'limit_fracoes' => null, // unlimited
        'features' => [
            'financas_completas' => true,
            'documentos' => true,
            'ocorrencias' => true,
            'votacoes_online' => true,
            'reservas_espacos' => true,
            'gestao_contratos' => true,
            'gestao_fornecedores' => true
        ]
    ]
];





