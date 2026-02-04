<?php

namespace App\Services;

use App\Models\Invoice;

class InvoiceService
{
    protected $invoiceModel;

    public function __construct()
    {
        $this->invoiceModel = new Invoice();
    }

    /**
     * Create invoice for subscription
     */
    public function createInvoice(int $subscriptionId, float $amount, array $metadata = []): int
    {
        return $this->invoiceModel->create([
            'subscription_id' => $subscriptionId,
            'amount' => $amount,
            'tax_amount' => 0, // IVA is included in price
            'total_amount' => $amount,
            'status' => 'pending',
            'due_date' => date('Y-m-d', strtotime('+7 days')),
            'metadata' => !empty($metadata) ? $metadata : null
        ]);
    }
}





