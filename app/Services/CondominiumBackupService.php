<?php

namespace App\Services;

/**
 * Backup and restore condominiums with all associated data (100% restore).
 * Handles users and admins associations by matching existing users by email.
 */
class CondominiumBackupService
{
    protected $db;
    protected $basePath;
    protected $backupPath;

    private const BACKUP_VERSION = 1;

    public function __construct()
    {
        global $db;
        $this->db = $db;
        $this->basePath = __DIR__ . '/../../storage';
        $this->backupPath = $this->basePath . '/backups';
    }

    /**
     * Create full backup of a condominium
     * @return string Path to backup file (.backup = zip)
     */
    public function backup(int $condominiumId): string
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        $condominium = $this->getRow("condominiums", $condominiumId);
        if (!$condominium) {
            throw new \Exception("Condomínio não encontrado");
        }

        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0755, true);
        }

        $tempDir = $this->backupPath . '/temp_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $data = [
                'version' => self::BACKUP_VERSION,
                'created_at' => date('Y-m-d H:i:s'),
                'condominium_id' => $condominiumId,
                'condominium' => $condominium,
                'tables' => []
            ];

            $data['tables']['users_snapshot'] = $this->exportUsersForCondominium($condominiumId);
            $data['tables']['condominiums'] = [$condominium];
            $data['tables']['fractions'] = $this->exportTable("fractions", "condominium_id", $condominiumId);
            $data['tables']['spaces'] = $this->exportTable("spaces", "condominium_id", $condominiumId);
            $data['tables']['bank_accounts'] = $this->exportTable("bank_accounts", "condominium_id", $condominiumId);
            $data['tables']['folders'] = $this->exportTable("folders", "condominium_id", $condominiumId);
            $data['tables']['budgets'] = $this->exportTable("budgets", "condominium_id", $condominiumId);
            $data['tables']['budget_items'] = $this->exportBudgetItems($condominiumId);
            $data['tables']['suppliers'] = $this->exportTable("suppliers", "condominium_id", $condominiumId);
            $data['tables']['contracts'] = $this->exportTable("contracts", "condominium_id", $condominiumId);
            $data['tables']['fees'] = $this->exportTable("fees", "condominium_id", $condominiumId);
            $data['tables']['fraction_accounts'] = $this->exportFractionAccounts($condominiumId);
            $data['tables']['condominium_users'] = $this->exportTable("condominium_users", "condominium_id", $condominiumId);
            $data['tables']['assemblies'] = $this->exportTable("assemblies", "condominium_id", $condominiumId);
            $data['tables']['assembly_agenda_points'] = $this->exportAssemblyAgendaPoints($condominiumId);
            $data['tables']['assembly_vote_topics'] = $this->exportAssemblyVoteTopics($condominiumId);
            $data['tables']['assembly_attendees'] = $this->exportAssemblyAttendees($condominiumId);
            $data['tables']['assembly_votes'] = $this->exportAssemblyVotes($condominiumId);
            $data['tables']['standalone_votes'] = $this->exportTable("standalone_votes", "condominium_id", $condominiumId);
            $data['tables']['vote_options'] = $this->exportTable("vote_options", "condominium_id", $condominiumId);
            $data['tables']['standalone_vote_responses'] = $this->exportStandaloneVoteResponses($condominiumId);
            $data['tables']['documents'] = $this->exportTable("documents", "condominium_id", $condominiumId);
            $data['tables']['minutes_revisions'] = $this->exportMinutesRevisions($condominiumId);
            $data['tables']['financial_transactions'] = $this->exportTable("financial_transactions", "condominium_id", $condominiumId);
            $data['tables']['fee_payments'] = $this->exportFeePayments($condominiumId);
            $data['tables']['fee_payment_history'] = $this->exportFeePaymentHistory($condominiumId);
            $data['tables']['fraction_account_movements'] = $this->exportFractionAccountMovements($condominiumId);
            $data['tables']['revenues'] = $this->exportTable("revenues", "condominium_id", $condominiumId);
            $data['tables']['expenses'] = $this->exportTable("expenses", "condominium_id", $condominiumId);
            $data['tables']['receipts'] = $this->exportTable("receipts", "condominium_id", $condominiumId);
            $data['tables']['reservations'] = $this->exportTable("reservations", "condominium_id", $condominiumId);
            $data['tables']['messages'] = $this->exportTable("messages", "condominium_id", $condominiumId);
            $data['tables']['message_attachments'] = $this->exportTable("message_attachments", "condominium_id", $condominiumId);
            $data['tables']['occurrences'] = $this->exportTable("occurrences", "condominium_id", $condominiumId);
            $data['tables']['occurrence_comments'] = $this->exportOccurrenceComments($condominiumId);
            $data['tables']['occurrence_history'] = $this->exportOccurrenceHistory($condominiumId);
            $data['tables']['occurrence_attachments'] = $this->exportTable("occurrence_attachments", "condominium_id", $condominiumId);
            $data['tables']['notifications'] = $this->exportTable("notifications", "condominium_id", $condominiumId);
            $data['tables']['invitations'] = $this->exportTable("invitations", "condominium_id", $condominiumId);
            $data['tables']['assembly_account_approvals'] = $this->exportTable("assembly_account_approvals", "condominium_id", $condominiumId);
            $data['tables']['admin_transfer_pending'] = $this->exportTable("admin_transfer_pending", "condominium_id", $condominiumId);

            // assembly_agenda_point_vote_topics pivot
            $data['tables']['assembly_agenda_point_vote_topics'] = $this->exportAssemblyAgendaPointVoteTopics($condominiumId);

            file_put_contents($tempDir . '/data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // Copy storage files
            $condoStorage = $this->basePath . '/condominiums/' . $condominiumId;
            if (is_dir($condoStorage)) {
                $this->copyDirectory($condoStorage, $tempDir . '/files');
            }

            // Documents in storage/documents that belong to this condominium
            $this->copyCondominiumDocuments($condominiumId, $tempDir . '/documents_storage');

            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $condominium['name']);
            $filename = 'backup_' . $condominiumId . '_' . $safeName . '_' . date('Y-m-d_H-i-s') . '.backup';
            $zipPath = $this->backupPath . '/' . $filename;

            $this->createZip($tempDir, $zipPath);

            return $zipPath;
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    /**
     * Restore condominium from backup file
     * @param string $backupPath Path to .backup file
     * @param int|null $targetAdminUserId If provided, use this user as the new condominium admin. Otherwise match by email.
     * @return int New condominium ID
     */
    public function restore(string $backupPath, ?int $targetAdminUserId = null): int
    {
        if (!$this->db) {
            throw new \Exception("Database connection not available");
        }

        if (!file_exists($backupPath)) {
            throw new \Exception("Ficheiro de backup não encontrado");
        }

        $tempDir = $this->backupPath . '/restore_' . uniqid();
        mkdir($tempDir, 0755, true);

        try {
            $zip = new \ZipArchive();
            if (!$zip->open($backupPath) || !$zip->extractTo($tempDir)) {
                throw new \Exception("Não foi possível abrir o ficheiro de backup");
            }
            $zip->close();

            $dataPath = $tempDir . '/data.json';
            if (!file_exists($dataPath)) {
                $found = null;
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if ($file->getFilename() === 'data.json') {
                        $found = $file->getRealPath();
                        break;
                    }
                }
                $dataPath = $found ?: '';
            }
            if (!file_exists($dataPath)) {
                throw new \Exception("Backup inválido: data.json não encontrado");
            }

            $data = json_decode(file_get_contents($dataPath), true);
            if (!$data) {
                throw new \Exception("Backup corrompido");
            }

            $condo = $data['tables']['condominiums'][0] ?? null;
            if (!$condo) {
                throw new \Exception("Backup inválido: condomínio não encontrado");
            }

            $oldCondominiumId = (int)$condo['id'];
            $restoreInPlace = $this->condominiumExists($oldCondominiumId);
            if ($restoreInPlace) {
                $deletionService = new CondominiumDeletionService();
                $deletionService->deleteCondominiumData($oldCondominiumId, false);
            }

            $this->db->beginTransaction();

            try {
                $map = ['users' => [], 'condominiums' => [], 'fractions' => [], 'bank_accounts' => [], 'budgets' => [],
                    'budget_items' => [], 'suppliers' => [], 'contracts' => [], 'fees' => [], 'fraction_accounts' => [],
                    'assemblies' => [], 'assembly_vote_topics' => [], 'spaces' => [], 'folders' => [], 'documents' => [],
                    'financial_transactions' => [], 'fee_payments' => [], 'standalone_votes' => [], 'occurrences' => [],
                    'messages' => [], 'assembly_agenda_points' => []];

                // 1. Users: match by email or create minimal
                $usersSnapshot = $data['tables']['users_snapshot'] ?? [];
                foreach ($usersSnapshot as $u) {
                    $existing = $this->findUserByEmail($u['email']);
                    if ($existing) {
                        $map['users'][$u['id']] = $existing['id'];
                    } else {
                        $newId = $this->createMinimalUser($u);
                        $map['users'][$u['id']] = $newId;
                    }
                }

                // 2. Condominium (same ID when restoring in place)
                $adminUserId = $targetAdminUserId ?? ($map['users'][$condo['user_id']] ?? null);
                if (!$adminUserId) {
                    throw new \Exception("Utilizador administrador não encontrado. Certifique-se que o email existe ou indique um admin.");
                }

                $forceId = $restoreInPlace ? $oldCondominiumId : null;
                $newCondominiumId = $this->insertCondominium($condo, $adminUserId, $forceId);
                $map['condominiums'][$condo['id']] = $newCondominiumId;
                $map['admin_user_id'] = $adminUserId;

                // 3. Fractions
                foreach ($data['tables']['fractions'] ?? [] as $row) {
                    $newId = $this->insertFraction($row, $newCondominiumId);
                    $map['fractions'][$row['id']] = $newId;
                }

                // 4. Spaces
                foreach ($data['tables']['spaces'] ?? [] as $row) {
                    $newId = $this->insertRow("spaces", $row, ['condominium_id' => $newCondominiumId]);
                    $map['spaces'][$row['id']] = $newId;
                }

                // 5. Bank accounts
                foreach ($data['tables']['bank_accounts'] ?? [] as $row) {
                    $newId = $this->insertRow("bank_accounts", $row, ['condominium_id' => $newCondominiumId]);
                    $map['bank_accounts'][$row['id']] = $newId;
                }

                // 6. Folders (insert in parent-first order)
                $folders = $data['tables']['folders'] ?? [];
                usort($folders, function ($a, $b) {
                    $depthA = substr_count($a['path'] ?? '', '/');
                    $depthB = substr_count($b['path'] ?? '', '/');
                    return $depthA <=> $depthB;
                });
                foreach ($folders as $row) {
                    $newId = $this->insertFolder($row, $newCondominiumId, $map);
                    $map['folders'][$row['id']] = $newId;
                }

                // 7. Budgets & budget_items
                foreach ($data['tables']['budgets'] ?? [] as $row) {
                    $newId = $this->insertRow("budgets", $row, ['condominium_id' => $newCondominiumId]);
                    $map['budgets'][$row['id']] = $newId;
                }
                foreach ($data['tables']['budget_items'] ?? [] as $row) {
                    $newBudgetId = $map['budgets'][$row['budget_id']] ?? null;
                    if ($newBudgetId) {
                        $this->insertRow("budget_items", $row, ['budget_id' => $newBudgetId]);
                    }
                }

                // 8. Suppliers, contracts
                foreach ($data['tables']['suppliers'] ?? [] as $row) {
                    $newId = $this->insertRow("suppliers", $row, ['condominium_id' => $newCondominiumId]);
                    $map['suppliers'][$row['id']] = $newId;
                }
                foreach ($data['tables']['contracts'] ?? [] as $row) {
                    $supplierId = $map['suppliers'][$row['supplier_id']] ?? $row['supplier_id'];
                    $this->insertRow("contracts", $row, ['condominium_id' => $newCondominiumId, 'supplier_id' => $supplierId]);
                }

                // 9. Fees
                foreach ($data['tables']['fees'] ?? [] as $row) {
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($fracId) {
                        $newId = $this->insertRow("fees", $row, ['condominium_id' => $newCondominiumId, 'fraction_id' => $fracId]);
                        $map['fees'][$row['id']] = $newId;
                    }
                }

                // 10. Fraction accounts
                foreach ($data['tables']['fraction_accounts'] ?? [] as $row) {
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($fracId) {
                        $newId = $this->insertRow("fraction_accounts", $row, ['condominium_id' => $newCondominiumId, 'fraction_id' => $fracId]);
                        $map['fraction_accounts'][$row['id']] = $newId;
                    }
                }

                // 11. Condominium users (associate users and admins)
                foreach ($data['tables']['condominium_users'] ?? [] as $row) {
                    $userId = $map['users'][$row['user_id']] ?? null;
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    if ($userId) {
                        $this->insertCondominiumUser($row, $newCondominiumId, $userId, $fracId);
                    }
                }

                // 12. Assemblies
                foreach ($data['tables']['assemblies'] ?? [] as $row) {
                    $newId = $this->insertRow("assemblies", $row, ['condominium_id' => $newCondominiumId]);
                    $map['assemblies'][$row['id']] = $newId;
                }

                // 13. Assembly vote topics
                foreach ($data['tables']['assembly_vote_topics'] ?? [] as $row) {
                    $asmId = $map['assemblies'][$row['assembly_id']] ?? null;
                    if ($asmId) {
                        $newId = $this->insertRow("assembly_vote_topics", $row, ['assembly_id' => $asmId]);
                        $map['assembly_vote_topics'][$row['id']] = $newId;
                    }
                }

                // 14. Assembly agenda points
                foreach ($data['tables']['assembly_agenda_points'] ?? [] as $row) {
                    $asmId = $map['assemblies'][$row['assembly_id']] ?? null;
                    $topicId = !empty($row['vote_topic_id']) ? ($map['assembly_vote_topics'][$row['vote_topic_id']] ?? null) : null;
                    if ($asmId) {
                        $newId = $this->insertRow("assembly_agenda_points", $row, ['assembly_id' => $asmId, 'vote_topic_id' => $topicId]);
                        $map['assembly_agenda_points'][$row['id']] = $newId;
                    }
                }

                // assembly_agenda_point_vote_topics (uses agenda_point_id, vote_topic_id)
                foreach ($data['tables']['assembly_agenda_point_vote_topics'] ?? [] as $row) {
                    $agendaId = $map['assembly_agenda_points'][$row['agenda_point_id'] ?? $row['assembly_agenda_point_id'] ?? 0] ?? null;
                    $topicId = $map['assembly_vote_topics'][$row['vote_topic_id'] ?? $row['assembly_vote_topic_id'] ?? 0] ?? null;
                    if ($agendaId && $topicId) {
                        $this->insertRow("assembly_agenda_point_vote_topics", $row, [
                            'agenda_point_id' => $agendaId,
                            'vote_topic_id' => $topicId
                        ]);
                    }
                }

                // 15. Assembly attendees, votes
                foreach ($data['tables']['assembly_attendees'] ?? [] as $row) {
                    $asmId = $map['assemblies'][$row['assembly_id']] ?? null;
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($asmId && $fracId) {
                        $this->insertRow("assembly_attendees", $row, ['assembly_id' => $asmId, 'fraction_id' => $fracId]);
                    }
                }
                foreach ($data['tables']['assembly_votes'] ?? [] as $row) {
                    $topicId = $map['assembly_vote_topics'][$row['topic_id']] ?? null;
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($topicId && $fracId) {
                        $this->insertRow("assembly_votes", $row, ['topic_id' => $topicId, 'fraction_id' => $fracId]);
                    }
                }

                // 16. Standalone votes, responses
                foreach ($data['tables']['standalone_votes'] ?? [] as $row) {
                    $newId = $this->insertRow("standalone_votes", $row, ['condominium_id' => $newCondominiumId]);
                    $map['standalone_votes'][$row['id']] = $newId;
                }
                foreach ($data['tables']['vote_options'] ?? [] as $row) {
                    $this->insertRow("vote_options", $row, ['condominium_id' => $newCondominiumId]);
                }
                foreach ($data['tables']['standalone_vote_responses'] ?? [] as $row) {
                    $voteId = $map['standalone_votes'][$row['standalone_vote_id']] ?? null;
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($voteId && $fracId) {
                        $this->insertRow("standalone_vote_responses", $row, ['standalone_vote_id' => $voteId, 'fraction_id' => $fracId]);
                    }
                }

                // 17. Financial transactions
                foreach ($data['tables']['financial_transactions'] ?? [] as $row) {
                    $bankId = $map['bank_accounts'][$row['bank_account_id']] ?? null;
                    $createdBy = !empty($row['created_by']) ? ($map['users'][$row['created_by']] ?? null) : null;
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    if ($bankId) {
                        $newId = $this->insertRow("financial_transactions", $row, [
                            'condominium_id' => $newCondominiumId,
                            'bank_account_id' => $bankId,
                            'fraction_id' => $fracId,
                            'created_by' => $createdBy
                        ]);
                        $map['financial_transactions'][$row['id']] = $newId;
                    }
                }

                // 18. Fee payments (with financial_transaction mapping)
                foreach ($data['tables']['fee_payments'] ?? [] as $row) {
                    $feeId = $map['fees'][$row['fee_id']] ?? null;
                    $ftId = !empty($row['financial_transaction_id']) ? ($map['financial_transactions'][$row['financial_transaction_id']] ?? null) : null;
                    $createdBy = !empty($row['created_by']) ? ($map['users'][$row['created_by']] ?? null) : null;
                    if ($feeId) {
                        $newId = $this->insertRow("fee_payments", $row, ['fee_id' => $feeId, 'financial_transaction_id' => $ftId, 'created_by' => $createdBy]);
                        $map['fee_payments'][$row['id']] = $newId;
                    }
                }
                foreach ($data['tables']['fee_payment_history'] ?? [] as $row) {
                    $feeId = $map['fees'][$row['fee_id']] ?? null;
                    if ($feeId) {
                        $this->insertRow("fee_payment_history", $row, ['fee_id' => $feeId]);
                    }
                }

                // 19. Fraction account movements
                foreach ($data['tables']['fraction_account_movements'] ?? [] as $row) {
                    $faId = $map['fraction_accounts'][$row['fraction_account_id']] ?? null;
                    $ftId = !empty($row['source_financial_transaction_id']) ? ($map['financial_transactions'][$row['source_financial_transaction_id']] ?? null) : null;
                    if ($faId) {
                        $this->insertRow("fraction_account_movements", $row, [
                            'fraction_account_id' => $faId,
                            'source_financial_transaction_id' => $ftId
                        ]);
                    }
                }

                // 20. Revenues, expenses, receipts
                foreach ($data['tables']['revenues'] ?? [] as $row) {
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    $this->insertRow("revenues", $row, ['condominium_id' => $newCondominiumId, 'fraction_id' => $fracId]);
                }
                foreach ($data['tables']['expenses'] ?? [] as $row) {
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    $supplierId = !empty($row['supplier_id']) ? ($map['suppliers'][$row['supplier_id']] ?? null) : null;
                    $this->insertRow("expenses", $row, ['condominium_id' => $newCondominiumId, 'fraction_id' => $fracId, 'supplier_id' => $supplierId]);
                }
                // Receipts: when restore in place keep original receipt_number; when new condominium generate new numbers (UNIQUE is global)
                $receiptsRows = $data['tables']['receipts'] ?? [];
                usort($receiptsRows, function ($a, $b) {
                    $t = (strtotime($a['generated_at'] ?? '') ?: 0) <=> (strtotime($b['generated_at'] ?? '') ?: 0);
                    return $t !== 0 ? $t : ((int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0));
                });
                $receiptSeqByYear = [];
                foreach ($receiptsRows as $row) {
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    $feeId = $map['fees'][$row['fee_id']] ?? null;
                    $feePaymentId = !empty($row['fee_payment_id']) ? ($map['fee_payments'][$row['fee_payment_id']] ?? null) : null;
                    $filePath = $this->remapDocumentFilePath($row['file_path'] ?? '', $oldCondominiumId, $newCondominiumId);
                    $generatedBy = !empty($row['generated_by']) ? ($map['users'][$row['generated_by']] ?? null) : null;
                    if ($fracId && $feeId) {
                        $overrides = [
                            'condominium_id' => $newCondominiumId,
                            'fraction_id' => $fracId,
                            'fee_id' => $feeId,
                            'fee_payment_id' => $feePaymentId,
                            'file_path' => $filePath,
                            'generated_by' => $generatedBy,
                        ];
                        if (!$restoreInPlace) {
                            $year = date('Y', strtotime($row['generated_at'] ?? 'now') ?: time());
                            $receiptSeqByYear[$year] = ($receiptSeqByYear[$year] ?? 0) + 1;
                            $overrides['receipt_number'] = sprintf('REC-%d-%s-%03d', $newCondominiumId, $year, $receiptSeqByYear[$year]);
                        }
                        $this->insertRow("receipts", $row, $overrides);
                    }
                }

                // 21. Reservations
                foreach ($data['tables']['reservations'] ?? [] as $row) {
                    $spaceId = $map['spaces'][$row['space_id']] ?? null;
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($spaceId && $fracId) {
                        $this->insertRow("reservations", $row, ['condominium_id' => $newCondominiumId, 'space_id' => $spaceId, 'fraction_id' => $fracId]);
                    }
                }

                // 22. Documents (folder is varchar path; insert parents before children)
                $documents = $data['tables']['documents'] ?? [];
                usort($documents, function ($a, $b) {
                    $pa = $a['parent_document_id'] ?? 0;
                    $pb = $b['parent_document_id'] ?? 0;
                    if (!$pa) return -1;
                    if (!$pb) return 1;
                    return $pa <=> $pb;
                });
                foreach ($documents as $row) {
                    $asmId = !empty($row['assembly_id']) ? ($map['assemblies'][$row['assembly_id']] ?? null) : null;
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    $uploadedBy = !empty($row['uploaded_by']) ? ($map['users'][$row['uploaded_by']] ?? null) : null;
                    $parentDocId = !empty($row['parent_document_id']) ? ($map['documents'][$row['parent_document_id']] ?? null) : null;
                    $filePath = $this->remapDocumentFilePath($row['file_path'] ?? '', $oldCondominiumId, $newCondominiumId);
                    $newId = $this->insertRow("documents", $row, [
                        'condominium_id' => $newCondominiumId,
                        'assembly_id' => $asmId,
                        'fraction_id' => $fracId,
                        'uploaded_by' => $uploadedBy,
                        'parent_document_id' => $parentDocId,
                        'file_path' => $filePath
                    ]);
                    $map['documents'][$row['id']] = $newId;
                }

                // 23. Minutes revisions
                foreach ($data['tables']['minutes_revisions'] ?? [] as $row) {
                    $asmId = $map['assemblies'][$row['assembly_id']] ?? null;
                    $docId = $map['documents'][$row['document_id']] ?? null;
                    $fracId = $map['fractions'][$row['fraction_id']] ?? null;
                    if ($asmId && $docId && $fracId) {
                        $this->insertRow("minutes_revisions", $row, ['assembly_id' => $asmId, 'document_id' => $docId, 'fraction_id' => $fracId]);
                    }
                }

                // 24. Messages, attachments
                foreach ($data['tables']['messages'] ?? [] as $row) {
                    $newId = $this->insertRow("messages", $row, ['condominium_id' => $newCondominiumId]);
                    $map['messages'][$row['id']] = $newId;
                }
                foreach ($data['tables']['message_attachments'] ?? [] as $row) {
                    $msgId = $map['messages'][$row['message_id']] ?? null;
                    if ($msgId) {
                        $filePath = $this->remapDocumentFilePath($row['file_path'] ?? '', $oldCondominiumId, $newCondominiumId);
                        $this->insertRow("message_attachments", $row, ['condominium_id' => $newCondominiumId, 'message_id' => $msgId, 'file_path' => $filePath]);
                    }
                }

                // 25. Occurrences
                foreach ($data['tables']['occurrences'] ?? [] as $row) {
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    $newId = $this->insertRow("occurrences", $row, ['condominium_id' => $newCondominiumId, 'fraction_id' => $fracId]);
                    $map['occurrences'][$row['id']] = $newId;
                }
                foreach ($data['tables']['occurrence_comments'] ?? [] as $row) {
                    $occId = $map['occurrences'][$row['occurrence_id']] ?? null;
                    if ($occId) {
                        $this->insertRow("occurrence_comments", $row, ['occurrence_id' => $occId]);
                    }
                }
                foreach ($data['tables']['occurrence_history'] ?? [] as $row) {
                    $occId = $map['occurrences'][$row['occurrence_id']] ?? null;
                    if ($occId) {
                        $this->insertRow("occurrence_history", $row, ['occurrence_id' => $occId]);
                    }
                }
                foreach ($data['tables']['occurrence_attachments'] ?? [] as $row) {
                    $occId = $map['occurrences'][$row['occurrence_id']] ?? null;
                    if ($occId) {
                        $filePath = $this->remapDocumentFilePath($row['file_path'] ?? '', $oldCondominiumId, $newCondominiumId);
                        $this->insertRow("occurrence_attachments", $row, ['condominium_id' => $newCondominiumId, 'occurrence_id' => $occId, 'file_path' => $filePath]);
                    }
                }

                // 26. Notifications, invitations, assembly_account_approvals, admin_transfer_pending
                foreach ($data['tables']['notifications'] ?? [] as $row) {
                    $userId = !empty($row['user_id']) ? ($map['users'][$row['user_id']] ?? null) : null;
                    $this->insertRow("notifications", $row, ['condominium_id' => $newCondominiumId, 'user_id' => $userId]);
                }
                foreach ($data['tables']['invitations'] ?? [] as $row) {
                    $fracId = !empty($row['fraction_id']) ? ($map['fractions'][$row['fraction_id']] ?? null) : null;
                    $this->insertRow("invitations", $row, ['condominium_id' => $newCondominiumId, 'fraction_id' => $fracId]);
                }
                foreach ($data['tables']['assembly_account_approvals'] ?? [] as $row) {
                    $this->insertRow("assembly_account_approvals", $row, ['condominium_id' => $newCondominiumId]);
                }
                foreach ($data['tables']['admin_transfer_pending'] ?? [] as $row) {
                    $userId = $map['users'][$row['user_id']] ?? null;
                    if ($userId) {
                        $this->insertRow("admin_transfer_pending", $row, ['condominium_id' => $newCondominiumId, 'user_id' => $userId]);
                    }
                }

                // 27. Copy storage files
                $filesDir = $tempDir . '/files';
                if (is_dir($filesDir)) {
                    $targetDir = $this->basePath . '/condominiums/' . $newCondominiumId;
                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0755, true);
                    }
                    $this->copyDirectory($filesDir, $targetDir);
                }

                $docStorageDir = $tempDir . '/documents_storage';
                if (is_dir($docStorageDir)) {
                    $this->copyDirectory($docStorageDir, $this->basePath . '/documents');
                }

                $this->db->commit();
                return $newCondominiumId;
            } catch (\Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
        } finally {
            $this->deleteDirectory($tempDir);
        }
    }

    /**
     * List available backup files (all)
     * @return array [ path, name, size_kb, modified ]
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }
        $files = [];
        foreach (glob($this->backupPath . '/*.backup') as $path) {
            $files[] = [
                'path' => $path,
                'name' => basename($path),
                'size_kb' => (int)ceil(filesize($path) / 1024),
                'modified' => filemtime($path)
            ];
        }
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
        return $files;
    }

    /**
     * List backup files for a specific condominium (filename must start with backup_{id}_)
     * @param int $condominiumId Condominium ID
     * @return array [ path, name, size_kb, modified ]
     */
    public function listBackupsForCondominium(int $condominiumId): array
    {
        if (!is_dir($this->backupPath)) {
            return [];
        }
        $prefix = 'backup_' . $condominiumId . '_';
        $files = [];
        foreach (glob($this->backupPath . '/*.backup') as $path) {
            if (strpos(basename($path), $prefix) === 0) {
                $files[] = [
                    'path' => $path,
                    'name' => basename($path),
                    'size_kb' => (int)ceil(filesize($path) / 1024),
                    'modified' => filemtime($path)
                ];
            }
        }
        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);
        return $files;
    }

    /**
     * Delete a backup file. Path must be inside the backups directory.
     * @param string $path Full path to the .backup file
     * @throws \Exception If path is invalid or outside backups dir
     */
    public function deleteBackup(string $path): void
    {
        $realPath = realpath($path);
        $realBackupPath = realpath($this->backupPath);
        if ($realPath === false || $realBackupPath === false) {
            throw new \Exception("Caminho inválido");
        }
        if (strpos($realPath, $realBackupPath) !== 0) {
            throw new \Exception("Backup não encontrado");
        }
        if (!is_file($realPath) || pathinfo($realPath, PATHINFO_EXTENSION) !== 'backup') {
            throw new \Exception("Ficheiro inválido");
        }
        if (!@unlink($realPath)) {
            throw new \Exception("Não foi possível apagar o backup");
        }
    }

    // --- Private helpers ---

    private function getRow(string $table, int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function exportUsersForCondominium(int $condominiumId): array
    {
        $condo = $this->getRow("condominiums", $condominiumId);
        $userIds = [];
        if ($condo) {
            $userIds[$condo['user_id']] = true;
        }
        $stmt = $this->db->prepare("SELECT user_id FROM condominium_users WHERE condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
            $userIds[$uid] = true;
        }
        if (empty($userIds)) {
            return [];
        }
        $ids = array_keys($userIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, name, email, role FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportTable(string $table, string $col, $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$table} WHERE {$col} = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportBudgetItems(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT bi.* FROM budget_items bi INNER JOIN budgets b ON b.id = bi.budget_id WHERE b.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportFractionAccounts(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM fraction_accounts WHERE condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportAssemblyAgendaPoints(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT aap.* FROM assembly_agenda_points aap INNER JOIN assemblies a ON a.id = aap.assembly_id WHERE a.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportAssemblyVoteTopics(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT avt.* FROM assembly_vote_topics avt INNER JOIN assemblies a ON a.id = avt.assembly_id WHERE a.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportAssemblyAttendees(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT aa.* FROM assembly_attendees aa INNER JOIN assemblies a ON a.id = aa.assembly_id WHERE a.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportAssemblyVotes(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT av.* FROM assembly_votes av INNER JOIN assembly_vote_topics avt ON avt.id = av.topic_id INNER JOIN assemblies a ON a.id = avt.assembly_id WHERE a.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportStandaloneVoteResponses(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT svr.* FROM standalone_vote_responses svr INNER JOIN standalone_votes sv ON sv.id = svr.standalone_vote_id WHERE sv.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportMinutesRevisions(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT mr.* FROM minutes_revisions mr INNER JOIN assemblies a ON a.id = mr.assembly_id WHERE a.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportFeePayments(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT fp.* FROM fee_payments fp INNER JOIN fees f ON f.id = fp.fee_id WHERE f.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportFeePaymentHistory(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT fph.* FROM fee_payment_history fph INNER JOIN fees f ON f.id = fph.fee_id WHERE f.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportFractionAccountMovements(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT fam.* FROM fraction_account_movements fam INNER JOIN fraction_accounts fa ON fa.id = fam.fraction_account_id WHERE fa.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportOccurrenceComments(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT oc.* FROM occurrence_comments oc INNER JOIN occurrences o ON o.id = oc.occurrence_id WHERE o.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportOccurrenceHistory(int $condominiumId): array
    {
        $stmt = $this->db->prepare("SELECT oh.* FROM occurrence_history oh INNER JOIN occurrences o ON o.id = oh.occurrence_id WHERE o.condominium_id = :cid");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function exportAssemblyAgendaPointVoteTopics(int $condominiumId): array
    {
        $stmt = $this->db->prepare("
            SELECT aapvt.* FROM assembly_agenda_point_vote_topics aapvt
            INNER JOIN assembly_agenda_points aap ON aap.id = aapvt.agenda_point_id
            INNER JOIN assemblies a ON a.id = aap.assembly_id
            WHERE a.condominium_id = :cid
        ");
        $stmt->execute([':cid' => $condominiumId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function copyCondominiumDocuments(int $condominiumId, string $targetDir): void
    {
        $stmt = $this->db->prepare("SELECT id, file_path FROM documents WHERE condominium_id = :cid AND file_path IS NOT NULL AND file_path != ''");
        $stmt->execute([':cid' => $condominiumId]);
        $docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($docs)) {
            return;
        }
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }
        foreach ($docs as $doc) {
            $src = $this->basePath . '/' . $doc['file_path'];
            if (file_exists($src)) {
                $dest = $targetDir . '/' . basename($doc['file_path']);
                $destDir = dirname($dest);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                @copy($src, $dest);
            }
        }
    }

    private function findUserByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function createMinimalUser(array $u): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, password, role, created_at, updated_at)
            VALUES (:name, :email, :password, 'admin', NOW(), NOW())
        ");
        $stmt->execute([
            ':name' => $u['name'] ?? 'Utilizador Restaurado',
            ':email' => $u['email'],
            ':password' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)
        ]);
        return (int)$this->db->lastInsertId();
    }

    private function insertCondominium(array $row, int $userId, ?int $forceId = null): int
    {
        $exclude = ['id', 'user_id', 'subscription_id', 'created_at', 'updated_at'];
        $cols = [];
        $vals = [];
        $params = [':user_id' => $userId];
        if ($forceId !== null) {
            $cols[] = 'id';
            $vals[] = ':id';
            $params[':id'] = $forceId;
        }
        foreach ($row as $k => $v) {
            if (in_array($k, $exclude)) continue;
            if ($k === 'user_id') continue;
            $cols[] = "`$k`";
            $vals[] = ":$k";
            $params[":$k"] = $v;
        }
        $cols[] = 'user_id';
        $vals[] = ':user_id';
        $cols[] = 'created_at';
        $vals[] = 'NOW()';
        $cols[] = 'updated_at';
        $vals[] = 'NOW()';
        $sql = "INSERT INTO condominiums (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
        $this->db->prepare($sql)->execute($params);
        return $forceId !== null ? $forceId : (int)$this->db->lastInsertId();
    }

    private function condominiumExists(int $condominiumId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM condominiums WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $condominiumId]);
        return (bool)$stmt->fetch();
    }

    private function insertFraction(array $row, int $condominiumId): int
    {
        return $this->insertRow("fractions", $row, ['condominium_id' => $condominiumId]);
    }

    private function insertCondominiumUser(array $row, int $condominiumId, int $userId, ?int $fractionId): void
    {
        $exclude = ['id', 'condominium_id', 'user_id', 'fraction_id'];
        $cols = ['condominium_id', 'user_id', 'fraction_id'];
        $vals = [':condominium_id', ':user_id', ':fraction_id'];
        $params = [':condominium_id' => $condominiumId, ':user_id' => $userId, ':fraction_id' => $fractionId];
        foreach ($row as $k => $v) {
            if (in_array($k, $exclude)) continue;
            $cols[] = "`$k`";
            $vals[] = ":$k";
            $params[":$k"] = $v;
        }
        $this->db->prepare("INSERT INTO condominium_users (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")->execute($params);
    }

    private function insertRow(string $table, array $row, array $overrides): int
    {
        $exclude = ['id'];
        $all = array_merge($row, $overrides);
        $cols = [];
        $vals = [];
        $params = [];
        foreach ($all as $k => $v) {
            if (in_array($k, $exclude)) continue;
            $cols[] = "`$k`";
            $vals[] = ":$k";
            $params[":$k"] = $v;
        }
        $this->db->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")")->execute($params);
        return (int)$this->db->lastInsertId();
    }

    private function insertFolder(array $row, int $condominiumId, array &$map): int
    {
        $parentId = !empty($row['parent_folder_id']) ? ($map['folders'][$row['parent_folder_id']] ?? null) : null;
        $createdBy = !empty($row['created_by']) ? ($map['users'][$row['created_by']] ?? null) : null;
        if (!$createdBy) {
            $createdBy = $map['admin_user_id'] ?? $this->db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
        }
        return $this->insertRow("folders", $row, ['condominium_id' => $condominiumId, 'parent_folder_id' => $parentId, 'created_by' => $createdBy]);
    }

    private function remapDocumentFilePath(string $filePath, int $oldCondominiumId, int $newCondominiumId): string
    {
        if (empty($filePath)) {
            return $filePath;
        }
        $pattern = 'condominiums/' . $oldCondominiumId . '/';
        $replacement = 'condominiums/' . $newCondominiumId . '/';
        return str_replace($pattern, $replacement, $filePath);
    }

    private function copyDirectory(string $src, string $dest): void
    {
        if (!is_dir($src)) return;
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            $s = $src . '/' . $f;
            $d = $dest . '/' . $f;
            if (is_dir($s)) {
                $this->copyDirectory($s, $d);
            } else {
                @copy($s, $d);
            }
        }
    }

    private function createZip(string $sourceDir, string $zipPath): void
    {
        $zip = new \ZipArchive();
        if (!$zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \Exception("Não foi possível criar o ficheiro de backup");
        }
        $sourceDirReal = realpath($sourceDir);
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($files as $file) {
            $path = $file->getRealPath();
            $relative = substr($path, strlen($sourceDirReal) + 1);
            $relative = str_replace('\\', '/', $relative);
            $relative = ltrim($relative, '/');
            $zip->addFile($path, $relative);
        }
        $zip->close();
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!is_dir($dir)) return false;
        foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->deleteDirectory($p) : @unlink($p);
        }
        return @rmdir($dir);
    }
}
