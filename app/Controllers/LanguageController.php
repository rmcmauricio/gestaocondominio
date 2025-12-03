<?php

namespace App\Controllers;

use App\Core\Controller;

class LanguageController extends Controller
{
    public function switch($lang)
    {
        // Validate language
        $allowedLanguages = ['pt', 'en'];
        
        if (!in_array($lang, $allowedLanguages)) {
            $lang = 'pt'; // Default to Portuguese
        }
        
        // Set language in session
        $_SESSION['lang'] = $lang;
        
        // Redirect back to previous page or home
        $referer = $_SERVER['HTTP_REFERER'] ?? BASE_URL;
        
        // Remove language from referer if present
        $referer = preg_replace('/\/lang\/(pt|en)/', '', $referer);
        
        header('Location: ' . $referer);
        exit;
    }
}

