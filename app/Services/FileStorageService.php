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

        $mimeType = mime_content_type($file['tmp_name']);
        
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





