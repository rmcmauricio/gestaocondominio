<?php

class AddReopenedToAssemblyAccountApprovals
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        $this->db->exec("
            ALTER TABLE assembly_account_approvals
            ADD COLUMN reopened_at DATETIME NULL AFTER notes,
            ADD COLUMN reopened_by INT NULL AFTER reopened_at,
            ADD COLUMN reopened_assembly_id INT NULL AFTER reopened_by,
            ADD CONSTRAINT fk_approvals_reopened_assembly
                FOREIGN KEY (reopened_assembly_id) REFERENCES assemblies(id) ON DELETE SET NULL,
            ADD CONSTRAINT fk_approvals_reopened_by
                FOREIGN KEY (reopened_by) REFERENCES users(id) ON DELETE SET NULL
        ");
    }

    public function down(): void
    {
        $this->db->exec("
            ALTER TABLE assembly_account_approvals
            DROP FOREIGN KEY fk_approvals_reopened_assembly,
            DROP FOREIGN KEY fk_approvals_reopened_by,
            DROP COLUMN reopened_at,
            DROP COLUMN reopened_by,
            DROP COLUMN reopened_assembly_id
        ");
    }
}
