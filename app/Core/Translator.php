<?php
namespace App\Core;

class Translator
{
    private string $lang;
    private array $translations = [];

    public function __construct(string $lang = 'pt')
    {
        $this->lang = $lang;
        $file = __DIR__ . '/../Lang/' . $lang . '.php';
        if (file_exists($file)) {
            $this->translations = include $file;
        }
    }

    public function get(string $key, array $replacements = []): string
    {
        $text = $this->translations[$key] ?? $key;

        foreach ($replacements as $k => $v) {
            $text = str_replace(':' . $k, $v, $text);
        }

        return $text;
    }

    public function getLang(): string
    {
        return $this->lang;
    }
}
