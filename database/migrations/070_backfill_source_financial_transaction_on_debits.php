<?php

class BackfillSourceFinancialTransactionOnDebits
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Preencher source_financial_transaction_id em débitos de quota_application que estão NULL,
        // usando o crédito (quota_payment) anterior na mesma conta que disponibilizou o valor.
        $stmt = $this->db->query("SHOW COLUMNS FROM fraction_account_movements LIKE 'source_financial_transaction_id'");
        if ($stmt->rowCount() == 0) {
            return;
        }

        $sql = "
            UPDATE fraction_account_movements fam
            INNER JOIN (
                SELECT d.id AS movement_id,
                    (SELECT c.source_reference_id
                     FROM fraction_account_movements c
                     WHERE c.fraction_account_id = d.fraction_account_id
                       AND c.type = 'credit' AND c.source_type = 'quota_payment'
                       AND c.source_reference_id IS NOT NULL
                       AND c.created_at <= d.created_at
                     ORDER BY c.created_at DESC
                     LIMIT 1) AS ft_id
                FROM fraction_account_movements d
                WHERE d.type = 'debit'
                  AND d.source_type = 'quota_application'
                  AND d.source_financial_transaction_id IS NULL
            ) sub ON sub.movement_id = fam.id AND sub.ft_id IS NOT NULL
            SET fam.source_financial_transaction_id = sub.ft_id
        ";
        $this->db->exec($sql);
    }

    public function down(): void
    {
        // Não reverter: o backfill é idempotente e corrige dados históricos.
        // Reverter poderia apagar a correção; mantém-se inalterado.
    }
}
