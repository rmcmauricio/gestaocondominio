<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;
use App\Services\FileStorageService;

class StorageController extends Controller
{
    protected $fileStorageService;

    public function __construct()
    {
        parent::__construct();
        $this->fileStorageService = new FileStorageService();
    }

    /**
     * Serve storage files with access control
     * Route: /storage/{path}
     */
    public function serve(string $path)
    {
        AuthMiddleware::require();

        // Decode path (may contain URL-encoded slashes)
        $path = urldecode($path);
        
        $pathParts = explode('/', $path);
        if (count($pathParts) < 2) {
            http_response_code(404);
            die('File not found');
        }

        // Support paths: support/tickets/{user_id}/... - allow if current user is owner or super_admin
        if (strpos($path, 'support/tickets/') === 0) {
            $ownerId = (int)($pathParts[2] ?? 0);
            $userId = (int)($_SESSION['user']['id'] ?? 0);
            $role = $_SESSION['user']['role'] ?? '';
            if ($ownerId !== $userId && $role !== 'super_admin') {
                http_response_code(403);
                die('Access denied');
            }
            $filePath = $this->fileStorageService->getFilePath($path);
        } else {
            // Security: Only allow files from condominiums directory
            if (strpos($path, 'condominiums/') !== 0) {
                http_response_code(403);
                die('Access denied');
            }
            $condominiumId = (int)$pathParts[1];
            try {
                RoleMiddleware::requireCondominiumAccess($condominiumId);
            } catch (\Exception $e) {
                http_response_code(403);
                die('Access denied');
            }
            $filePath = $this->fileStorageService->getFilePath($path);
        }
        
        if (!file_exists($filePath)) {
            http_response_code(404);
            die('File not found');
        }

        // Determine MIME type
        $mimeType = mime_content_type($filePath);
        if (!$mimeType) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $mimeTypes = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'pdf' => 'application/pdf',
                'doc' => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls' => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            $mimeType = $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
        }

        // For images, use inline; for others, use attachment
        $disposition = (strpos($mimeType, 'image/') === 0) ? 'inline' : 'attachment';
        
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: ' . $disposition . '; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        
        readfile($filePath);
        exit;
    }
}
