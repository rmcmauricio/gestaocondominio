<?php

namespace Addons\SupportTickets\Services;

/**
 * Upload images for support tickets (inline in editor).
 * Stores under storage/support/tickets/{user_id}/{year}/{month}/
 */
class TicketUploadService
{
    protected $basePath;
    protected $maxSize = 2097152; // 2MB
    protected $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 3) . '/storage';
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    /**
     * Upload image and return relative path for URL (storage/...).
     */
    public function upload(array $file, int $userId): array
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \Exception("Ficheiro inválido");
        }
        if ($file['size'] > $this->maxSize) {
            throw new \Exception("Ficheiro muito grande. Máximo: 2MB");
        }
        $mimeType = $this->detectMimeType($file['tmp_name'], $file);
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            throw new \Exception("Apenas imagens são permitidas (JPEG, PNG, GIF, WebP)");
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $ext = strtolower($ext ?: 'jpg');
        $allowedExt = ['jpg' => true, 'jpeg' => true, 'png' => true, 'gif' => true, 'webp' => true];
        if (!isset($allowedExt[$ext])) {
            $ext = 'jpg';
        }
        $filename = uniqid('ticket_', true) . '.' . $ext;
        $year = date('Y');
        $month = date('m');
        $relativePath = 'support/tickets/' . (int) $userId . '/' . $year . '/' . $month . '/';
        $fullPath = $this->basePath . '/' . $relativePath;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }
        $relativePath .= $filename;
        $fullPath .= $filename;
        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new \Exception("Erro ao guardar ficheiro");
        }
        return [
            'file_path' => $relativePath,
            'file_name' => $file['name'],
            'file_size' => $file['size'],
            'mime_type' => $mimeType,
        ];
    }

    public function getFileUrl(string $relativePath): string
    {
        return BASE_URL . 'storage/' . $relativePath;
    }

    protected function detectMimeType(string $filePath, array $fileInfo = []): string
    {
        if (function_exists('mime_content_type')) {
            $mime = mime_content_type($filePath);
            if ($mime) {
                return $mime;
            }
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if ($mime) {
                    return $mime;
                }
            }
        }
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
        return $map[$ext] ?? ($fileInfo['type'] ?? 'application/octet-stream');
    }
}
