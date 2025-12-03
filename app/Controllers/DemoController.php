<?php

namespace App\Controllers;

use App\Core\Controller;

class DemoController extends Controller
{
    public function index()
    {
        $this->loadPageTranslations('demo');
        
        $this->data += [
            'viewName' => 'pages/demo.html.twig',
            'page' => [
                'titulo' => 'Framework Demo & Documentation',
                'description' => 'Learn how to use the MVC Framework',
                'keywords' => 'mvc, framework, documentation, tutorial'
            ]
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

