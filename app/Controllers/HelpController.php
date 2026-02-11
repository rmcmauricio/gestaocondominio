<?php

namespace App\Controllers;

use App\Core\Controller;

class HelpController extends Controller
{
    // Ordem linear de navegação - todas as páginas na sequência
    private $helpNavigationOrder = [
        'dashboard',
        'finances',
        'finances-budgets',
        'finances-fees',
        'finances-expenses',
        'finances-revenues',
        'finances-historical-debts',
        'finances-historical-credits',
        'finances-reports',
        'finances-fees-map',
        'fractions',
        'documents',
        'occurrences',
        'assemblies',
        'messages',
        'reservations',
        'suppliers',
        'bank-accounts',
        'receipts',
        'notifications',
        'profile',
        'subscriptions',
        'invitations'
    ];

    private $helpSections = [
        'dashboard' => [
            'title' => 'Dashboard',
            'icon' => 'bi-speedometer2',
            'description' => 'Visão geral do dashboard, navegação entre condomínios e estatísticas',
            'subsections' => []
        ],
        'finances' => [
            'title' => 'Finanças',
            'icon' => 'bi-cash-stack',
            'description' => 'Gerir orçamentos, quotas, despesas e receitas',
            'subsections' => [
                'finances-budgets' => 'Orçamentos',
                'finances-fees' => 'Quotas',
                'finances-expenses' => 'Despesas',
                'finances-revenues' => 'Receitas',
                'finances-historical-debts' => 'Dívidas Históricas',
                'finances-historical-credits' => 'Créditos Históricos',
                'finances-reports' => 'Relatórios',
                'finances-fees-map' => 'Mapa de Quotas'
            ]
        ],
        'fractions' => [
            'title' => 'Frações',
            'icon' => 'bi-building',
            'description' => 'Criar e editar frações, atribuir a condóminos e gerir permilagem',
            'subsections' => []
        ],
        'documents' => [
            'title' => 'Documentos',
            'icon' => 'bi-folder',
            'description' => 'Upload e organização de documentos, pastas e versões',
            'subsections' => []
        ],
        'occurrences' => [
            'title' => 'Ocorrências',
            'icon' => 'bi-exclamation-triangle',
            'description' => 'Criar ocorrências, workflow de estados e atribuição a fornecedores',
            'subsections' => []
        ],
        'assemblies' => [
            'title' => 'Assembleias',
            'icon' => 'bi-people',
            'description' => 'Criar assembleias, enviar convocatórias, registar presenças e gerar atas',
            'subsections' => []
        ],
        'messages' => [
            'title' => 'Mensagens',
            'icon' => 'bi-chat-dots',
            'description' => 'Criar mensagens, responder e gerir anexos',
            'subsections' => []
        ],
        'reservations' => [
            'title' => 'Reservas',
            'icon' => 'bi-calendar-check',
            'description' => 'Criar espaços comuns, fazer reservas e aprovar/rejeitar',
            'subsections' => []
        ],
        'suppliers' => [
            'title' => 'Fornecedores',
            'icon' => 'bi-truck',
            'description' => 'Gerir fornecedores, criar contratos e associar a ocorrências',
            'subsections' => []
        ],
        'bank-accounts' => [
            'title' => 'Contas Bancárias',
            'icon' => 'bi-bank',
            'description' => 'Adicionar contas, movimentos financeiros e saldos',
            'subsections' => []
        ],
        'receipts' => [
            'title' => 'Recibos',
            'icon' => 'bi-receipt',
            'description' => 'Visualizar recibos, descarregar PDFs e recibos automáticos',
            'subsections' => []
        ],
        'notifications' => [
            'title' => 'Notificações',
            'icon' => 'bi-bell',
            'description' => 'Ver notificações, marcar como lidas e configurações',
            'subsections' => []
        ],
        'profile' => [
            'title' => 'Perfil',
            'icon' => 'bi-person',
            'description' => 'Editar perfil, alterar password e preferências',
            'subsections' => []
        ],
        'subscriptions' => [
            'title' => 'Subscrições',
            'icon' => 'bi-credit-card',
            'description' => 'Ver plano atual, alterar plano e histórico de pagamentos',
            'subsections' => []
        ],
        'invitations' => [
            'title' => 'Convites',
            'icon' => 'bi-envelope-paper',
            'description' => 'Enviar convites, aceitar convites e gerir membros',
            'subsections' => []
        ]
    ];

    /**
     * Get navigation info (previous and next sections)
     */
    private function getNavigationInfo(string $currentSection): array
    {
        $currentIndex = array_search($currentSection, $this->helpNavigationOrder);
        
        if ($currentIndex === false) {
            return ['prev' => null, 'next' => null];
        }
        
        $prevSection = $currentIndex > 0 ? $this->helpNavigationOrder[$currentIndex - 1] : null;
        $nextSection = $currentIndex < count($this->helpNavigationOrder) - 1 ? $this->helpNavigationOrder[$currentIndex + 1] : null;
        
        $prevTitle = null;
        $nextTitle = null;
        
        if ($prevSection) {
            if (isset($this->helpSections[$prevSection])) {
                $prevTitle = $this->helpSections[$prevSection]['title'];
            } else {
                // Check if it's a subsection
                foreach ($this->helpSections as $sectionData) {
                    if (isset($sectionData['subsections'][$prevSection])) {
                        $prevTitle = $sectionData['subsections'][$prevSection];
                        break;
                    }
                }
            }
        }
        
        if ($nextSection) {
            if (isset($this->helpSections[$nextSection])) {
                $nextTitle = $this->helpSections[$nextSection]['title'];
            } else {
                // Check if it's a subsection
                foreach ($this->helpSections as $sectionData) {
                    if (isset($sectionData['subsections'][$nextSection])) {
                        $nextTitle = $sectionData['subsections'][$nextSection];
                        break;
                    }
                }
            }
        }
        
        return [
            'prev' => $prevSection ? ['section' => $prevSection, 'title' => $prevTitle] : null,
            'next' => $nextSection ? ['section' => $nextSection, 'title' => $nextTitle] : null
        ];
    }

