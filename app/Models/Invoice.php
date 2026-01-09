<?php

namespace App\Models;

use App\Core\Model;

class Invoice
{
    protected $db;
    protected $table = 'invoices';

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Create invoice
     */
    public function create(array $data): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $invoiceNumber = $this->generateInvoiceNumber();
        
        $stmt = $this->db->prepare("
            INSERT INTO invoices (
                subscription_id, invoice_number, amount, tax_amount, total_amount,
                status, due_date, notes
            )
            VALUES (
                :subscription_id, :invoice_number, :amount, :tax_amount, :total_amount,
                :status, :due_date, :notes
            )
        ");

        $stmt->execute([
            ':subscription_id' => $data['subscription_id'],
            ':invoice_number' => $invoiceNumber,
            ':amount' => $data['amount'],
            ':tax_amount' => $data['tax_amount'] ?? 0,
            ':total_amount' => $data['total_amount'] ?? $data['amount'],
            ':status' => $data['status'] ?? 'pending',
            ':due_date' => $data['due_date'] ?? date('Y-m-d', strtotime('+7 days')),
            ':notes' => $data['notes'] ?? null
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid(int $invoiceId): bool
    {
        if (!$this->db) {
            return false;
        }

        $stmt = $this->db->prepare("
            UPDATE invoices 
            SET status = 'paid',
                paid_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
        ");

        return $stmt->execute([':id' => $invoiceId]);
    }

    /**
     * Find invoice by ID
     */
    public function findById(int $id): ?array
    {
        if (!$this->db) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Generate invoice number
     */
    protected function generateInvoiceNumber(): string
    {
        $year = date('Y');
        $month = date('m');
        
        // Get last invoice number for this month
        $stmt = $this->db->prepare("
            SELECT invoice_number 
            FROM invoices 
            WHERE invoice_number LIKE :pattern
            ORDER BY id DESC 
            LIMIT 1
        ");
        
        $pattern = "INV-{$year}{$month}-%";
        $stmt->execute([':pattern' => $pattern]);
        $last = $stmt->fetch();
        
        if ($last) {
            $lastNumber = (int)substr($last['invoice_number'], -4);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return sprintf("INV-%s%s-%04d", $year, $month, $newNumber);
    }
}





