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

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
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

        // Generate filename: logo.{ext}
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
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
}





