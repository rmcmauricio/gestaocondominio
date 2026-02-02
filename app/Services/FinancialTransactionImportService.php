<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class FinancialTransactionImportService
{
    protected $db;
    
    // Padrões para detecção automática de colunas separadas
    protected $creditPatterns = ['crédito', 'credito', 'credit', 'entrada', 'receita', 'receipt', 'income', 'deposit', 'créditos', 'creditos', 'credits'];
    protected $debitPatterns = ['débito', 'debito', 'debit', 'saída', 'saida', 'despesa', 'expense', 'withdrawal', 'payment', 'débitos', 'debitos', 'debits'];

    public function __construct($db = null)
    {
        $this->db = $db ?? $GLOBALS['db'] ?? null;
    }

    /**
     * Load PhpSpreadsheet autoloader
     */
    protected function loadPhpSpreadsheet()
    {
        // Check for required PHP extensions
        if (!extension_loaded('zip')) {
            throw new \Exception('A extensão PHP "zip" não está instalada. Para ficheiros .xlsx, é necessário ativar a extensão zip no PHP. No XAMPP, edite php.ini e descomente a linha: extension=zip');
        }
        
        if (!class_exists('ZipArchive')) {
            throw new \Exception('A classe ZipArchive não está disponível. A extensão PHP "zip" precisa estar instalada e ativada. No XAMPP, edite php.ini e descomente: extension=zip');
        }
        
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            // Try composer autoload first
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
            
            // Check again after loading autoload
            if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                throw new \Exception('PhpSpreadsheet não está instalado ou não pode ser carregado. Execute: composer install');
            }
        }
    }

    /**
     * Read file and return data array
     */
    public function readFile(string $filePath, bool $hasHeader = true, string $originalFileName = null): array
    {
        // Use original filename for extension detection if provided, otherwise use file path
        $fileNameForExtension = $originalFileName ?? $filePath;
        $extension = strtolower(pathinfo($fileNameForExtension, PATHINFO_EXTENSION));
        
        // Check for zip extension before loading PhpSpreadsheet if it's an Excel file
        if (in_array($extension, ['xlsx', 'xls'])) {
            if (!extension_loaded('zip')) {
                throw new \Exception('A extensão PHP "zip" não está instalada. Para importar ficheiros Excel (.xlsx, .xls), é necessário ativar a extensão zip no PHP. No XAMPP: 1) Abra php.ini, 2) Procure por "extension=zip", 3) Remova o ponto e vírgula (;) no início da linha, 4) Reinicie o Apache.');
            }
        }
        
        $this->loadPhpSpreadsheet();
        
        // If extension is empty, try to detect from MIME type
        if (empty($extension) && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType) {
                if (strpos($mimeType, 'spreadsheetml') !== false || $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                    $extension = 'xlsx';
                } elseif (strpos($mimeType, 'msexcel') !== false || $mimeType === 'application/vnd.ms-excel') {
                    $extension = 'xls';
                } elseif (strpos($mimeType, 'csv') !== false || $mimeType === 'text/csv' || $mimeType === 'text/plain') {
                    $extension = 'csv';
                }
            }
        }
        
        // Determine reader based on extension
        switch ($extension) {
            case 'xlsx':
                $reader = new Xlsx();
                // Read only data values, not formulas - prevents calculation errors with structured references
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                break;
            case 'xls':
                $reader = new Xls();
                // Read only data values, not formulas - prevents calculation errors with structured references
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                break;
            case 'csv':
                $reader = new Csv();
                $reader->setInputEncoding('UTF-8');
                // Try to detect delimiter
                $firstLine = file_get_contents($filePath, false, null, 0, 1000);
                if (strpos($firstLine, ';') !== false) {
                    $reader->setDelimiter(';'); // Semicolon (common in PT)
                } elseif (strpos($firstLine, ',') !== false) {
                    $reader->setDelimiter(','); // Comma
                } else {
                    $reader->setDelimiter(';'); // Default
                }
                $reader->setEnclosure('"');
                break;
            default:
                $detectedExtension = $extension ?: 'nenhuma';
                throw new \Exception('Formato de ficheiro não suportado (extensão detectada: ' . $detectedExtension . '). Use .xlsx, .xls ou .csv');
        }

        try {
            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new \Exception('Ficheiro não encontrado ou não acessível: ' . $filePath);
            }
            
            // Disable calculation completely before loading to prevent any formula evaluation
            // This is critical for files with structured references (Excel tables)
            if (class_exists('\PhpOffice\PhpSpreadsheet\Calculation\Calculation')) {
                try {
                    // Temporarily disable calculation engine globally
                    $globalCalc = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance();
                    $globalCalc->setCalculationCacheEnabled(false);
                    $globalCalc->disableCalculationCache();
                } catch (\Exception $calcEx) {
                    // Ignore if we can't disable calculation globally
                }
            }
            
            // Load spreadsheet - wrap in try-catch to catch calculation errors during load
            try {
                $spreadsheet = $reader->load($filePath);
            } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $loadCalcEx) {
                $errorMsg = $loadCalcEx->getMessage();
                if (strpos($errorMsg, 'Table for Structured Reference') !== false || 
                    strpos($errorMsg, 'structured reference') !== false ||
                    strpos($errorMsg, 'Structured Reference') !== false) {
                    throw new \Exception('O ficheiro Excel contém fórmulas com referências a tabelas estruturadas que não podem ser processadas. Solução: 1) Abra o ficheiro no Excel, 2) Selecione as células com fórmulas, 3) Copie (Ctrl+C), 4) Clique com botão direito e escolha "Colar Especial" > "Valores", 5) Guarde o ficheiro e tente importar novamente. Alternativamente, exporte o ficheiro para CSV e importe o CSV.');
                }
                throw $loadCalcEx;
            } catch (\Exception $loadEx) {
                // Check if it's a calculation error even if not the specific exception type
                $errorMsg = $loadEx->getMessage();
                $errorClass = get_class($loadEx);
                if (strpos($errorClass, 'Calculation') !== false || 
                    strpos($errorMsg, 'Table for Structured Reference') !== false ||
                    strpos($errorMsg, 'structured reference') !== false ||
                    strpos($errorMsg, 'Structured Reference') !== false) {
                    throw new \Exception('O ficheiro Excel contém fórmulas com referências a tabelas estruturadas que não podem ser processadas. Solução: 1) Abra o ficheiro no Excel, 2) Selecione as células com fórmulas, 3) Copie (Ctrl+C), 4) Clique com botão direito e escolha "Colar Especial" > "Valores", 5) Guarde o ficheiro e tente importar novamente. Alternativamente, exporte o ficheiro para CSV e importe o CSV.');
                }
                throw $loadEx;
            }
            
            $worksheet = $spreadsheet->getActiveSheet();
            
            // Ensure calculation is disabled for this spreadsheet instance
            try {
                $calculation = \PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($spreadsheet);
                $calculation->setCalculationCacheEnabled(false);
                $calculation->disableCalculationCache();
            } catch (\Exception $calcEx) {
                // Ignore if calculation instance cannot be accessed
            }
            
            // Get highest row and column - use data-only approach to avoid formula evaluation
            // Use iterateOnlyExistingCells to avoid triggering calculations
            $highestRow = $worksheet->getHighestDataRow();
            $highestColumn = $worksheet->getHighestDataColumn();
            
            if ($highestRow < 1) {
                throw new \Exception('Ficheiro vazio ou sem dados');
            }
            
            // Get data as array - read only calculated values, don't evaluate formulas
            // false = don't calculate formulas (prevents errors with structured references)
            // true = formatted values, true = return as array, false = numeric indices
            $data = $worksheet->toArray(false, true, true, false);
            
            // Clean empty rows
            $data = array_filter($data, function($row) {
                if (!is_array($row)) return false;
                return !empty(array_filter($row, function($cell) {
                    return trim((string)$cell) !== '';
                }));
            });
            
            if (empty($data)) {
                throw new \Exception('Ficheiro vazio ou sem dados válidos');
            }

            // Re-index to ensure numeric indices starting from 0
            $data = array_values($data);

            // If has header, extract it
            $headers = [];
            if ($hasHeader && !empty($data)) {
                $firstRow = array_shift($data);
                // Ensure first row is array and has numeric indices
                if (is_array($firstRow)) {
                    $headers = array_values(array_map(function($cell) {
                        return trim((string)$cell);
                    }, $firstRow));
                } else {
                    $headers = [trim((string)$firstRow)];
                }
            }

            // Re-index data again after removing header
            $data = array_values($data);

            return [
                'headers' => $headers,
                'rows' => $data,
                'columnCount' => count($headers) ?: (count($data[0] ?? [])),
                'rowCount' => count($data)
            ];
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            throw new \Exception('Erro ao ler ficheiro Excel/CSV: ' . $e->getMessage());
        } catch (\PhpOffice\PhpSpreadsheet\Calculation\Exception $e) {
            // Handle calculation errors (e.g., structured references in Excel tables)
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'Table for Structured Reference') !== false || 
                strpos($errorMsg, 'structured reference') !== false ||
                strpos($errorMsg, 'Structured Reference') !== false) {
                throw new \Exception('O ficheiro Excel contém fórmulas com referências a tabelas estruturadas que não podem ser processadas. Por favor, converta as fórmulas para valores (copiar e colar como valores) ou remova as referências a tabelas antes de importar.');
            }
            throw new \Exception('Erro ao calcular fórmulas no ficheiro Excel: ' . $errorMsg);
        } catch (\Exception $e) {
            // Check if it's a calculation-related error even if not caught above
            $errorMsg = $e->getMessage();
            $errorClass = get_class($e);
            
            if (strpos($errorClass, 'Calculation') !== false || 
                strpos($errorMsg, 'Table for Structured Reference') !== false ||
                strpos($errorMsg, 'structured reference') !== false ||
                strpos($errorMsg, 'Structured Reference') !== false) {
                throw new \Exception('O ficheiro Excel contém fórmulas com referências a tabelas estruturadas que não podem ser processadas. Por favor, converta as fórmulas para valores (copiar e colar como valores) ou remova as referências a tabelas antes de importar.');
            }
            
            throw new \Exception('Erro ao processar ficheiro: ' . $errorMsg . ' (Tipo: ' . $errorClass . ')');
        }
    }

    /**
     * Detect if file uses separate credit/debit columns
     */
    public function detectColumnMode(array $headers): string
    {
        $hasCredit = false;
        $hasDebit = false;
        $creditIndex = null;
        $debitIndex = null;

        foreach ($headers as $index => $header) {
            $headerLower = mb_strtolower(trim($header), 'UTF-8');
            
            foreach ($this->creditPatterns as $pattern) {
                if (strpos($headerLower, $pattern) !== false) {
                    $hasCredit = true;
                    $creditIndex = $index;
                    break;
                }
            }
            
            foreach ($this->debitPatterns as $pattern) {
                if (strpos($headerLower, $pattern) !== false) {
                    $hasDebit = true;
                    $debitIndex = $index;
                    break;
                }
            }
        }

        if ($hasCredit && $hasDebit) {
            return 'separate';
        }

        return 'single';
    }

    /**
     * Suggest column mapping based on headers with improved fuzzy matching
     */
    public function suggestMapping(array $headers, string $mode = 'single'): array
    {
        $mapping = [];
        $usedFields = [];
        $fieldScores = []; // Store scores for each field to find best matches
        $isSeparateMode = ($mode === 'separate');

        // Define field patterns with priorities (higher priority = better match)
        $fieldPatterns = [
            'transaction_date' => [
                // Prioritize "Data Valor" over "Data Operação"
                'patterns' => [
                    '/^data\s+valor$/i',  // Highest priority: "Data Valor"
                    '/^data\s+(operacao|operação|op|transacao|transação|movimento|mov)$/i',  // Lower priority: "Data Operação"
                    '/^data$/i',  // Generic "Data"
                    '/data|date|dia/i'  // Any date pattern
                ],
                'priority' => [12, 10, 8, 5]  // "Data Valor" has highest priority
            ],
            'description' => [
                'patterns' => ['/^descri(cao|ção|çao)$/i', '/descri|description|desc|observa|observ|nota|note|memo/i'],
                'priority' => [8, 5]
            ],
            'amount_credit' => [
                'patterns' => ['/^crédito$/i', '/crédito|credito|credit|entrada|receita|receipt|income|deposit/i'],
                'priority' => [10, 6]
            ],
            'amount_debit' => [
                'patterns' => ['/^débito$/i', '/débito|debito|debit|saída|saida|despesa|expense|withdrawal|payment/i'],
                'priority' => [10, 6]
            ],
            'amount' => [
                'patterns' => ['/^valor$/i', '/valor|amount|montante|importe|value|total/i'],
                'priority' => [8, 5]
            ],
            'category' => [
                'patterns' => ['/^categoria$/i', '/categoria|category|tipo|type|class/i'],
                'priority' => [8, 5]
            ],
            'reference' => [
                'patterns' => ['/^referência|referencia$/i', '/referência|referencia|reference|ref|num|número|numero/i'],
                'priority' => [8, 5]
            ],
            'is_transfer' => [
                'patterns' => ['/transferência|transferencia|transfer/i'],
                'priority' => [5]
            ]
        ];
        
        // Remove incompatible fields based on mode
        if ($isSeparateMode) {
            unset($fieldPatterns['amount']); // Don't suggest "amount" in separate mode
        } else {
            unset($fieldPatterns['amount_credit'], $fieldPatterns['amount_debit']); // Don't suggest credit/debit in single mode
        }

        // First pass: exact and high-priority matches
        // Special handling for transaction_date: collect all date columns first, then choose best one
        $dateColumns = [];
        
        foreach ($headers as $index => $header) {
            $headerLower = mb_strtolower(trim($header), 'UTF-8');
            $headerClean = preg_replace('/\s+/', ' ', $headerLower);
            
            // Check if this is a date column
            $isDateColumn = false;
            $datePriority = 0;
            foreach ($fieldPatterns['transaction_date']['patterns'] as $patternIndex => $pattern) {
                if (preg_match($pattern, $headerLower) || preg_match($pattern, $headerClean)) {
                    $isDateColumn = true;
                    $datePriority = $fieldPatterns['transaction_date']['priority'][$patternIndex] ?? 5;
                    break;
                }
            }
            
            if ($isDateColumn) {
                $dateColumns[] = [
                    'index' => $index,
                    'priority' => $datePriority,
                    'header' => $headerLower
                ];
            }
            
            // Process other fields normally
            foreach ($fieldPatterns as $field => $config) {
                // Skip transaction_date - handled separately above
                if ($field === 'transaction_date') {
                    continue;
                }
                
                // Skip if field already mapped
                if (in_array($field, $usedFields)) {
                    continue;
                }
                
                // Skip incompatible fields based on mode (already handled above, but double-check)
                if ($isSeparateMode && $field === 'amount') {
                    continue;
                }
                if (!$isSeparateMode && ($field === 'amount_credit' || $field === 'amount_debit')) {
                    continue;
                }
                
                foreach ($config['patterns'] as $patternIndex => $pattern) {
                    if (preg_match($pattern, $headerLower) || preg_match($pattern, $headerClean)) {
                        $priority = $config['priority'][$patternIndex] ?? 5;
                        
                        if (!isset($fieldScores[$field]) || $fieldScores[$field]['score'] < $priority) {
                            $fieldScores[$field] = [
                                'index' => $index,
                                'score' => $priority,
                                'header' => $headerLower
                            ];
                        }
                        break; // Use first matching pattern
                    }
                }
            }
        }
        
        // Choose best date column (highest priority, or first if same priority)
        if (!empty($dateColumns)) {
            // Sort by priority descending
            usort($dateColumns, function($a, $b) {
                return $b['priority'] - $a['priority'];
            });
            
            // Use the highest priority date column
            $bestDateColumn = $dateColumns[0];
            $fieldScores['transaction_date'] = [
                'index' => $bestDateColumn['index'],
                'score' => $bestDateColumn['priority'],
                'header' => $bestDateColumn['header']
            ];
        }

        // Second pass: assign mappings based on scores
        // Sort by score descending to assign best matches first
        uasort($fieldScores, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        foreach ($fieldScores as $field => $scoreData) {
            if (!in_array($field, $usedFields)) {
                $mapping[$scoreData['index']] = $field;
                $usedFields[] = $field;
            }
        }

        return $mapping;
    }

    /**
     * Check if header matches any pattern
     */
    protected function matchesPattern(string $header, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (strpos($header, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse and map data rows
     */
    public function parseRows(array $rows, array $columnMapping, string $mode = 'single'): array
    {
        $parsedData = [];

        foreach ($rows as $rowIndex => $row) {
            $rowData = [];
            $errors = [];

            // Ensure row is an array with numeric indices
            if (!is_array($row)) {
                $row = [$row];
            }
            
            // Convert to numeric indices (in case it has string keys)
            $rowArray = array_values($row);

            // Map columns
            // IMPORTANT: columnMapping structure is: [column_index => field_name]
            // e.g., ["0" => "transaction_date", "1" => "description"] or [0 => "transaction_date", 1 => "description"]
            // The column index corresponds to the position in the file (0-based)
            // CRITICAL: Use EXACTLY the column index that was mapped by the user - NO fallback or auto-detection
            foreach ($columnMapping as $fileColumnIndex => $systemField) {
                // Convert column index to integer - this is the EXACT column the user mapped
                // Handle both string and integer keys from JSON
                $colIndex = is_numeric($fileColumnIndex) ? (int)$fileColumnIndex : (int)$fileColumnIndex;
                
                // Validate column index is within bounds
                if ($colIndex < 0 || $colIndex >= count($rowArray)) {
                    if ($systemField === 'transaction_date') {
                        $errors[] = 'Índice de coluna inválido para Data: ' . $colIndex . ' (total de colunas: ' . count($rowArray) . ')';
                    }
                    continue;
                }
                
                // Get value from row array using the EXACT mapped column index
                // This is the ONLY place we read the value - we trust the user's mapping completely
                $value = $rowArray[$colIndex] ?? null;
                
                // Clean value
                if ($value !== null) {
                    if (is_string($value)) {
                        $value = trim($value);
                        if ($value === '') {
                            $value = null;
                        }
                    }
                }

                // Set the value for this field - ALWAYS use the mapped column, never override
                // For transaction_date, this is CRITICAL - we must use ONLY the column the user mapped
                if ($systemField === 'transaction_date') {
                    // For transaction_date, always set from mapped column, even if null
                    // This ensures we don't accidentally use a different date column
                    $rowData[$systemField] = $value;
                } else {
                    // For other fields, only set if value is not null
                    if ($value !== null || !isset($rowData[$systemField])) {
                        $rowData[$systemField] = $value;
                    }
                }
            }

            // Process transaction date - use ONLY the value from the mapped column
            // Do NOT look for other date columns or use fallbacks
            if (isset($rowData['transaction_date'])) {
                $mappedDateValue = $rowData['transaction_date'];
                $dateResult = $this->parseDate($mappedDateValue);
                if ($dateResult['success']) {
                    // Use the parsed date from the mapped column - this is the ONLY source
                    $rowData['transaction_date'] = $dateResult['date'];
                } else {
                    $errors[] = 'Data inválida na coluna mapeada: ' . ($mappedDateValue ?? 'vazio');
                    $rowData['transaction_date'] = null;
                }
            } else {
                // If transaction_date is not set, it means no column was mapped to it
                $errors[] = 'Data não mapeada - nenhuma coluna foi selecionada para Data';
            }

            // Process amount based on mode
            if ($mode === 'separate') {
                $amountResult = $this->processSeparateAmounts(
                    $rowData['amount_credit'] ?? null,
                    $rowData['amount_debit'] ?? null
                );
                $rowData['amount'] = $amountResult['amount'];
                $rowData['transaction_type'] = $amountResult['transaction_type'];
                if (!$amountResult['valid']) {
                    $errors[] = $amountResult['error'];
                }
            } else {
                $amountResult = $this->processSingleAmount($rowData['amount'] ?? null);
                $rowData['amount'] = $amountResult['amount'];
                $rowData['transaction_type'] = $amountResult['transaction_type'];
                if (!$amountResult['valid']) {
                    $errors[] = $amountResult['error'];
                }
            }

            $rowData['_row_index'] = $rowIndex + 1;
            $rowData['_errors'] = $errors;
            $rowData['_has_errors'] = !empty($errors);

            $parsedData[] = $rowData;
        }

        return $parsedData;
    }

    /**
     * Process single amount column
     */
    public function processSingleAmount($value): array
    {
        if ($value === null || $value === '') {
            return [
                'amount' => null,
                'transaction_type' => null,
                'valid' => false,
                'error' => 'Valor não pode estar vazio'
            ];
        }

        // Convert to float
        $amount = $this->parseAmount($value);
        
        if ($amount === null) {
            return [
                'amount' => null,
                'transaction_type' => null,
                'valid' => false,
                'error' => 'Valor inválido: ' . $value
            ];
        }

        if ($amount == 0) {
            return [
                'amount' => null,
                'transaction_type' => null,
                'valid' => false,
                'error' => 'Valor não pode ser zero'
            ];
        }

        // Determine type based on sign
        $transactionType = $amount > 0 ? 'income' : 'expense';
        $amount = abs($amount);

        return [
            'amount' => $amount,
            'transaction_type' => $transactionType,
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Process separate credit/debit columns
     */
    public function processSeparateAmounts($credit, $debit): array
    {
        $creditAmount = $credit !== null && $credit !== '' ? $this->parseAmount($credit) : null;
        $debitAmount = $debit !== null && $debit !== '' ? $this->parseAmount($debit) : null;

        // Both empty
        if ($creditAmount === null && $debitAmount === null) {
            return [
                'amount' => null,
                'transaction_type' => null,
                'valid' => false,
                'error' => 'Pelo menos uma das colunas (Crédito/Débito) deve ter valor'
            ];
        }

        // Both have values
        if ($creditAmount !== null && $debitAmount !== null) {
            return [
                'amount' => null,
                'transaction_type' => null,
                'valid' => false,
                'error' => 'Apenas uma das colunas (Crédito/Débito) pode ter valor'
            ];
        }

        // Credit has value
        if ($creditAmount !== null) {
            if ($creditAmount <= 0) {
                return [
                    'amount' => null,
                    'transaction_type' => null,
                    'valid' => false,
                    'error' => 'Valor de crédito deve ser positivo'
                ];
            }
            return [
                'amount' => $creditAmount,
                'transaction_type' => 'income',
                'valid' => true,
                'error' => null
            ];
        }

        // Debit has value
        if ($debitAmount <= 0) {
            return [
                'amount' => null,
                'transaction_type' => null,
                'valid' => false,
                'error' => 'Valor de débito deve ser positivo'
            ];
        }
        
        return [
            'amount' => $debitAmount,
            'transaction_type' => 'expense',
            'valid' => true,
            'error' => null
        ];
    }

    /**
     * Parse amount value
     */
    protected function parseAmount($value)
    {
        if (is_numeric($value)) {
            return (float)$value;
        }

        if (is_string($value)) {
            // Remove currency symbols and spaces
            $value = preg_replace('/[€$£¥\s]/u', '', trim($value));
            
            if (empty($value)) {
                return null;
            }
            
            // Handle Portuguese format: 1.234,56 or 1234,56
            // Check if last comma exists (decimal separator in PT)
            if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
                // Has both comma and dot - assume PT format: dot = thousands, comma = decimal
                $value = str_replace('.', '', $value); // Remove thousands separator
                $value = str_replace(',', '.', $value); // Convert comma to dot
            } elseif (strpos($value, ',') !== false) {
                // Only comma - could be decimal separator
                // Check if it's likely a decimal (has 2 digits after comma)
                $parts = explode(',', $value);
                if (count($parts) == 2 && strlen($parts[1]) <= 2) {
                    // Likely decimal separator
                    $value = str_replace(',', '.', $value);
                } else {
                    // Likely thousands separator, remove it
                    $value = str_replace(',', '', $value);
                }
            }
            
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    /**
     * Parse date value
     */
    protected function parseDate($value): array
    {
        if (empty($value)) {
            return ['success' => false, 'date' => null, 'error' => 'Data vazia'];
        }

        // If it's an Excel date serial number
        if (is_numeric($value) && $value > 25569) { // Excel epoch starts at 1900-01-01
            try {
                $date = Date::excelToDateTimeObject($value);
                // Excel dates are stored as days since 1900-01-01, so we need to extract just the date part
                // Create a new DateTime with just the date components to avoid timezone issues
                $year = (int)$date->format('Y');
                $month = (int)$date->format('m');
                $day = (int)$date->format('d');
                $cleanDate = new \DateTime();
                $cleanDate->setDate($year, $month, $day);
                $cleanDate->setTime(0, 0, 0);
                return [
                    'success' => true,
                    'date' => $cleanDate->format('Y-m-d'),
                    'error' => null
                ];
            } catch (\Exception $e) {
                // Continue to try other formats
            }
        }

        // Try common date formats
        $formats = [
            'Y-m-d',
            'd/m/Y',
            'm/d/Y',
            'd-m-Y',
            'd.m.Y',
            'Y/m/d',
            'd/m/y',
            'm/d/y'
        ];

        // If it's a string, try parsing
        if (is_string($value)) {
            // Clean the string
            $value = trim($value);
            
            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date !== false) {
                    // Ensure we're working with date only (no time component)
                    // Set time to noon to avoid timezone issues, then format as date
                    $date->setTime(12, 0, 0);
                    return [
                        'success' => true,
                        'date' => $date->format('Y-m-d'),
                        'error' => null
                    ];
                }
            }

            // Try strtotime as last resort
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                // Use DateTime to ensure consistent date formatting
                $date = new \DateTime();
                $date->setTimestamp($timestamp);
                $date->setTime(12, 0, 0); // Set to noon to avoid timezone shifts
                return [
                    'success' => true,
                    'date' => $date->format('Y-m-d'),
                    'error' => null
                ];
            }
        }

        return [
            'success' => false,
            'date' => null,
            'error' => 'Formato de data não reconhecido: ' . $value
        ];
    }

    /**
     * Validate parsed data
     */
    public function validateRow(array $rowData, array $accounts, int $condominiumId): array
    {
        $errors = [];

        // Required fields
        if (empty($rowData['transaction_date'])) {
            $errors[] = 'Data é obrigatória';
        }

        if (empty($rowData['amount']) || $rowData['amount'] <= 0) {
            $errors[] = 'Valor é obrigatório e deve ser maior que zero';
        }

        // Description is optional - if empty, set a default value
        if (empty($rowData['description'])) {
            $rowData['description'] = 'Movimento importado';
        }

        // Bank account is now set before validation, just verify it exists
        if (empty($rowData['bank_account_id'])) {
            $errors[] = 'Conta bancária não definida';
        } else {
            // Validate account exists and belongs to condominium
            $accountFound = false;
            $accountId = (int)$rowData['bank_account_id'];
            
            foreach ($accounts as $account) {
                if ($account['id'] == $accountId) {
                    $accountFound = true;
                    $rowData['bank_account_id'] = $account['id'];
                    break;
                }
            }
            if (!$accountFound) {
                $errors[] = 'Conta bancária não encontrada ou não pertence ao condomínio';
            }
        }

        // Validate transaction type
        if (empty($rowData['transaction_type']) || !in_array($rowData['transaction_type'], ['income', 'expense'])) {
            $errors[] = 'Tipo de transação inválido';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'rowData' => $rowData
        ];
    }

    /**
     * Find bank account by name or ID
     */
    public function findBankAccount($identifier, array $accounts): ?array
    {
        foreach ($accounts as $account) {
            if ($account['id'] == $identifier || 
                mb_strtolower(trim($account['name']), 'UTF-8') === mb_strtolower(trim($identifier), 'UTF-8')) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Check for duplicate transactions
     * Compares imported transactions with existing ones based on date and amount only
     * 
     * @param array $parsedData Array of parsed transaction data
     * @param int $condominiumId Condominium ID
     * @param int $bankAccountId Bank account ID
     * @return array Array with duplicate information for each row
     */
    public function checkDuplicates(array $parsedData, int $condominiumId, int $bankAccountId): array
    {
        if (!$this->db || empty($parsedData)) {
            return [];
        }

        $duplicates = [];
        
        // Get date range from parsed data
        $dates = array_filter(array_column($parsedData, 'transaction_date'));
        if (empty($dates)) {
            return [];
        }
        
        $minDate = min($dates);
        $maxDate = max($dates);
        
        // Expand date range by 1 day on each side for tolerance
        $minDateObj = new \DateTime($minDate);
        $minDateObj->modify('-1 day');
        $maxDateObj = new \DateTime($maxDate);
        $maxDateObj->modify('+1 day');
        
        // Fetch existing transactions in the date range for this account
        $stmt = $this->db->prepare("
            SELECT id, transaction_date, amount, transaction_type, description, category, reference
            FROM financial_transactions
            WHERE condominium_id = :condominium_id
            AND bank_account_id = :bank_account_id
            AND transaction_date >= :min_date
            AND transaction_date <= :max_date
            ORDER BY transaction_date DESC, created_at DESC
        ");
        
        $stmt->execute([
            ':condominium_id' => $condominiumId,
            ':bank_account_id' => $bankAccountId,
            ':min_date' => $minDateObj->format('Y-m-d'),
            ':max_date' => $maxDateObj->format('Y-m-d')
        ]);
        
        $existingTransactions = $stmt->fetchAll() ?: [];
        
        // Compare each parsed transaction with existing ones
        // Check: date, amount, and transaction type (income/expense)
        foreach ($parsedData as $index => $rowData) {
            if (empty($rowData['transaction_date']) || empty($rowData['amount']) || empty($rowData['transaction_type'])) {
                continue; // Skip invalid rows
            }
            
            $rowDate = $rowData['transaction_date'];
            $rowAmount = (float)$rowData['amount'];
            $rowType = $rowData['transaction_type'];
            
            foreach ($existingTransactions as $existing) {
                $existingDate = $existing['transaction_date'];
                $existingAmount = (float)$existing['amount'];
                $existingType = $existing['transaction_type'];
                
                // Check if dates match (exact match)
                if ($rowDate !== $existingDate) {
                    continue;
                }
                
                // Check if amounts match (with small tolerance for floating point - 0.01€)
                if (abs($rowAmount - $existingAmount) > 0.01) {
                    continue;
                }
                
                // Check if transaction types match (income/expense)
                if ($rowType !== $existingType) {
                    continue;
                }
                
                // Found a duplicate (same date, same amount, and same type)
                if (!isset($duplicates[$index])) {
                    $duplicates[$index] = [];
                }
                
                $duplicates[$index][] = [
                    'id' => $existing['id'],
                    'transaction_date' => $existing['transaction_date'],
                    'amount' => $existing['amount'],
                    'transaction_type' => $existing['transaction_type'],
                    'description' => $existing['description'],
                    'category' => $existing['category'],
                    'reference' => $existing['reference']
                ];
            }
        }
        
        return $duplicates;
    }
}
