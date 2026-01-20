<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\FractionAccount;

class LiquidationService
{
    protected $feeModel;
    protected $feePaymentModel;
    protected $fractionAccountModel;

    public function __construct()
    {
        $this->feeModel = new Fee();
        $this->feePaymentModel = new FeePayment();
        $this->fractionAccountModel = new FractionAccount();
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

        foreach ($fees as $fee) {
            if ($balance <= 0) {
                break;
            }

            $feeId = (int)$fee['id'];
            $feeAmount = (float)$fee['amount'];
            $paid = $this->feePaymentModel->getTotalPaid($feeId);
            $remaining = $feeAmount - $paid;

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
     * Get pending/overdue fees for the fraction with remaining amount > 0,
     * ordered by oldest first: period_year, period_month, regular before extra.
     */
    protected function getPendingFeesOrdered(int $fractionId): array
    {
        return $this->feeModel->getPendingOrderedForLiquidation($fractionId);
    }
}
