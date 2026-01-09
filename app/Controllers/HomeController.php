<?php

namespace App\Controllers;

use App\Core\Controller;

class HomeController extends Controller
{
    public function index()
    {
        // Get plans for pricing section
        $planModel = new \App\Models\Plan();
        $plans = $planModel->getActivePlans();
        
        $this->data += [
            'viewName' => 'pages/home.html.twig',
            'page' => [
                'titulo' => 'MeuPrédio - Gestão Completa de Condomínios',
                'description' => 'Solução SaaS para gestão de condomínios em Portugal. Automatize quotas, assembleias, documentos e muito mais.',
                'keywords' => 'gestão condomínios, software condomínios, quotas automáticas, assembleias online, portugal'
            ],
            'plans' => $plans
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}

