<?php

namespace App\Models;

use App\Core\Model;

class FractionAccount extends Model
{
    protected $table = 'fraction_accounts';

    /**
     * Get or create fraction account for a fraction
     */
    public function getOrCreate(int $fractionId, int $condominiumId): array
    {
        $existing = $this->getByFraction($fractionId);
        if ($existing) {
            return $existing;
        }

        $id = $this->create([
            'fraction_id' => $fractionId,
            'condominium_id' => $condominiumId,
            'balance' => 0.00
        ]);

        $account = $this->findById($id);
        return $account ?: [];
    }

    /**
     * Get fraction account by fraction_id
     */
    public function getByFraction(int $fractionId): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fraction_accounts WHERE fraction_id = :fraction_id LIMIT 1");
        $stmt->execute([':fraction_id' => $fractionId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get all fraction accounts by condominium
     */
    public function getByCondominium(int $condominiumId): array
    {
        if (!$this->db) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT fa.*, fr.identifier as fraction_identifier
            FROM fraction_accounts fa
            INNER JOIN fractions fr ON fr.id = fa.fraction_id
            WHERE fa.condominium_id = :condominium_id
            ORDER BY fr.identifier ASC
        ");
        $stmt->execute([':condominium_id' => $condominiumId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Add credit to fraction account (payment received)
     * Creates movement and updates balance. Returns fraction_account_movements.id
     */
    public function addCredit(int $fractionAccountId, float $amount, string $sourceType, ?int $sourceReferenceId = null, ?string $description = null): int
    {
        if (!$this->db || $amount <= 0) {
            throw new \Exception("Invalid amount or database not available");
        }

        $movementModel = new FractionAccountMovement();
        $movementId = $movementModel->create([
            'fraction_account_id' => $fractionAccountId,
            'type' => 'credit',
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_reference_id' => $sourceReferenceId,
            'description' => $description
        ]);

        $this->db->prepare("UPDATE fraction_accounts SET balance = balance + :amount WHERE id = :id")
            ->execute([':amount' => $amount, ':id' => $fractionAccountId]);

        return $movementId;
    }

    /**
     * Remove credit (reverse): subtract amount from balance and delete movement.
     * Used when deleting historical credits. Returns true on success.
     */
    public function removeCredit(int $movementId): bool
    {
        if (!$this->db) {
            return false;
        }
        $movementModel = new FractionAccountMovement();
        $mov = $movementModel->findById($movementId);
        if (!$mov || $mov['type'] !== 'credit') {
            return false;
        }
        $amount = (float)$mov['amount'];
        $faId = (int)$mov['fraction_account_id'];
        $this->db->prepare("UPDATE fraction_accounts SET balance = balance - :amount WHERE id = :id")
            ->execute([':amount' => $amount, ':id' => $faId]);
        $this->db->prepare("DELETE FROM fraction_account_movements WHERE id = :id")
            ->execute([':id' => $movementId]);
        return true;
    }

    /**
     * Update credit amount and optionally description. Adjusts balance by delta.
     */
    public function updateCredit(int $movementId, float $newAmount, ?string $newDescription = null): bool
    {
        if (!$this->db || $newAmount <= 0) {
            return false;
        }
        $movementModel = new FractionAccountMovement();
        $mov = $movementModel->findById($movementId);
        if (!$mov || $mov['type'] !== 'credit') {
            return false;
        }
        $oldAmount = (float)$mov['amount'];
        $delta = $newAmount - $oldAmount;
        $faId = (int)$mov['fraction_account_id'];

        $this->db->prepare("UPDATE fraction_accounts SET balance = balance + :delta WHERE id = :id")
            ->execute([':delta' => $delta, ':id' => $faId]);

        $movementModel = new FractionAccountMovement();
        $data = ['amount' => $newAmount];
        if ($newDescription !== null) {
            $data['description'] = $newDescription;
        }
        return $movementModel->updateAmountAndDescription($movementId, $data);
    }

    /**
     * Add debit to fraction account (quota application / amount applied to fees)
     * Creates movement and updates balance. Returns fraction_account_movements.id
     * @param int|null $financialTransactionId Quando a liquidação vem de um movimento financeiro (pagamento), para referência única nos débitos
     */
    public function addDebit(int $fractionAccountId, float $amount, string $sourceType, ?int $sourceReferenceId = null, ?string $description = null, ?int $financialTransactionId = null): int
    {
        if (!$this->db || $amount <= 0) {
            throw new \Exception("Invalid amount or database not available");
        }

        $movementModel = new FractionAccountMovement();
        $data = [
            'fraction_account_id' => $fractionAccountId,
            'type' => 'debit',
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_reference_id' => $sourceReferenceId,
            'description' => $description
        ];
        if ($financialTransactionId !== null) {
            $data['source_financial_transaction_id'] = $financialTransactionId;
        }
        $movementId = $movementModel->create($data);

        $this->db->prepare("UPDATE fraction_accounts SET balance = balance - :amount WHERE id = :id")
            ->execute([':amount' => $amount, ':id' => $fractionAccountId]);

        return $movementId;
    }

    /**
     * Create fraction account
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $stmt = $this->db->prepare("
            INSERT INTO fraction_accounts (condominium_id, fraction_id, balance)
            VALUES (:condominium_id, :fraction_id, :balance)
        ");
        $stmt->execute([
            ':condominium_id' => $data['condominium_id'],
            ':fraction_id' => $data['fraction_id'],
            ':balance' => $data['balance'] ?? 0.00
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Find fraction account by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM fraction_accounts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }
}
