<?php

namespace App\Services;

use App\Models\Fee;
use App\Models\FeePayment;
use App\Models\Receipt;
use App\Models\Fraction;
use App\Models\Condominium;
use App\Models\Document;

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
            $fractionIdentifier = $fraction['identifier'] ?? '';

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

            // Generate PDF with folder structure
            $filePath = $pdfService->generateReceiptPdf($htmlContent, $receiptId, $receiptNumber, $condominiumId, $fractionIdentifier, (string)$periodYear, $userId);
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

            // Create entry in documents table
            try {
                $receiptFolderService = new \App\Services\ReceiptFolderService();
                $folderPath = $receiptFolderService->ensureReceiptFolders($condominiumId, (string)$periodYear, $fractionIdentifier, $userId);

                $documentModel = new Document();
                $documentModel->create([
                    'condominium_id' => $condominiumId,
                    'fraction_id' => (int)$fee['fraction_id'],
                    'folder' => $folderPath,
                    'title' => 'Recibo ' . $receiptNumber,
                    'description' => 'Recibo gerado automaticamente para quota totalmente paga - Fração: ' . $fractionIdentifier . ' - Ano: ' . $periodYear,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'mime_type' => 'application/pdf',
                    'visibility' => 'fraction',
                    'document_type' => 'receipt',
                    'uploaded_by' => $userId
                ]);
            } catch (\Exception $e) {
                // Log error but don't fail receipt generation
                error_log("Error creating document entry for receipt: " . $e->getMessage());
            }

            // Log audit
            $auditService = new \App\Services\AuditService();
            $auditService->logDocument([
                'condominium_id' => $condominiumId,
                'document_type' => 'receipt',
                'action' => 'generate',
                'user_id' => $userId,
                'receipt_id' => $receiptId,
                'fee_id' => $feeId,
                'file_path' => $filePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'description' => 'Recibo gerado automaticamente para quota totalmente paga - Fração: ' . ($fraction['identifier'] ?? 'N/A') . ' - Número: ' . $receiptNumber,
                'metadata' => [
                    'receipt_number' => $receiptNumber,
                    'receipt_type' => 'final',
                    'amount' => $fee['amount'],
                    'fraction_identifier' => $fraction['identifier'] ?? null,
                    'period_year' => $periodYear,
                    'period_month' => $fee['period_month'] ?? null
                ]
            ]);
        } catch (\Exception $e) {
            error_log("ReceiptService::generateForFullyPaidFee error: " . $e->getMessage());
        }
    }

    /**
     * Regenerate receipts for a fee (delete existing and create new if fully paid).
     * Used when payments are deleted or changed.
     *
     * @param int $feeId
     * @param int $condominiumId
     * @param int $userId
     */
    public function regenerateReceiptsForFee(int $feeId, int $condominiumId, int $userId): void
    {
        try {
            $feeModel = new Fee();
            $fee = $feeModel->findById($feeId);
            if (!$fee) {
                return;
            }

            $receiptModel = new Receipt();
            $feePaymentModel = new FeePayment();
            $existingReceipts = $receiptModel->getByFee($feeId);

            global $db;
            $documentModel = new Document();
            foreach ($existingReceipts as $receipt) {
                if (!empty($receipt['file_path'])) {
                    $filePath = $receipt['file_path'];
                    $fullPath = strpos($filePath, 'condominiums/') === 0
                        ? dirname(__DIR__, 2) . '/storage/' . $filePath
                        : dirname(__DIR__, 2) . '/storage/documents/' . $filePath;
                    if (file_exists($fullPath)) {
                        @unlink($fullPath);
                    }
                    if ($db) {
                        $docStmt = $db->prepare("
                            SELECT id FROM documents
                            WHERE document_type = 'receipt' AND condominium_id = :condominium_id
                            AND (file_path = :file_path OR title = CONCAT('Recibo ', :receipt_number))
                        ");
                        $docStmt->execute([
                            ':file_path' => $filePath,
                            ':condominium_id' => $condominiumId,
                            ':receipt_number' => $receipt['receipt_number'] ?? ''
                        ]);
                        $doc = $docStmt->fetch();
                        if ($doc) {
                            $documentModel->delete((int)$doc['id']);
                        }
                    }
                }
                if ($db) {
                    $stmt = $db->prepare("DELETE FROM receipts WHERE id = :id");
                    $stmt->execute([':id' => $receipt['id']]);
                }
            }

            $totalPaid = $feePaymentModel->getTotalPaid($feeId);
            $isFullyPaid = $totalPaid >= (float)$fee['amount'];

            if ($isFullyPaid) {
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

                $pdfService = new PdfService();
                $periodYear = (int)$fee['period_year'];
                $receiptNumber = $receiptModel->generateReceiptNumber($condominiumId, $periodYear);
                $fractionIdentifier = $fraction['identifier'] ?? '';
                $htmlContent = $pdfService->generateReceiptReceipt($fee, $fraction, $condominium, null, 'final');

                $receiptId = $receiptModel->create([
                    'fee_id' => $feeId,
                    'fee_payment_id' => null,
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

                $filePath = $pdfService->generateReceiptPdf($htmlContent, $receiptId, $receiptNumber, $condominiumId, $fractionIdentifier, (string)$periodYear, $userId);
                $fullPath = dirname(__DIR__, 2) . '/storage/' . $filePath;
                $fileSize = file_exists($fullPath) ? filesize($fullPath) : 0;
                $fileName = basename($filePath);

                if ($db) {
                    $stmt = $db->prepare("UPDATE receipts SET file_path = :file_path, file_name = :file_name, file_size = :file_size WHERE id = :id");
                    $stmt->execute([
                        ':file_path' => $filePath,
                        ':file_name' => $fileName,
                        ':file_size' => $fileSize,
                        ':id' => $receiptId
                    ]);
                }

                try {
                    $receiptFolderService = new ReceiptFolderService();
                    $folderPath = $receiptFolderService->ensureReceiptFolders($condominiumId, (string)$periodYear, $fractionIdentifier, $userId);
                    $documentModel->create([
                        'condominium_id' => $condominiumId,
                        'fraction_id' => (int)$fee['fraction_id'],
                        'folder' => $folderPath,
                        'title' => 'Recibo ' . $receiptNumber,
                        'description' => 'Recibo regenerado para quota #' . $feeId . ' - Fração: ' . $fractionIdentifier . ' - Ano: ' . $periodYear,
                        'file_path' => $filePath,
                        'file_name' => $fileName,
                        'file_size' => $fileSize,
                        'mime_type' => 'application/pdf',
                        'visibility' => 'fraction',
                        'document_type' => 'receipt',
                        'uploaded_by' => $userId
                    ]);
                } catch (\Exception $e) {
                    error_log("Error creating document entry for receipt: " . $e->getMessage());
                }

                $auditService = new AuditService();
                $auditService->logDocument([
                    'condominium_id' => $condominiumId,
                    'document_type' => 'receipt',
                    'action' => 'regenerate',
                    'user_id' => $userId,
                    'receipt_id' => $receiptId,
                    'fee_id' => $feeId,
                    'file_path' => $filePath,
                    'file_name' => $fileName,
                    'file_size' => $fileSize,
                    'description' => 'Recibo regenerado para quota #' . $feeId . ' - Número: ' . $receiptNumber,
                    'metadata' => [
                        'receipt_number' => $receiptNumber,
                        'receipt_type' => 'final',
                        'amount' => $fee['amount']
                    ]
                ]);
            }
        } catch (\Exception $e) {
            error_log("ReceiptService::regenerateReceiptsForFee error: " . $e->getMessage());
        }
    }
}
