<?php

namespace App\Controllers;

use App\Core\Controller;

class HomeController extends Controller
{
    public function index()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('home');
        
        // Get plans for pricing section
        $planModel = new \App\Models\Plan();
        $plans = $planModel->getActivePlans();
        
        $this->data += [
            'viewName' => 'pages/home.html.twig',
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ],
            'plans' => $plans
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

