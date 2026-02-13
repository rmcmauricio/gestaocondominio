<?php

class SeedHelpFaq
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $faqs = [
            ['Como altero o meu plano?', 'Aceda a Subscrições no menu e escolha "Alterar plano". Pode fazer upgrade ou downgrade; as alterações aplicam-se no próximo período de faturação.', 'plano subscrição alterar', 1],
            ['Como gero quotas mensais?', 'Em Finanças > Quotas, use "Gerar quotas" e selecione o mês e as frações. As quotas são criadas com o valor definido no orçamento.', 'quotas gerar mensais finanças', 2],
            ['Como convido um condómino?', 'Em Frações, edite a fração e use "Convidar condómino" ou aceda a Convites para enviar um email de convite com link de aceitação.', 'convite condómino fração', 3],
            ['Onde vejo os pagamentos pendentes?', 'Em Finanças > Quotas pode filtrar por estado (pendente/pago). Também há relatórios de incumprimento em Relatórios.', 'pagamentos pendentes quotas', 4],
            ['Como adiciono um fornecedor?', 'Em Fornecedores > Criar, preencha os dados. Pode associar despesas e contratos ao fornecedor depois.', 'fornecedor criar', 5],
            ['Como faço backup do condomínio?', 'Na página do condomínio (ou Definições do condomínio), use a opção "Criar backup". O ficheiro pode ser descarregado e restaurado depois.', 'backup restauro condomínio', 6],
            ['O que são quotas extras?', 'Quotas extras são quotas além da mensalidade regular (ex.: obras, despesas pontuais). Podem ser geradas em Finanças > Quotas com tipo "Extra".', 'quotas extras finanças', 7],
            ['Como envio uma mensagem aos condóminos?', 'Use a secção Mensagens no menu. Crie uma nova mensagem e escolha os destinatários (todas as frações ou seleção).', 'mensagens condóminos', 8],
            ['Como marco uma quota como paga?', 'Em Finanças > Quotas, abra a quota e use "Registar pagamento" ou "Marcar como paga" para registar o valor e a data.', 'quota paga pagamento', 9],
            ['Onde configuro métodos de pagamento?', 'Os métodos de pagamento (Multibanco, MB Way, etc.) são configurados na área Super Admin em Métodos de Pagamento (se tiver permissão).', 'pagamento multibanco mbway', 10],
        ];
        $stmt = $this->db->prepare("INSERT INTO help_faq (question, answer, keywords, sort_order) VALUES (?, ?, ?, ?)");
        foreach ($faqs as $row) {
            $stmt->execute($row);
        }
    }

    public function down(): void
    {
        $this->db->exec("DELETE FROM help_faq");
    }
}
