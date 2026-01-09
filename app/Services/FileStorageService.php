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
        $this->basePath = __DIR__ . '/../../storage/documents';
        
        // Create directories if they don't exist
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Upload file
     */
    public function upload(array $file, int $condominiumId, string $folder = null): array
    {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \Exception("Ficheiro inválido");
        }

        if ($file['size'] > $this->maxFileSize) {
            throw new \Exception("Ficheiro muito grande. Máximo: " . ($this->maxFileSize / 1048576) . "MB");
        }

        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            throw new \Exception("Tipo de ficheiro não permitido");
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid('doc_', true) . '.' . $extension;
        
        // Create folder structure: condominium_id/folder/year/month/
        $year = date('Y');
        $month = date('m');
        $storagePath = $condominiumId . '/' . ($folder ? $folder . '/' : '') . $year . '/' . $month . '/';
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