    public function index()
    {
        // Load page metadata from Metafiles
        $this->page->setPage('help');
        
        $this->data += [
            'viewName' => 'pages/help/index.html.twig',
            'page' => [
                'titulo' => $this->page->titulo,
                'description' => $this->page->description,
                'keywords' => $this->page->keywords
            ],
            'helpSections' => $this->helpSections
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function show(string $section)
    {
        // Check if section exists directly
        $sectionKey = $section;
        $isSubsection = false;
        
        // First check if it's a main section
        if (isset($this->helpSections[$section])) {
            $sectionKey = $section;
            $isSubsection = false;
        } else {
            // Check if it's a subsection (e.g., finances-budgets)
            foreach ($this->helpSections as $key => $sectionData) {
                if (isset($sectionData['subsections'][$section])) {
                    $sectionKey = $key;
                    $isSubsection = true;
                    break;
                }
            }
            
            if (!isset($this->helpSections[$sectionKey])) {
                // Section not found, redirect to help index
                header('Location: ' . BASE_URL . 'help');
                exit;
            }
        }
        
        // Load page metadata from Metafiles (if exists)
        $metafileKey = 'help-' . str_replace('-', '-', $section);
        try {
            $this->page->setPage($metafileKey);
        } catch (\Exception $e) {
            // Metafile doesn't exist, use defaults
            $this->page->titulo = '';
            $this->page->description = '';
            $this->page->keywords = '';
        }
        
        $sectionData = $isSubsection ? $this->helpSections[$sectionKey] : $this->helpSections[$sectionKey];
        $subsectionTitle = $isSubsection ? $this->helpSections[$sectionKey]['subsections'][$section] : null;
        
        // Get navigation info
        $navigation = $this->getNavigationInfo($section);
        
        $this->data += [
            'viewName' => 'pages/help/' . $section . '.html.twig',
            'page' => [
                'titulo' => $this->page->titulo ?: ($subsectionTitle ? $subsectionTitle . ' - Ajuda | O Meu Prédio' : $sectionData['title'] . ' - Ajuda | O Meu Prédio'),
                'description' => $this->page->description ?: $sectionData['description'],
                'keywords' => $this->page->keywords ?: 'ajuda, ' . strtolower($sectionData['title']) . ', tutorial'
            ],
            'section' => $section,
            'sectionData' => $sectionData,
            'subsectionTitle' => $subsectionTitle,
            'isSubsection' => $isSubsection,
            'parentSection' => $sectionKey,
            'helpSections' => $this->helpSections,
            'navigation' => $navigation
        ];
        
        echo $GLOBALS['twig']->render('templates/mainTemplate.html.twig', $this->data);
    }

    public function modal(string $section)
    {
        // Check if section exists directly
        $sectionKey = $section;
        $isSubsection = false;
        
        // First check if it's a main section
        if (isset($this->helpSections[$section])) {
            $sectionKey = $section;
            $isSubsection = false;
        } else {
            // Check if it's a subsection
            foreach ($this->helpSections as $key => $sectionData) {
                if (isset($sectionData['subsections'][$section])) {
                    $sectionKey = $key;
                    $isSubsection = true;
                    break;
                }
            }
            
            if (!isset($this->helpSections[$sectionKey])) {
                http_response_code(404);
                echo json_encode(['error' => 'Seção não encontrada']);
                exit;
            }
        }
        
        $sectionData = $isSubsection ? $this->helpSections[$sectionKey] : $this->helpSections[$sectionKey];
        $subsectionTitle = $isSubsection ? $this->helpSections[$sectionKey]['subsections'][$section] : null;
        
        // Get navigation info
        $navigation = $this->getNavigationInfo($section);
        
        // Load the help content view
        $viewPath = 'pages/help/' . $section . '.html.twig';
        $fullPath = __DIR__ . '/../Views/' . $viewPath;
        
        if (!file_exists($fullPath)) {
            http_response_code(404);
            echo json_encode(['error' => 'Conteúdo não encontrado']);
            exit;
        }
        
        // Render the view content
        global $twig;
        $content = $twig->render($viewPath, array_merge($this->data, [
            'section' => $section,
            'sectionData' => $sectionData,
            'subsectionTitle' => $subsectionTitle,
            'isSubsection' => $isSubsection,
            'parentSection' => $sectionKey,
            'isModal' => true,
            'navigation' => $navigation
        ]));
        
        header('Content-Type: application/json');
        echo json_encode([
            'title' => $subsectionTitle ?: $sectionData['title'],
            'content' => $content
        ]);
    }
}
