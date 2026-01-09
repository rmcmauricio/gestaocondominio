<?php

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Middleware\ApiAuthMiddleware;

class ApiController extends Controller
{
    protected $user;

    public function __construct()
    {
        parent::__construct();
        $this->user = ApiAuthMiddleware::require();
    }

    /**
     * Send JSON response
     */
    protected function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Send success response
     */
    protected function success(array $data = [], string $message = 'Success', int $code = 200): void
    {
        $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ], $code);
    }

    /**
     * Send error response
     */
    protected function error(string $message, int $code = 400, array $errors = []): void
    {
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        $this->json($response, $code);
    }
}





