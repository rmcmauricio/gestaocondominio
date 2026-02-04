<?php

namespace App\Services;

class GoogleOAuthService
{
    protected $client;
    protected $clientId;
    protected $clientSecret;
    protected $redirectUri;

    public function __construct()
    {
        // Ensure Composer autoloader is loaded (should already be loaded via config.php/index.php)
        // But ensure it's loaded here as well for safety
        $autoloadFile = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($autoloadFile) && !class_exists('Composer\Autoload\ClassLoader', false)) {
            require_once $autoloadFile;
        }
        
        // Force autoloader to be active by checking if it's registered
        if (!spl_autoload_functions()) {
            require_once $autoloadFile;
        }
        
        global $config;
        
        // Load config if not already loaded
        if (!isset($config)) {
            $config = [];
            $envFile = __DIR__ . '/../../.env';
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                        list($key, $value) = explode('=', $line, 2);
                        $config[trim($key)] = trim($value);
                    }
                }
            }
        }

        $this->clientId = $config['GOOGLE_CLIENT_ID'] ?? '';
        $this->clientSecret = $config['GOOGLE_CLIENT_SECRET'] ?? '';
        
        // Get BASE_URL and BASE_PATH if not defined
        if (!defined('BASE_URL')) {
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $basePath = defined('BASE_PATH') && BASE_PATH ? BASE_PATH . '/' : '';
            $baseUrl = $protocol . '://' . $host . '/' . $basePath;
            define('BASE_URL', $baseUrl);
        }
        
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $config['BASE_PATH'] ?? '');
        }
        
        // Build redirect URI - if provided in .env, ensure BASE_PATH is included
        if (isset($config['GOOGLE_REDIRECT_URI']) && !empty($config['GOOGLE_REDIRECT_URI'])) {
            $redirectUri = $config['GOOGLE_REDIRECT_URI'];
            // If redirect URI is a full URL (starts with http), use as is
            if (strpos($redirectUri, 'http') === 0) {
                $this->redirectUri = $redirectUri;
            } else {
                // If it's a relative path, build full URL with BASE_PATH
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $basePath = BASE_PATH ? BASE_PATH . '/' : '';
                $this->redirectUri = $protocol . '://' . $host . '/' . $basePath . ltrim($redirectUri, '/');
            }
        } else {
            // Default: use BASE_URL which already includes BASE_PATH
            $this->redirectUri = BASE_URL . 'auth/google/callback';
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new \Exception('Google OAuth credentials not configured. Please set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env file.');
        }

        // Load Google classes manually - ensure all dependencies are loaded
        // The autoloader may not be working correctly for Google namespaces
        $vendorPath = __DIR__ . '/../../vendor';
        
        // 1. Load GetUniverseDomainInterface first (required by Client constructor)
        if (!interface_exists('Google\Auth\GetUniverseDomainInterface', false)) {
            require_once $vendorPath . '/google/auth/src/GetUniverseDomainInterface.php';
        }
        
        // 2. Load Google Client - load directly since autoloader isn't working
        if (!class_exists('Google\Client', false)) {
            require_once $vendorPath . '/google/apiclient/src/Client.php';
        }
        
        // 3. Load Google Service base class
        if (!class_exists('Google\Service', false)) {
            require_once $vendorPath . '/google/apiclient/src/Service.php';
        }
        
        // 4. Load Oauth2 service
        if (!class_exists('Google\Service\Oauth2', false)) {
            require_once $vendorPath . '/google/apiclient-services/src/Oauth2.php';
        }

        $this->client = new \Google\Client();
        $this->client->setClientId($this->clientId);
        $this->client->setClientSecret($this->clientSecret);
        $this->client->setRedirectUri($this->redirectUri);
        $this->client->addScope('email');
        $this->client->addScope('profile');
        $this->client->setAccessType('offline');
        $this->client->setPrompt('select_account');
    }

    /**
     * Get Google OAuth authorization URL
     */
    public function getAuthUrl(): string
    {
        return $this->client->createAuthUrl();
    }

    /**
     * Handle OAuth callback and get user info
     * @param string $code Authorization code from Google
     * @return array User information from Google
     */
    public function handleCallback(string $code): array
    {
        try {
            // Exchange authorization code for access token
            $token = $this->client->fetchAccessTokenWithAuthCode($code);
            
            if (isset($token['error'])) {
                throw new \Exception('Error fetching access token: ' . $token['error']);
            }

            $this->client->setAccessToken($token);

            // Get user info
            $oauth2 = new \Google\Service\Oauth2($this->client);
            $userInfo = $oauth2->userinfo->get();

            return [
                'google_id' => $userInfo->getId(),
                'email' => $userInfo->getEmail(),
                'name' => $userInfo->getName(),
                'picture' => $userInfo->getPicture(),
                'verified_email' => $userInfo->getVerifiedEmail()
            ];
        } catch (\Exception $e) {
            throw new \Exception('Error processing Google OAuth callback: ' . $e->getMessage());
        }
    }

    /**
     * Get user info from access token
     * @param string $accessToken Access token
     * @return array User information
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $this->client->setAccessToken($accessToken);
            $oauth2 = new \Google\Service\Oauth2($this->client);
            $userInfo = $oauth2->userinfo->get();

            return [
                'google_id' => $userInfo->getId(),
                'email' => $userInfo->getEmail(),
                'name' => $userInfo->getName(),
                'picture' => $userInfo->getPicture(),
                'verified_email' => $userInfo->getVerifiedEmail()
            ];
        } catch (\Exception $e) {
            throw new \Exception('Error fetching user info: ' . $e->getMessage());
        }
    }
}
