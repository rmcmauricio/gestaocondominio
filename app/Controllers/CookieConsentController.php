<?php

namespace App\Controllers;

use App\Core\Controller;

class CookieConsentController extends Controller
{
    /**
     * Save cookie consent preferences
     * POST /api/cookie-consent
     */
    public function save()
    {
        // Get JSON input
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        // Set JSON header
        header('Content-Type: application/json');

        // Validate input
        if (!$data || !isset($data['version']) || !isset($data['timestamp'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Dados inválidos'
            ]);
            exit;
        }

        // Validate structure
        $requiredFields = ['version', 'timestamp', 'essential', 'functional', 'analytics'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => "Campo obrigatório ausente: {$field}"
                ]);
                exit;
            }
        }

        // Essential cookies must always be true
        if ($data['essential'] !== true) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Cookies essenciais não podem ser desativados'
            ]);
            exit;
        }

        // Log consent (optional - for audit purposes)
        // You can add database logging here if needed
        if (isset($_SESSION['user'])) {
            // Log to audit trail if user is logged in
            // This is optional and depends on your audit system
        }

        // Return success
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Preferências de cookies salvas com sucesso',
            'data' => [
                'version' => $data['version'],
                'timestamp' => $data['timestamp'],
                'essential' => $data['essential'],
                'functional' => $data['functional'],
                'analytics' => $data['analytics']
            ]
        ]);
        exit;
    }
}
