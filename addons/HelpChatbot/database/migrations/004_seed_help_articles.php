<?php
/**
 * Seed help_articles from manual sections (same as app HelpController helpSections).
 * Enables chatbot search to return links to /help/{section}.
 */
class SeedHelpArticles
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $articles = [
            ['dashboard', 'Dashboard', 'Visão geral do dashboard, navegação entre condomínios e estatísticas.'],
            ['finances', 'Finanças', 'Gerir orçamentos, quotas, despesas e receitas.'],
            ['finances-budgets', 'Orçamentos', 'Criar e editar orçamentos anuais, itens de orçamento.'],
            ['finances-fees', 'Quotas', 'Gerar quotas mensais e extras, registar pagamentos, liquidar quotas.'],
            ['finances-expenses', 'Despesas', 'Registar despesas e associar a itens de orçamento.'],
            ['finances-revenues', 'Receitas', 'Registar receitas do condomínio.'],
            ['finances-historical-debts', 'Dívidas Históricas', 'Registar dívidas anteriores de frações.'],
            ['finances-historical-credits', 'Créditos Históricos', 'Registar créditos de frações.'],
            ['finances-reports', 'Relatórios', 'Relatórios financeiros, balanço, mapa de quotas, incumprimento.'],
            ['finances-fees-map', 'Mapa de Quotas', 'Visualizar estado das quotas por fração e mês.'],
            ['fractions', 'Frações', 'Criar e editar frações, atribuir a condóminos, permilagem, importar CSV.'],
            ['documents', 'Documentos', 'Upload e organização de documentos, pastas e versões.'],
            ['occurrences', 'Ocorrências', 'Criar ocorrências, workflow de estados, atribuir a fornecedores.'],
            ['assemblies', 'Assembleias', 'Criar assembleias, convocatórias, presenças, atas, votações.'],
            ['messages', 'Mensagens', 'Criar mensagens aos condóminos, responder e anexos.'],
            ['reservations', 'Reservas', 'Espaços comuns, reservas, aprovar e rejeitar.'],
            ['suppliers', 'Fornecedores', 'Gerir fornecedores, contratos, associar a ocorrências.'],
            ['bank-accounts', 'Contas Bancárias', 'Contas bancárias, movimentos e saldos.'],
            ['receipts', 'Recibos', 'Visualizar e descarregar recibos de quotas em PDF.'],
            ['notifications', 'Notificações', 'Ver notificações, marcar como lidas.'],
            ['profile', 'Perfil', 'Editar perfil, alterar password e preferências de email.'],
            ['subscriptions', 'Subscrições', 'Plano atual, alterar plano, histórico de pagamentos.'],
            ['invitations', 'Convites', 'Enviar convites a condóminos, aceitar convites.'],
        ];
        $stmt = $this->db->prepare("INSERT INTO help_articles (section_key, title, body_text, url_path) VALUES (?, ?, ?, ?)");
        foreach ($articles as $row) {
            $stmt->execute([
                $row[0],
                $row[1],
                $row[2],
                $row[0],
            ]);
        }
    }

    public function down(): void
    {
        $this->db->exec("DELETE FROM help_articles");
    }
}
