<?php

namespace App\Controllers;

use App\Core\Controller;

class LegalController extends Controller
{
    public function faq()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('faq');
        
        $this->data += [
            'viewName' => 'pages/legal/faq.html.twig',
            'isHomepage' => false,
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ]
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function terms()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('terms');
        
        $this->data += [
            'viewName' => 'pages/legal/terms.html.twig',
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ]
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function privacy()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('privacy');
        
        $this->data += [
            'viewName' => 'pages/legal/privacy.html.twig',
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ]
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function cookies()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('cookies');
        
        $this->data += [
            'viewName' => 'pages/legal/cookies.html.twig',
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ]
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }
}
