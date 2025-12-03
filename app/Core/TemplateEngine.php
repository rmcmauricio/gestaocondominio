<?php
namespace App\Core;

use Twig\Loader\FilesystemLoader;
use Twig\Environment;

class TemplateEngine
{
    private Environment $twig;

    public function __construct()
    {
        $loader = new FilesystemLoader(__DIR__ . '/../Views');
        $this->twig = new Environment($loader);
    }

    public function renderFile(string $templatePath, $context, array $data = []): string
    {
        // Remove extensão .html se houver
        $templatePath = str_replace('.html', '', $templatePath);
        return $this->twig->render($templatePath . '.html.twig', $data);
    }
}
