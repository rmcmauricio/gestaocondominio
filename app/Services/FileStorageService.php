<?php

namespace App\Services;

class FileStorageService
{
    protected $basePath;
    protected $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
        'image/gif',
        'text/plain'
    ];

    protected $maxFileSize = 10485760; // 10MB

    public function __construct()
    {
        $this->basePath = __DIR__ . '/../../storage';
        
        // Create base storage directory if it doesn't exist
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Detect MIME type using multiple methods with fallback
     * @param string $filePath Path to the file
     * @param array $fileInfo File info array from $_FILES
     * @return string MIME type
     */
    protected function detectMimeType(string $filePath, array $fileInfo = []): string
    {
        // Method 1: Try mime_content_type() if available
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType !== false) {
                return $mimeType;
            }
        }

        // Method 2: Try finfo_open() if available
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mimeType !== false) {
                    return $mimeType;
                }
            }
        }

        // Method 3: Use file extension mapping (less reliable but works as fallback)
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $extensionMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'txt' => 'text/plain',
        ];

        if (isset($extensionMap[$extension])) {
            return $extensionMap[$extension];
        }

        // Method 4: Use $_FILES['type'] as last resort (least reliable)
        if (isset($fileInfo['type']) && !empty($fileInfo['type'])) {
            return $fileInfo['type'];
        }

        // Default fallback
        return 'application/octet-stream';
    }

    /**
     * Upload file
     * @param array $file File array from $_FILES
     * @param int $condominiumId Condominium ID
     * @param string $type Type of file: 'messages', 'occurrences', 'receipts', 'documents'
     * @param string $subfolder Optional subfolder (e.g., 'inline', 'attachments')
     * @param int $maxSize Optional max file size in bytes (default: 10MB, 2MB for inline images)
     */
    public function upload(array $file, int $condominiumId, string $type = 'documents', string $subfolder = null, int $maxSize = null): array
    {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \Exception("Ficheiro inválido");
        }

        // Use specific max size for inline images, otherwise use default
        $fileMaxSize = $maxSize ?? ($subfolder === 'inline' ? 2097152 : $this->maxFileSize); // 2MB for inline, 10MB for others
        
        if ($file['size'] > $fileMaxSize) {
            throw new \Exception("Ficheiro muito grande. Máximo: " . ($fileMaxSize / 1048576) . "MB");
        }

        $mimeType = $this->detectMimeType($file['tmp_name'], $file);
        
        // For inline images, only allow image types
        if ($subfolder === 'inline') {
            $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mimeType, $allowedImageTypes)) {
                throw new \Exception("Apenas imagens são permitidas (JPEG, PNG, GIF, WebP)");
            }
        } elseif (!in_array($mimeType, $this->allowedMimeTypes)) {
            throw new \Exception("Tipo de ficheiro não permitido");
        }

        // Sanitize filename - remove dangerous characters and path traversal attempts
        $originalName = basename($file['name']); // Remove any path components
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName); // Only allow safe characters
        $originalName = preg_replace('/\.{2,}/', '.', $originalName); // Remove multiple dots
        $originalName = trim($originalName, '.'); // Remove leading/trailing dots
        
        // Get extension from sanitized name
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // Validate extension against MIME type to prevent extension spoofing
        $allowedExtensions = [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/gif' => ['gif'],
            'image/webp' => ['webp'],
            'application/pdf' => ['pdf'],
            'application/msword' => ['doc'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.ms-excel' => ['xls'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            'text/plain' => ['txt']
        ];
        
        if (isset($allowedExtensions[$mimeType])) {
            if (!in_array($extension, $allowedExtensions[$mimeType])) {
                throw new \Exception("Extensão do ficheiro não corresponde ao tipo MIME");
            }
        }
        
        // Verify file content matches MIME type (basic check)
        if (!$this->verifyFileContent($file['tmp_name'], $mimeType, $extension)) {
            throw new \Exception("Conteúdo do ficheiro não corresponde ao tipo declarado");
        }
        
        // Generate unique filename with sanitized extension
        $filename = uniqid('file_', true) . '.' . $extension;
        
        // Create folder structure: condominiums/{condominium_id}/{type}/{subfolder}/{year}/{month}/
        $year = date('Y');
        $month = date('m');
        $storagePath = 'condominiums/' . $condominiumId . '/' . $type;
        if ($subfolder) {
            $storagePath .= '/' . $subfolder;
        }
        $storagePath .= '/' . $year . '/' . $month . '/';
        $fullPath = $this->basePath . '/' . $storagePath;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $filePath = $storagePath . $filename;
        $fullFilePath = $fullPath . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullFilePath)) {
            throw new \Exception("Erro ao guardar ficheiro");
        }

        return [
            'file_path' => $filePath,
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'mime_type' => $mimeType
        ];
    }

    /**
     * Get file path
     */
    public function getFilePath(string $relativePath): string
    {
        return $this->basePath . '/' . $relativePath;
    }

    /**
     * Get file URL for web access
     */
    public function getFileUrl(string $relativePath): string
    {
        return BASE_URL . 'storage/' . $relativePath;
    }

    /**
     * Delete file
     */
    public function delete(string $relativePath): bool
    {
        $fullPath = $this->getFilePath($relativePath);
        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    /**
     * Upload logo for condominium
     * @param array $file File array from $_FILES
     * @param int $condominiumId Condominium ID
     * @return array File data with file_path, file_name, file_size, mime_type
     */
    public function uploadLogo(array $file, int $condominiumId): array
    {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \Exception("Ficheiro inválido");
        }

        // Max size: 2MB for logos
        $maxSize = 2097152; // 2MB
        if ($file['size'] > $maxSize) {
            throw new \Exception("Ficheiro muito grande. Máximo: 2MB");
        }

        // Detect MIME type using multiple methods with fallback
        $mimeType = $this->detectMimeType($file['tmp_name'], $file);
        
        // Only allow image types
        $allowedImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedImageTypes)) {
            throw new \Exception("Apenas imagens são permitidas (JPEG, PNG, GIF, WebP)");
        }

        // Sanitize filename - remove dangerous characters
        $originalName = basename($file['name']);
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $originalName = preg_replace('/\.{2,}/', '.', $originalName);
        $originalName = trim($originalName, '.');
        
        // Get extension from sanitized name
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // Validate extension against MIME type
        $allowedImageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extension, $allowedImageExtensions)) {
            throw new \Exception("Extensão de ficheiro inválida para logo");
        }
        
        // Verify file content matches image type
        if (!$this->verifyFileContent($file['tmp_name'], $mimeType, $extension)) {
            throw new \Exception("Conteúdo do ficheiro não corresponde ao tipo de imagem");
        }
        
        // Generate filename: logo.{ext}
        $filename = 'logo.' . $extension;
        
        // Create folder structure: condominiums/{condominium_id}/logo/
        $storagePath = 'condominiums/' . $condominiumId . '/logo/';
        $fullPath = $this->basePath . '/' . $storagePath;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        // Delete old logo if exists
        $oldLogoPath = $fullPath . 'logo.*';
        $oldLogos = glob($oldLogoPath);
        foreach ($oldLogos as $oldLogo) {
            if (is_file($oldLogo)) {
                unlink($oldLogo);
            }
        }

        $filePath = $storagePath . $filename;
        $fullFilePath = $fullPath . $filename;

        if (!move_uploaded_file($file['tmp_name'], $fullFilePath)) {
            throw new \Exception("Erro ao guardar ficheiro");
        }

        return [
            'file_path' => $filePath,
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'mime_type' => $mimeType
        ];
    }

    /**
     * Verify file content matches declared MIME type
     * 
     * @param string $filePath Path to uploaded file
     * @param string $mimeType Detected MIME type
     * @param string $extension File extension
     * @return bool True if content matches type
     */
    protected function verifyFileContent(string $filePath, string $mimeType, string $extension): bool
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return false;
        }
        
        // Read first bytes to verify file signature
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        
        $bytes = fread($handle, 12); // Read first 12 bytes
        fclose($handle);
        
        if ($bytes === false) {
            return false;
        }
        
        // Check file signatures (magic bytes)
        $signatures = [
            'image/jpeg' => ["\xFF\xD8\xFF"],
            'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
            'image/gif' => ["\x47\x49\x46\x38\x37\x61", "\x47\x49\x46\x38\x39\x61"], // GIF87a or GIF89a
            'image/webp' => ["\x52\x49\x46\x46"], // RIFF (WebP starts with RIFF)
            'application/pdf' => ["\x25\x50\x44\x46"], // %PDF
        ];
        
        // For images, verify signature
        if (isset($signatures[$mimeType])) {
            foreach ($signatures[$mimeType] as $signature) {
                if (substr($bytes, 0, strlen($signature)) === $signature) {
                    // Special check for WebP - must have WEBP after RIFF
                    if ($mimeType === 'image/webp' && strpos($bytes, 'WEBP') === false) {
                        continue;
                    }
                    return true;
                }
            }
            return false;
        }
        
        // For other file types, basic validation
        // Office documents and text files are harder to verify without libraries
        // So we rely on MIME type detection and extension validation
        return true;
    }

    /**
     * Format file size
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Save generated file content (not uploaded)
     * @param string $content File content to save
     * @param string $filename Original filename
     * @param int $condominiumId Condominium ID
     * @param string $type Type of file: 'documents', 'reports', etc.
     * @param string $mimeType MIME type (default: application/pdf)
     * @return array File data with file_path, file_name, file_size, mime_type
     */
    public function saveGeneratedFile(string $content, string $filename, int $condominiumId, string $type = 'documents', string $mimeType = 'application/pdf'): array
    {
        // Sanitize filename
        $originalName = basename($filename);
        $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $originalName = preg_replace('/\.{2,}/', '.', $originalName);
        $originalName = trim($originalName, '.');
        
        // Get extension from filename or MIME type
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!$extension) {
            // Determine extension from MIME type
            $extensionMap = [
                'application/pdf' => 'pdf',
                'text/html' => 'html',
                'text/plain' => 'txt',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
            ];
            $extension = $extensionMap[$mimeType] ?? 'txt';
        }
        
        // Generate unique filename
        $uniqueFilename = uniqid('report_', true) . '.' . $extension;
        
        // Create folder structure: condominiums/{condominium_id}/{type}/{year}/{month}/
        $year = date('Y');
        $month = date('m');
        $storagePath = 'condominiums/' . $condominiumId . '/' . $type;
        $storagePath .= '/' . $year . '/' . $month . '/';
        $fullPath = $this->basePath . '/' . $storagePath;

        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }

        $filePath = $storagePath . $uniqueFilename;
        $fullFilePath = $fullPath . $uniqueFilename;

        // Save content to file
        if (file_put_contents($fullFilePath, $content) === false) {
            throw new \Exception("Erro ao guardar ficheiro gerado");
        }

        $fileSize = filesize($fullFilePath);

        return [
            'file_path' => $filePath,
            'file_name' => $originalName ?: $uniqueFilename,
            'file_size' => $fileSize,
            'mime_type' => $mimeType
        ];
    }
}





