<?php

namespace App\Services;

class BreadcrumbService
{
    /**
     * Each entry: [ [label, url], ... ]. Last url is null (current page).
     * Label can be 'key.path' to resolve from context (e.g. condominium.name, page.titulo).
     * Url can contain {condominium_id}, {fraction_id}, {assembly_id}, {id}.
     */
    protected static $map = [
        'pages/home.html.twig' => [],

        'pages/login.html.twig' => [ ['Entrar', null] ],
        'pages/register.html.twig' => [ ['Registar', null] ],
        'pages/forgot-password.html.twig' => [ ['Recuperar palavra-passe', null] ],
        'pages/reset-password.html.twig' => [ ['Repor palavra-passe', null] ],
        'pages/auth/select-account-type.html.twig' => [ ['Tipo de conta', null] ],
        'pages/auth/select-plan.html.twig' => [ ['Escolher plano', null] ],

        'pages/about.html.twig' => [ ['Sobre', null] ],
        'pages/help/index.html.twig' => [ ['Início', '__inicio__'], ['Ajuda', null] ],

        'pages/legal/faq.html.twig' => [ ['Início', '__inicio__'], ['FAQ', null] ],
        'pages/legal/terms.html.twig' => [ ['Início', '__inicio__'], ['Termos e Condições', null] ],
        'pages/legal/privacy.html.twig' => [ ['Início', '__inicio__'], ['Privacidade', null] ],
        'pages/legal/cookies.html.twig' => [ ['Início', '__inicio__'], ['Cookies', null] ],

        'pages/dashboard/condomino.html.twig' => [ ['Dashboard', null] ],
        'pages/dashboard/super-admin.html.twig' => [ ['Admin', null] ],
        'pages/condominiums/index.html.twig' => [ ['Admin', 'admin'], ['Condomínios', null] ],

        'pages/condominiums/create.html.twig' => [ ['Início', 'dashboard'], ['Criar condomínio', null] ],
        'pages/condominiums/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', null] ],
        'pages/condominiums/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Editar', null] ],
        'pages/condominiums/assign-admin.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Atribuir administrador', null] ],

        'pages/finances/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', null] ],
        'pages/finances/budgets.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Orçamentos', null] ],
        'pages/finances/create-budget.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Orçamentos', 'condominiums/{condominium_id}/budgets'], ['Criar orçamento', null] ],
        'pages/finances/show-budget.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['page.titulo', null] ],
        'pages/finances/edit-budget.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Editar orçamento', null] ],
        'pages/finances/fees.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Quotas', null] ],
        'pages/finances/fraction-accounts/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Contas por fração', null] ],
        'pages/finances/fraction-accounts/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Contas por fração', 'condominiums/{condominium_id}/fraction-accounts'], ['fraction.identifier', null] ],
        'pages/finances/historical-debts.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Dívidas históricas', null] ],
        'pages/finances/historical-credits.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Créditos históricos', null] ],
        'pages/finances/revenues.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Receitas', null] ],
        'pages/finances/revenue-categories.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Receitas', 'condominiums/{condominium_id}/finances/revenues'], ['Categorias', null] ],
        'pages/finances/revenue-category-edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Receitas', 'condominiums/{condominium_id}/finances/revenues'], ['Categorias', 'condominiums/{condominium_id}/finances/revenues/categories'], ['Editar categoria', null] ],
        'pages/finances/expenses.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Despesas', null] ],
        'pages/finances/expense-categories.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Despesas', 'condominiums/{condominium_id}/expenses'], ['Categorias', null] ],
        'pages/finances/expense-category-edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Despesas', 'condominiums/{condominium_id}/expenses'], ['Categorias', 'condominiums/{condominium_id}/expenses/categories'], ['Editar categoria', null] ],
        'pages/finances/create-revenue.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Receitas', 'condominiums/{condominium_id}/finances/revenues'], ['Registar receita', null] ],
        'pages/finances/edit-revenue.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Receitas', 'condominiums/{condominium_id}/finances/revenues'], ['Editar receita', null] ],
        'pages/finances/edit-fee.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Quotas', 'condominiums/{condominium_id}/fees'], ['Editar quota', null] ],
        'pages/finances/reports.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Finanças', 'condominiums/{condominium_id}/finances'], ['Relatórios', null] ],

        'pages/financial-transactions/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Movimentos', null] ],
        'pages/financial-transactions/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Movimentos', 'condominiums/{condominium_id}/financial-transactions'], ['Novo movimento', null] ],
        'pages/financial-transactions/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Movimentos', 'condominiums/{condominium_id}/financial-transactions'], ['Editar movimento', null] ],

        'pages/fractions/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Frações', null] ],
        'pages/fractions/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Frações', 'condominiums/{condominium_id}/fractions'], ['Nova fração', null] ],
        'pages/fractions/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Frações', 'condominiums/{condominium_id}/fractions'], ['Editar fração', null] ],

        'pages/bank-accounts/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Contas bancárias', null] ],
        'pages/bank-accounts/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Contas bancárias', 'condominiums/{condominium_id}/bank-accounts'], ['Nova conta', null] ],
        'pages/bank-accounts/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Contas bancárias', 'condominiums/{condominium_id}/bank-accounts'], ['Editar conta', null] ],

        'pages/documents/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', null] ],
        'pages/documents/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', 'condominiums/{condominium_id}/documents'], ['Novo documento', null] ],
        'pages/documents/view.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', 'condominiums/{condominium_id}/documents'], ['page.titulo', null] ],
        'pages/documents/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', 'condominiums/{condominium_id}/documents'], ['Editar documento', null] ],
        'pages/documents/versions.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', 'condominiums/{condominium_id}/documents'], ['Versões', null] ],
        'pages/documents/upload-version.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', 'condominiums/{condominium_id}/documents'], ['Nova versão', null] ],
        'pages/documents/manage-folders.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Documentos', 'condominiums/{condominium_id}/documents'], ['Pastas', null] ],

        'pages/occurrences/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Ocorrências', null] ],
        'pages/occurrences/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Ocorrências', 'condominiums/{condominium_id}/occurrences'], ['Nova ocorrência', null] ],
        'pages/occurrences/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Ocorrências', 'condominiums/{condominium_id}/occurrences'], ['page.titulo', null] ],

        'pages/suppliers/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Fornecedores', null] ],
        'pages/suppliers/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Fornecedores', 'condominiums/{condominium_id}/suppliers'], ['Novo fornecedor', null] ],
        'pages/suppliers/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Fornecedores', 'condominiums/{condominium_id}/suppliers'], ['Editar fornecedor', null] ],
        'pages/suppliers/contracts.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Fornecedores', 'condominiums/{condominium_id}/suppliers'], ['Contratos', null] ],
        'pages/suppliers/create-contract.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Fornecedores', 'condominiums/{condominium_id}/suppliers'], ['Contratos', 'condominiums/{condominium_id}/suppliers/contracts'], ['Novo contrato', null] ],

        'pages/spaces/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Espaços', null] ],
        'pages/spaces/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Espaços', 'condominiums/{condominium_id}/spaces'], ['Novo espaço', null] ],
        'pages/spaces/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Espaços', 'condominiums/{condominium_id}/spaces'], ['Editar espaço', null] ],

        'pages/reservations/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Reservas', null] ],
        'pages/reservations/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Reservas', 'condominiums/{condominium_id}/reservations'], ['Nova reserva', null] ],

        'pages/assemblies/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', null] ],
        'pages/assemblies/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['Nova assembleia', null] ],
        'pages/assemblies/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['page.titulo', null] ],
        'pages/assemblies/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['Editar assembleia', null] ],
        'pages/assemblies/edit-minutes-template.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['page.titulo', null] ],
        'pages/assemblies/revisions.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['page.titulo', 'condominiums/{condominium_id}/assemblies/{id}'], ['Revisões', null] ],

        'pages/votes/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Votações', null] ],
        'pages/votes/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Votações', 'condominiums/{condominium_id}/votes'], ['Nova votação', null] ],
        'pages/votes/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Votações', 'condominiums/{condominium_id}/votes'], ['page.titulo', null] ],
        'pages/votes/edit.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Votações', 'condominiums/{condominium_id}/votes'], ['Editar votação', null] ],
        'pages/votes/create-topic.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['page.titulo', null] ],
        'pages/votes/edit-topic.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['Editar tema', null] ],
        'pages/votes/results.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Assembleias', 'condominiums/{condominium_id}/assemblies'], ['Resultados', null] ],

        'pages/vote-options/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Opções de voto', null] ],

        'pages/messages/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Mensagens', null] ],
        'pages/messages/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Mensagens', 'condominiums/{condominium_id}/messages'], ['Nova mensagem', null] ],
        'pages/messages/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Mensagens', 'condominiums/{condominium_id}/messages'], ['page.titulo', null] ],

        'pages/invitations/create.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Convites', null] ],
        'pages/invitations/accept.html.twig' => [ ['Aceitar convite', null] ],

        'pages/receipts/index.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Recibos', null] ],
        'pages/receipts/show.html.twig' => [ ['Início', 'dashboard'], ['condominium.name', 'condominiums/{condominium_id}'], ['Recibos', 'condominiums/{condominium_id}/receipts'], ['Recibo', null] ],

        'pages/profile/show.html.twig' => [ ['Início', 'dashboard'], ['Perfil', null] ],
        'pages/notifications/index.html.twig' => [ ['Início', 'dashboard'], ['Notificações', null] ],

        'pages/subscription/index.html.twig' => [ ['Início', 'dashboard'], ['Subscrição', null] ],
        'pages/subscription/choose-plan.html.twig' => [ ['Início', 'dashboard'], ['Subscrição', 'subscription'], ['Escolher plano', null] ],

        'pages/demo.html.twig' => [ ['Demo', null] ],

        'pages/api/documentation.html.twig' => [ ['Início', 'dashboard'], ['API', null] ],
        'pages/api/index.html.twig' => [ ['Início', 'dashboard'], ['API', null] ],
    ];

    /** Prefix patterns: if no exact match, use first matching prefix. Value = steps. */
    protected static $prefixMap = [
        'pages/help/' => [ ['Início', '__inicio__'], ['Ajuda', 'help'], ['page.titulo', null] ],
    ];

    /**
     * @param array $context Twig context (viewName, page, condominium, BASE_URL, ...)
     * @return array [ ['label' => string, 'url' => string|null], ... ]
     */
    public static function getBreadcrumbs(array $context): array
    {
        $viewName = $context['viewName'] ?? '';
        if ($viewName === 'pages/home.html.twig') {
            return [];
        }

        $baseUrl = rtrim($context['BASE_URL'] ?? '', '/') . '/';
        $steps = null;

        if (isset(self::$map[$viewName])) {
            $steps = self::$map[$viewName];
        } else {
            foreach (self::$prefixMap as $prefix => $preSteps) {
                if (strpos($viewName, $prefix) === 0) {
                    $steps = $preSteps;
                    break;
                }
            }
        }

        if ($steps === null) {
            $titulo = $context['page']['titulo'] ?? 'Página';
            return [ ['label' => $titulo, 'url' => null] ];
        }

        if (empty($steps)) {
            return [];
        }

        $out = [];
        $n = count($steps);
        foreach ($steps as $i => $step) {
            $label = is_array($step) ? $step[0] : $step;
            $urlTmpl = is_array($step) && isset($step[1]) ? $step[1] : null;
            if (is_callable($urlTmpl)) {
                $url = $urlTmpl();
            } else {
                $url = self::resolveUrl($context, $urlTmpl, $baseUrl);
            }
            $label = self::resolveLabel($context, $label);
            if ($i === $n - 1 && isset($context['page']['titulo']) && (string)$context['page']['titulo'] !== '') {
                $label = $context['page']['titulo'];
            }
            if ($label === null || $label === '') {
                continue;
            }
            $out[] = [ 'label' => (string)$label, 'url' => $url ];
        }
        return $out;
    }

    protected static function resolveLabel(array $context, $label): ?string
    {
        if (!is_string($label)) {
            return (string)$label;
        }
        if (strpos($label, '.') !== false && !str_contains($label, ' ')) {
            $v = $context;
            foreach (explode('.', $label) as $k) {
                $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : null;
                if ($v === null) {
                    break;
                }
            }
            return $v !== null ? (string)$v : null;
        }
        return $label;
    }

    protected static function resolveUrl(array $context, ?string $template, string $baseUrl): ?string
    {
        if ($template === null || $template === '') {
            return null;
        }
        if ($template === '__inicio__') {
            $b = rtrim($context['BASE_URL'] ?? '', '/') . '/';
            return !empty($context['user']) ? $b . 'dashboard' : ($context['BASE_URL'] ?? '/');
        }
        $c = $context['condominium'] ?? null;
        $cid = is_array($c) && isset($c['id']) ? $c['id'] : ($context['condominium_id'] ?? '');
        $fid = ($context['fraction']['id'] ?? $context['fraction_id'] ?? '');
        $aid = ($context['assembly']['id'] ?? $context['assembly_id'] ?? '');
        $genId = ($context['budget']['id'] ?? $context['assembly']['id'] ?? $context['fraction']['id'] ?? $context['id'] ?? '');
        $template = str_replace('{condominium_id}', (string)$cid, $template);
        $template = str_replace('{fraction_id}', (string)$fid, $template);
        $template = str_replace('{assembly_id}', (string)$aid, $template);
        $template = str_replace('{id}', (string)$genId, $template);
        return $baseUrl . $template;
    }
}
