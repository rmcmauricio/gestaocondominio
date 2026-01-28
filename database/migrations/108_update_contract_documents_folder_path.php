<?php

class UpdateContractDocumentsFolderPath
{
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function up(): void
    {
        // Update documents that are in contracts folder to include year/month subfolders
        // Find documents with folder = 'Contratos' and file_path containing contracts/{year}/{month}
        $stmt = $this->db->query("
            SELECT id, file_path, folder 
            FROM documents 
            WHERE folder = 'Contratos' 
            AND file_path LIKE '%/contracts/%'
        ");
        
        $documents = $stmt->fetchAll();
        
        foreach ($documents as $doc) {
            $filePath = $doc['file_path'];
            $filePathParts = explode('/', $filePath);
            
            // Find 'contracts' in path
            $contractsIndex = array_search('contracts', $filePathParts);
            if ($contractsIndex !== false && isset($filePathParts[$contractsIndex + 1]) && isset($filePathParts[$contractsIndex + 2])) {
                $year = $filePathParts[$contractsIndex + 1];
                $month = $filePathParts[$contractsIndex + 2];
                
                // Validate year/month format (4 digits for year, 2 digits for month)
                if (preg_match('/^\d{4}$/', $year) && preg_match('/^\d{2}$/', $month)) {
                    $newFolder = 'Contratos/' . $year . '/' . $month;
                    
                    // Update document folder
                    $updateStmt = $this->db->prepare("
                        UPDATE documents 
                        SET folder = :folder 
                        WHERE id = :id
                    ");
                    $updateStmt->execute([
                        ':folder' => $newFolder,
                        ':id' => $doc['id']
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Revert folder paths back to just 'Contratos'
        $this->db->exec("
            UPDATE documents 
            SET folder = 'Contratos' 
            WHERE folder LIKE 'Contratos/%'
        ");
    }
}
