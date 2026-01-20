<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\Receipt;
use App\Models\Fraction;
use App\Models\Condominium;

/**
 * Gera recibos para quotas totalmente liquidadas.
 */
class ReceiptService
{
    /**
     * Gera recibo final para uma quota totalmente paga.
     * Não gera se já existir recibo final para a quota.
     *
     * @param int $feeId
     * @param int|null $feePaymentId fee_payment que completou a quota (opcional)
     * @param int $condominiumId
     * @param int $userId
     */
    public function generateForFullyPaidFee(int $feeId, ?int $feePaymentId, int $condominiumId, int $userId): void
    {
        try {
            $feeModel = new Fee();
            $fee = $feeModel->findById($feeId);
            if (!$fee) {
                return;
            }

            $fractionModel = new Fraction();
            $fraction = $fractionModel->findById($fee['fraction_id']);
            if (!$fraction) {
                return;
            }

            $condominiumModel = new Condominium();
            $condominium = $condominiumModel->findById($condominiumId);
            if (!$condominium) {
                return;
            }

            $receiptModel = new Receipt();
            $existingReceipts = $receiptModel->getByFee($feeId);
            foreach ($existingReceipts as $r) {
                if (($r['receipt_type'] ?? '') === 'final') {
                    return;
                }
            }

            $periodYear = (int)($fee['period_year'] ?? date('Y'));
            $receiptNumber = $receiptModel->generateReceiptNumber($condominiumId, $periodYear);

            $pdfService = new PdfService();
            $htmlContent = $pdfService->generateReceiptReceipt($fee, $fraction, $condominium, null, 'final');

            $receiptId = $receiptModel->create([
                'fee_id' => $feeId,
                'fee_payment_id' => $feePaymentId,
                'condominium_id' => $condominiumId,
                'fraction_id' => (int)$fee['fraction_id'],
                'receipt_number' => $receiptNumber,
                'receipt_type' => 'final',
                'amount' => (float)$fee['amount'],
                'file_path' => '',
                'file_name' => '',
                'file_size' => 0,
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => $userId
            ]);

            $filePath = $pdfService->generateReceiptPdf($htmlContent, $receiptId, $receiptNumber, $condominiumId);
            $fullPath = dirname(__DIR__, 2) . '/storage/' . $filePath;
            $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
            $fileName = basename($filePath);

            global $db;
            if ($db) {
                $stmt = $db->prepare("UPDATE receipts SET file_path = :file_path, file_name = :file_name, file_size = :file_size WHERE id = :id");
                $stmt->execute([
                    ':file_path' => $filePath,
                    ':file_name' => $fileName,
                    ':file_size' => $fileSize,
                    ':id' => $receiptId
                ]);
            }
        } catch (\Exception $e) {
            error_log("ReceiptService::generateForFullyPaidFee error: " . $e->getMessage());
        }
    }
}
