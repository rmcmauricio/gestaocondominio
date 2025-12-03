<?php

namespace App\Controllers;

use App\Core\Controller;

class HomeController extends Controller
{
    public function index()
    {
        $this->data += [
            'viewName' => 'pages/home.html.twig',
            'page' => [
                'titulo' => 'Welcome to MVC Framework',
                'description' => 'Simple and lightweight PHP MVC Framework',
                'keywords' => 'mvc, framework, php'
            ]
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

