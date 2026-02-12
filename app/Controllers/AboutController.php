<?php

namespace App\Controllers;

use App\Core\Controller;

class AboutController extends Controller
{
    public function index()
    {
        $this->loadPageTranslations('about');
        
        $this->data += [
            'viewName' => 'pages/about.html.twig',
            'page' => [
                'titulo' => 'About MVC Framework',
                'description' => 'Learn more about the MVC Framework',
                'keywords' => 'mvc, framework, about, php'
            ]
        ];
        
        $this->renderMainTemplate();
    }
}

