<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class FractionImportService
{
    protected $db;

    public function __construct($db = null)
    {
        $this->db = $db ?? $GLOBALS['db'] ?? null;
    }

    /**
     * Load PhpSpreadsheet autoloader
     */
    protected function loadPhpSpreadsheet(): void
    {
        if (!extension_loaded('zip')) {
            throw new \Exception('A extensão PHP "zip" não está instalada. Para ficheiros .xlsx, é necessário ativar a extensão zip no PHP.');
        }
        if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
            if (file_exists($autoloadPath)) {
                require_once $autoloadPath;
            }
            if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                throw new \Exception('PhpSpreadsheet não está instalado. Execute: composer install');
            }
        }
    }

    /**
     * Read file and return headers + rows (same pattern as FinancialTransactionImportService)
     */
    public function readFile(string $filePath, bool $hasHeader = true, ?string $originalFileName = null): array
    {
        $fileNameForExtension = $originalFileName ?? $filePath;
        $extension = strtolower(pathinfo($fileNameForExtension, PATHINFO_EXTENSION));

        if (in_array($extension, ['xlsx', 'xls'])) {
            if (!extension_loaded('zip')) {
                throw new \Exception('A extensão PHP "zip" não está instalada. Para importar ficheiros Excel (.xlsx, .xls), ative a extensão zip no PHP.');
            }
        }

        $this->loadPhpSpreadsheet();

        if (empty($extension) && function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType) {
                if (strpos($mimeType, 'spreadsheetml') !== false) {
                    $extension = 'xlsx';
                } elseif (strpos($mimeType, 'msexcel') !== false) {
                    $extension = 'xls';
                } elseif (strpos($mimeType, 'csv') !== false || $mimeType === 'text/plain') {
                    $extension = 'csv';
                }
            }
        }

        switch ($extension) {
            case 'xlsx':
                $reader = new Xlsx();
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                break;
            case 'xls':
                $reader = new Xls();
                $reader->setReadDataOnly(true);
                $reader->setReadEmptyCells(false);
                break;
            case 'csv':
                $reader = new Csv();
                $reader->setInputEncoding('UTF-8');
                $firstLine = file_get_contents($filePath, false, null, 0, 1000);
                if (strpos($firstLine, ';') !== false) {
                    $reader->setDelimiter(';');
                } elseif (strpos($firstLine, ',') !== false) {
                    $reader->setDelimiter(',');
                } else {
                    $reader->setDelimiter(';');
                }
                $reader->setEnclosure('"');
                break;
            default:
                throw new \Exception('Formato não suportado. Use .xlsx, .xls ou .csv');
        }

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \Exception('Ficheiro não encontrado ou não acessível.');
        }

        try {
            $spreadsheet = $reader->load($filePath);
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Structured Reference') !== false || strpos($msg, 'structured reference') !== false) {
                throw new \Exception('O ficheiro Excel contém fórmulas com referências a tabelas. Converta as fórmulas para valores ou exporte para CSV.');
            }
            throw new \Exception('Erro ao processar ficheiro: ' . $msg);
        }

        $worksheet = $spreadsheet->getActiveSheet();
        $highestRow = $worksheet->getHighestDataRow();
        if ($highestRow < 1) {
            throw new \Exception('Ficheiro vazio ou sem dados.');
        }

        $data = $worksheet->toArray(false, true, true, false);
        $data = array_filter($data, function ($row) {
            if (!is_array($row)) {
                return false;
            }
            return !empty(array_filter($row, function ($cell) {
                return trim((string)$cell) !== '';
            }));
        });
        $data = array_values($data);

        $headers = [];
        if ($hasHeader && !empty($data)) {
            $firstRow = array_shift($data);
            $headers = array_values(array_map(function ($cell) {
                return trim((string)$cell);
            }, is_array($firstRow) ? $firstRow : [$firstRow]));
        }
        $data = array_values($data);

        return [
            'headers' => $headers,
            'rows' => $data,
            'columnCount' => count($headers) ?: (count($data[0] ?? [])),
            'rowCount' => count($data),
        ];
    }

    /**
     * Suggest column mapping: column index => field name (identifier, permillage, floor, typology, area, notes)
     */
    public function suggestMapping(array $headers): array
    {
        $mapping = [];
        $usedFields = [];
        $fieldScores = [];

        $fieldPatterns = [
            'identifier' => [
                'patterns' => ['/^identificador$/i', '/fracao|fração|fraccao|numero|número|numero|ref|referencia|referência/i'],
                'priority' => [10, 6],
            ],
            'permillage' => [
                'patterns' => ['/^permilagem$/i', '/permillage|permilagem|‰|permil/i'],
                'priority' => [10, 6],
            ],
            'floor' => [
                'patterns' => ['/^piso$/i', '/floor|andar|piso/i'],
                'priority' => [10, 6],
            ],
            'typology' => [
                'patterns' => ['/^tipologia$/i', '/typology|tipo|tipologia/i'],
                'priority' => [10, 6],
            ],
            'area' => [
                'patterns' => ['/^área$/i', '/area|área|m2|m²|metros/i'],
                'priority' => [10, 6],
            ],
            'notes' => [
                'patterns' => ['/^notas$/i', '/notes|notas|observa|observações|obs/i'],
                'priority' => [10, 6],
            ],
            'owner_name' => [
                'patterns' => ['/^nome$/i', '/nome|name|condómino|condomino/i'],
                'priority' => [10, 6],
            ],
            'owner_email' => [
                'patterns' => ['/^email$/i', '/email|e-mail/i'],
                'priority' => [10, 6],
            ],
            'owner_nif' => [
                'patterns' => ['/^nif$/i', '/nif|contribuinte/i'],
                'priority' => [10, 6],
            ],
            'owner_phone' => [
                'patterns' => ['/^telefone$/i', '/telefone|phone|tel|telemóvel|telemovel/i'],
                'priority' => [10, 6],
            ],
            'owner_alternative_address' => [
                'patterns' => ['/^morada\s+alternativa$/i', '/morada alternativa|morada|alternative_address|endereço|endereco/i'],
                'priority' => [10, 6],
            ],
            'owner_role' => [
                'patterns' => ['/^tipo$/i', '/role|proprietario|arrendatario|condomino/i'],
                'priority' => [10, 6],
            ],
        ];

        foreach ($headers as $index => $header) {
            $headerLower = mb_strtolower(trim($header), 'UTF-8');
            $headerClean = preg_replace('/\s+/', ' ', $headerLower);

            foreach ($fieldPatterns as $field => $config) {
                if (in_array($field, $usedFields)) {
                    continue;
                }
                foreach ($config['patterns'] as $patternIndex => $pattern) {
                    if (preg_match($pattern, $headerLower) || preg_match($pattern, $headerClean)) {
                        $priority = $config['priority'][$patternIndex] ?? 5;
                        $fieldScores[$field] = [
                            'index' => $index,
                            'score' => $priority,
                            'header' => $headerLower,
                        ];
                        $usedFields[] = $field;
                        break 2;
                    }
                }
            }
        }

        foreach ($fieldScores as $field => $scoreData) {
            $mapping[$scoreData['index']] = $field;
        }

        return $mapping;
    }

    /**
     * Parse rows using column mapping. columnMapping: [ columnIndex => fieldName ]
     */
    public function parseRows(array $rows, array $columnMapping): array
    {
        $parsedData = [];

        foreach ($rows as $rowIndex => $row) {
            if (!is_array($row)) {
                $row = [$row];
            }
            $rowArray = array_values($row);

            $rowData = [
                'identifier' => null,
                'permillage' => 0,
                'floor' => null,
                'typology' => null,
                'area' => null,
                'notes' => null,
                'owner_name' => null,
                'owner_email' => null,
                'owner_nif' => null,
                'owner_phone' => null,
                'owner_alternative_address' => null,
                'owner_role' => null,
            ];

            foreach ($columnMapping as $fileColumnIndex => $systemField) {
                $colIndex = is_numeric($fileColumnIndex) ? (int)$fileColumnIndex : (int)$fileColumnIndex;
                if ($colIndex < 0 || $colIndex >= count($rowArray)) {
                    continue;
                }

                $value = $rowArray[$colIndex] ?? null;
                if ($value !== null) {
                    if (is_string($value)) {
                        $value = trim($value);
                        if ($value === '') {
                            $value = null;
                        }
                    }
                }

                if (!array_key_exists($systemField, $rowData)) {
                    continue;
                }

                if ($systemField === 'identifier') {
                    $rowData[$systemField] = $value !== null ? $value : '';
                } elseif ($systemField === 'permillage' || $systemField === 'area') {
                    if ($value !== null && $value !== '') {
                        $rowData[$systemField] = is_numeric(str_replace(',', '.', $value))
                            ? (float)str_replace(',', '.', $value)
                            : 0;
                    }
                } elseif ($systemField === 'owner_role') {
                    if ($value !== null && $value !== '') {
                        $roleLower = mb_strtolower((string)$value, 'UTF-8');
                        if (in_array($roleLower, ['proprietario', 'arrendatario', 'condomino'], true)) {
                            $rowData[$systemField] = $roleLower;
                        } elseif (preg_match('/proprietário|proprietario/i', $roleLower)) {
                            $rowData[$systemField] = 'proprietario';
                        } elseif (preg_match('/arrendatário|arrendatario/i', $roleLower)) {
                            $rowData[$systemField] = 'arrendatario';
                        } else {
                            $rowData[$systemField] = 'condomino';
                        }
                    }
                } else {
                    $rowData[$systemField] = $value !== null && $value !== '' ? $value : null;
                }
            }

            $parsedData[] = $rowData;
        }

        return $parsedData;
    }

    /**
     * Validate a single row. Returns ['valid' => bool, 'errors' => string[]]
     * Optionally checks (condominium_id, identifier) uniqueness in DB.
     */
    public function validateRow(array $row, int $condominiumId, array $existingIdentifiers = []): array
    {
        $errors = [];

        $identifier = trim((string)($row['identifier'] ?? ''));
        if ($identifier === '') {
            $errors[] = 'Identificador é obrigatório.';
        }

        $permillage = $row['permillage'] ?? 0;
        if (!is_numeric($permillage) || (float)$permillage < 0) {
            $errors[] = 'Permilagem deve ser um número >= 0.';
        }

        $hasOwnerData = !empty(trim((string)($row['owner_name'] ?? ''))) || !empty(trim((string)($row['owner_email'] ?? '')))
            || !empty(trim((string)($row['owner_nif'] ?? ''))) || !empty(trim((string)($row['owner_phone'] ?? '')))
            || !empty(trim((string)($row['owner_alternative_address'] ?? ''))) || !empty(trim((string)($row['owner_role'] ?? '')));
        if ($hasOwnerData && empty(trim((string)($row['owner_name'] ?? '')))) {
            $errors[] = 'Nome do condómino é obrigatório quando há dados do condómino.';
        }
        $ownerEmail = trim((string)($row['owner_email'] ?? ''));
        if ($ownerEmail !== '' && !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email do condómino inválido.';
        }
        $ownerRole = trim((string)($row['owner_role'] ?? ''));
        if ($ownerRole !== '' && !in_array($ownerRole, ['condomino', 'proprietario', 'arrendatario'], true)) {
            $errors[] = 'Tipo do condómino deve ser condomino, proprietario ou arrendatario.';
        }

        if (!empty($existingIdentifiers) && in_array($identifier, $existingIdentifiers, true)) {
            $errors[] = 'Identificador duplicado no ficheiro: ' . $identifier;
        }

        if ($this->db && $identifier !== '') {
            $stmt = $this->db->prepare("
                SELECT id FROM fractions 
                WHERE condominium_id = :condominium_id AND identifier = :identifier AND is_active = 1 LIMIT 1
            ");
            $stmt->execute([
                ':condominium_id' => $condominiumId,
                ':identifier' => $identifier,
            ]);
            if ($stmt->fetch()) {
                $errors[] = 'Já existe uma fração com este identificador neste condomínio: ' . $identifier;
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get list of field names for mapping UI
     */
    public static function getMappingFields(): array
    {
        return [
            'identifier' => 'Identificador (obrigatório)',
            'permillage' => 'Permilagem',
            'floor' => 'Piso',
            'typology' => 'Tipologia',
            'area' => 'Área (m²)',
            'notes' => 'Notas',
            'owner_name' => 'Nome do condómino',
            'owner_email' => 'Email do condómino',
            'owner_nif' => 'NIF',
            'owner_phone' => 'Telefone',
            'owner_alternative_address' => 'Morada alternativa',
            'owner_role' => 'Tipo (condomino/proprietario/arrendatario)',
        ];
    }
}
