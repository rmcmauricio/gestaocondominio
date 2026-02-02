<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\FractionAccount;
use App\Services\AuditService;

class LiquidationService
{
    protected $feeModel;
    protected $feePaymentModel;
    protected $fractionAccountModel;
    protected $auditService;

    public function __construct()
    {
        $this->feeModel = new Fee();
        $this->feePaymentModel = new FeePayment();
        $this->fractionAccountModel = new FractionAccount();
        $this->auditService = new AuditService();
    }

    /**
     * Apply fraction account balance to oldest pending fees.
     * Creates fee_payments (including partial), debit movements, updates fee status.
     *
     * @param int $fractionId
     * @param int|null $createdBy User ID for fee_payments created_by (audit)
     * @param string|null $paymentDate Date for fee_payments (Y-m-d). Default: today
     * @param int|null $financialTransactionId ID do movimento financeiro que originou o crédito (para ligar os débitos à mesma referência)
     * @return array ['fully_paid' => [fee_id,...], 'fully_paid_payments' => [fee_id=>payment_id], 'partially_paid' => [fee_id => remaining,...], 'credit_remaining' => float]
     */
    public function liquidate(int $fractionId, ?int $createdBy = null, ?string $paymentDate = null, ?int $financialTransactionId = null): array
    {
        $result = ['fully_paid' => [], 'fully_paid_payments' => [], 'partially_paid' => [], 'credit_remaining' => 0.0];
        $payDate = $paymentDate ?: date('Y-m-d');

        $account = $this->fractionAccountModel->getByFraction($fractionId);
        if (!$account || (float)($account['balance'] ?? 0) <= 0) {
            $result['credit_remaining'] = $account ? (float)$account['balance'] : 0.0;
            return $result;
        }

        $balance = (float)$account['balance'];
        $fractionAccountId = (int)$account['id'];

        $fees = $this->getPendingFeesOrdered($fractionId);
        if (empty($fees)) {
            $result['credit_remaining'] = $balance;
            return $result;
        }

        // Get condominium_id from first fee for audit logging
        $condominiumId = !empty($fees) && isset($fees[0]['condominium_id']) ? (int)$fees[0]['condominium_id'] : null;

        foreach ($fees as $fee) {
            if ($balance <= 0) {
                break;
            }

            $feeId = (int)$fee['id'];
            $feeAmount = (float)$fee['amount'];
            $paid = $this->feePaymentModel->getTotalPaid($feeId);
            $remaining = $feeAmount - $paid;
            $oldStatus = $fee['status'] ?? 'pending';

            if ($remaining <= 0) {
                continue;
            }

            $toApply = min($balance, $remaining);

            $paymentId = $this->feePaymentModel->create([
                'fee_id' => $feeId,
                'financial_transaction_id' => null,
                'amount' => $toApply,
                'payment_method' => 'transfer',
                'reference' => null,
                'payment_date' => $payDate,
                'notes' => 'Liquidação automática (conta da fração)',
                'created_by' => $createdBy
            ]);

            // Log fee payment creation
            if ($condominiumId) {
                $this->auditService->logFinancial([
                    'condominium_id' => $condominiumId,
                    'entity_type' => 'fee_payment',
                    'entity_id' => $paymentId,
                    'action' => 'fee_payment_created',
                    'user_id' => $createdBy,
                    'amount' => $toApply,
                    'new_status' => 'completed',
                    'description' => "Pagamento de quota criado via liquidação automática. Quota ID: {$feeId}, Valor: €" . number_format($toApply, 2, ',', '.') . ($remaining <= $toApply ? ' (quota totalmente paga)' : ' (pagamento parcial)')
                ]);
            }

            $this->fractionAccountModel->addDebit(
                $fractionAccountId,
                $toApply,
                'quota_application',
                $paymentId,
                'Aplicação à quota ' . ($fee['reference'] ?? '#' . $feeId),
                $financialTransactionId
            );

            $balance -= $toApply;
            $remaining -= $toApply;

            if ($remaining <= 0) {
                $this->feeModel->markAsPaid($feeId);
                
                // Log fee status change to paid
                if ($condominiumId) {
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee',
                        'entity_id' => $feeId,
                        'action' => 'fee_marked_as_paid',
                        'user_id' => $createdBy,
                        'amount' => $feeAmount,
                        'old_status' => $oldStatus,
                        'new_status' => 'paid',
                        'description' => "Quota marcada como paga via liquidação automática. Quota ID: {$feeId}, Valor total: €" . number_format($feeAmount, 2, ',', '.') . ($fee['reference'] ? " - Referência: {$fee['reference']}" : '')
                    ]);
                }
                
                $result['fully_paid'][] = $feeId;
                $result['fully_paid_payments'][$feeId] = $paymentId;
            } else {
                $result['partially_paid'][$feeId] = $remaining;
            }
        }

        $result['credit_remaining'] = $balance;
        return $result;
    }

    /**
     * Apply fraction account balance to selected fees first, then oldest pending fees.
     * Similar to liquidate() but allows selecting specific fees to liquidate first.
     *
     * @param int $fractionId
     * @param array $selectedFeeIds Array of fee IDs to liquidate first (empty = auto liquidate oldest)
     * @param int|null $createdBy User ID for fee_payments created_by (audit)
     * @param string|null $paymentDate Date for fee_payments (Y-m-d). Default: today
     * @param int|null $financialTransactionId ID do movimento financeiro que originou o crédito
     * @return array ['fully_paid' => [fee_id,...], 'fully_paid_payments' => [fee_id=>payment_id], 'partially_paid' => [fee_id => remaining,...], 'credit_remaining' => float]
     */
    public function liquidateSelectedFees(
        int $fractionId, 
        array $selectedFeeIds, 
        ?int $createdBy = null, 
        ?string $paymentDate = null, 
        ?int $financialTransactionId = null
    ): array
    {
        $result = ['fully_paid' => [], 'fully_paid_payments' => [], 'partially_paid' => [], 'credit_remaining' => 0.0];
        $payDate = $paymentDate ?: date('Y-m-d');

        $account = $this->fractionAccountModel->getByFraction($fractionId);
        if (!$account || (float)($account['balance'] ?? 0) <= 0) {
            $result['credit_remaining'] = $account ? (float)$account['balance'] : 0.0;
            return $result;
        }

        $balance = (float)$account['balance'];
        $fractionAccountId = (int)$account['id'];

        // If no selected fees, use standard liquidation
        if (empty($selectedFeeIds)) {
            return $this->liquidate($fractionId, $createdBy, $paymentDate, $financialTransactionId);
        }

        // Get all pending fees ordered
        $allPendingFees = $this->getPendingFeesOrdered($fractionId);
        if (empty($allPendingFees)) {
            $result['credit_remaining'] = $balance;
            return $result;
        }

        // Get condominium_id from first fee for audit logging
        $condominiumId = !empty($allPendingFees) && isset($allPendingFees[0]['condominium_id']) ? (int)$allPendingFees[0]['condominium_id'] : null;

        // Separate selected fees from others
        $selectedFees = [];
        $otherFees = [];
        $selectedFeeIdsMap = array_flip($selectedFeeIds); // For faster lookup

        foreach ($allPendingFees as $fee) {
            $feeId = (int)$fee['id'];
            if (isset($selectedFeeIdsMap[$feeId])) {
                $selectedFees[] = $fee;
            } else {
                $otherFees[] = $fee;
            }
        }

        // Validate selected fees belong to fraction
        foreach ($selectedFees as $fee) {
            $feeId = (int)$fee['id'];
            // Verify fee exists and belongs to fraction (already filtered by getPendingFeesOrdered)
            $paid = $this->feePaymentModel->getTotalPaid($feeId);
            $remaining = (float)$fee['amount'] - $paid;
            if ($remaining <= 0) {
                // Skip fees that are already fully paid
                continue;
            }
        }

        // Process selected fees first (in order - oldest first)
        foreach ($selectedFees as $fee) {
            if ($balance <= 0) {
                break;
            }

            $feeId = (int)$fee['id'];
            $feeAmount = (float)$fee['amount'];
            $paid = $this->feePaymentModel->getTotalPaid($feeId);
            $remaining = $feeAmount - $paid;
            $oldStatus = $fee['status'] ?? 'pending';

            if ($remaining <= 0) {
                continue;
            }

            $toApply = min($balance, $remaining);

            $paymentId = $this->feePaymentModel->create([
                'fee_id' => $feeId,
                'financial_transaction_id' => null,
                'amount' => $toApply,
                'payment_method' => 'transfer',
                'reference' => null,
                'payment_date' => $payDate,
                'notes' => 'Liquidação automática (conta da fração) - quota selecionada',
                'created_by' => $createdBy
            ]);

            // Log fee payment creation
            if ($condominiumId) {
                $this->auditService->logFinancial([
                    'condominium_id' => $condominiumId,
                    'entity_type' => 'fee_payment',
                    'entity_id' => $paymentId,
                    'action' => 'fee_payment_created',
                    'user_id' => $createdBy,
                    'amount' => $toApply,
                    'new_status' => 'completed',
                    'description' => "Pagamento de quota criado via liquidação automática (selecionada). Quota ID: {$feeId}, Valor: €" . number_format($toApply, 2, ',', '.') . ($remaining <= $toApply ? ' (quota totalmente paga)' : ' (pagamento parcial)')
                ]);
            }

            $this->fractionAccountModel->addDebit(
                $fractionAccountId,
                $toApply,
                'quota_application',
                $paymentId,
                'Aplicação à quota ' . ($fee['reference'] ?? '#' . $feeId),
                $financialTransactionId
            );

            $balance -= $toApply;
            $remaining -= $toApply;

            if ($remaining <= 0) {
                $this->feeModel->markAsPaid($feeId);
                
                // Log fee status change to paid
                if ($condominiumId) {
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee',
                        'entity_id' => $feeId,
                        'action' => 'fee_marked_as_paid',
                        'user_id' => $createdBy,
                        'amount' => $feeAmount,
                        'old_status' => $oldStatus,
                        'new_status' => 'paid',
                        'description' => "Quota marcada como paga via liquidação automática (selecionada). Quota ID: {$feeId}, Valor total: €" . number_format($feeAmount, 2, ',', '.') . ($fee['reference'] ? " - Referência: {$fee['reference']}" : '')
                    ]);
                }
                
                $result['fully_paid'][] = $feeId;
                $result['fully_paid_payments'][$feeId] = $paymentId;
            } else {
                $result['partially_paid'][$feeId] = $remaining;
            }
        }

        // If balance remains, apply to oldest pending fees (excluding already processed selected fees)
        if ($balance > 0 && !empty($otherFees)) {
            foreach ($otherFees as $fee) {
                if ($balance <= 0) {
                    break;
                }

                $feeId = (int)$fee['id'];
                $feeAmount = (float)$fee['amount'];
                $paid = $this->feePaymentModel->getTotalPaid($feeId);
                $remaining = $feeAmount - $paid;
                $oldStatus = $fee['status'] ?? 'pending';

                if ($remaining <= 0) {
                    continue;
                }

                $toApply = min($balance, $remaining);

                $paymentId = $this->feePaymentModel->create([
                    'fee_id' => $feeId,
                    'financial_transaction_id' => null,
                    'amount' => $toApply,
                    'payment_method' => 'transfer',
                    'reference' => null,
                    'payment_date' => $payDate,
                    'notes' => 'Liquidação automática (conta da fração) - valor restante',
                    'created_by' => $createdBy
                ]);

                // Log fee payment creation
                if ($condominiumId) {
                    $this->auditService->logFinancial([
                        'condominium_id' => $condominiumId,
                        'entity_type' => 'fee_payment',
                        'entity_id' => $paymentId,
                        'action' => 'fee_payment_created',
                        'user_id' => $createdBy,
                        'amount' => $toApply,
                        'new_status' => 'completed',
                        'description' => "Pagamento de quota criado via liquidação automática (valor restante). Quota ID: {$feeId}, Valor: €" . number_format($toApply, 2, ',', '.') . ($remaining <= $toApply ? ' (quota totalmente paga)' : ' (pagamento parcial)')
                    ]);
                }

                $this->fractionAccountModel->addDebit(
                    $fractionAccountId,
                    $toApply,
                    'quota_application',
                    $paymentId,
                    'Aplicação à quota ' . ($fee['reference'] ?? '#' . $feeId),
                    $financialTransactionId
                );

                $balance -= $toApply;
                $remaining -= $toApply;

                if ($remaining <= 0) {
                    $this->feeModel->markAsPaid($feeId);
                    
                    // Log fee status change to paid
                    if ($condominiumId) {
                        $this->auditService->logFinancial([
                            'condominium_id' => $condominiumId,
                            'entity_type' => 'fee',
                            'entity_id' => $feeId,
                            'action' => 'fee_marked_as_paid',
                            'user_id' => $createdBy,
                            'amount' => $feeAmount,
                            'old_status' => $oldStatus,
                            'new_status' => 'paid',
                            'description' => "Quota marcada como paga via liquidação automática (valor restante). Quota ID: {$feeId}, Valor total: €" . number_format($feeAmount, 2, ',', '.') . ($fee['reference'] ? " - Referência: {$fee['reference']}" : '')
                        ]);
                    }
                    
                    $result['fully_paid'][] = $feeId;
                    $result['fully_paid_payments'][$feeId] = $paymentId;
                } else {
                    $result['partially_paid'][$feeId] = $remaining;
                }
            }
        }

        $result['credit_remaining'] = $balance;
        return $result;
    }

    /**
     * Get pending/overdue fees for the fraction with remaining amount > 0,
     * ordered by oldest first: period_year, period_month, regular before extra.
     */
    protected function getPendingFeesOrdered(int $fractionId): array
    {
        return $this->feeModel->getPendingOrderedForLiquidation($fractionId);
    }
}
