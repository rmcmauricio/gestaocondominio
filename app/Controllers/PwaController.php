<?php

namespace App\Controllers;

use App\Core\Controller;

/**
 * Serves PWA manifest and service worker for the mobile version (/m/*).
 */
class PwaController extends Controller
{
    /**
     * Serve the web app manifest for the mobile PWA.
     * Route: GET /manifest-mobile.json
     */
    public function manifest(): void
    {
        $baseUrl = rtrim(BASE_URL, '/') . '/';
        $manifest = [
            'name' => 'O Meu Prédio',
            'short_name' => 'O Meu Prédio',
            'start_url' => $baseUrl . 'm/dashboard',
            'scope' => $baseUrl . 'm/',
            'display' => 'standalone',
            'theme_color' => '#e07920',
            'background_color' => '#ffffff',
            'orientation' => 'portrait-primary',
            'icons' => [
                [
                    'src' => $baseUrl . 'assets/images/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => $baseUrl . 'assets/images/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => $baseUrl . 'assets/images/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
                [
                    'src' => $baseUrl . 'assets/images/icon-512.png',
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'maskable',
                ],
                [
                    'src' => $baseUrl . 'assets/images/apple-touch-icon.png',
                    'sizes' => '180x180',
                    'type' => 'image/png',
                    'purpose' => 'any',
                ],
            ],
        ];

        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Serve the service worker script.
     * Route: GET /sw-mobile.js
     */
    public function serviceWorker(): void
    {
        $path = __DIR__ . '/../../assets/js/sw-mobile.js';
        if (!is_readable($path)) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Service worker not found';
            return;
        }

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Service-Worker-Allowed: /');
        readfile($path);
    }
}
