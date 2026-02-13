<?php

namespace Addons\HelpChatbot\Controllers;

use App\Core\Controller;
use App\Middleware\AuthMiddleware;
use App\Middleware\RoleMiddleware;

class HelpController extends Controller
{
    public function index(): void
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $this->data['viewName'] = '@addon_help_chatbot/index.html.twig';
        $this->data['page'] = [
            'titulo' => 'Ajuda - Assistente',
            'description' => 'Pesquise no manual e FAQ.',
            'keywords' => 'ajuda, faq, manual',
        ];
        $this->mergeGlobalData($this->data);
        $this->renderMainTemplate();
    }

    public function search(): void
    {
        AuthMiddleware::require();
        RoleMiddleware::requireSuperAdmin();

        $q = trim($_GET['q'] ?? $_POST['q'] ?? '');
        if ($q === '') {
            $this->jsonSuccess(['results' => [], 'faq' => [], 'articles' => []]);
        }

        $faqModel = new \Addons\HelpChatbot\Models\HelpFaq();
        $articleModel = new \Addons\HelpChatbot\Models\HelpArticle();
        $faq = $faqModel->search($q, 15);
        $articles = $articleModel->search($q, 10);

        $results = [];
        foreach ($faq as $row) {
            $results[] = [
                'type' => 'faq',
                'title' => $row['question'],
                'snippet' => mb_substr(strip_tags($row['answer']), 0, 300) . (mb_strlen($row['answer']) > 300 ? '...' : ''),
                'url' => null,
            ];
        }
        foreach ($articles as $row) {
            $snippet = !empty($row['body_text'])
                ? mb_substr(strip_tags($row['body_text']), 0, 300) . (mb_strlen($row['body_text']) > 300 ? '...' : '')
                : $row['title'];
            $results[] = [
                'type' => 'article',
                'title' => $row['title'],
                'snippet' => $snippet,
                'url' => BASE_URL . 'help/' . ltrim($row['url_path'], '/'),
            ];
        }
        $this->jsonSuccess(['results' => $results, 'faq' => $faq, 'articles' => $articles]);
    }
}
