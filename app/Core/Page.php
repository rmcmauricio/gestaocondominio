<?php
namespace App\Core;

use App\Core;

class Page extends Model{

    public $titulo;
    public $description;
    public $keywords;
    public $t;

    public function setPage($page)
    {
        $lang = $_SESSION['lang'] ?? 'pt';
        $file = __DIR__ . "/../Metafiles/{$lang}/{$page}.json";

        if (!file_exists($file)) {
            throw new \Exception("Metafile '{$file}' não encontrado.");
        }
        
        $pageData = json_decode(file_get_contents($file), true);
        
        // Verificar se o JSON foi decodificado corretamente
        if ($pageData === null || !is_array($pageData)) {
            throw new \Exception("Erro ao decodificar metafile '{$file}'. JSON inválido ou vazio.");
        }
        
        //$page = json_decode(file_get_contents(BASE_URL."/app/Metafiles/".$page.".json"), TRUE);
        $this->titulo = $pageData['titulo'] ?? '';
        $this->description = $pageData['description'] ?? '';
        $this->keywords = $pageData['keywords'] ?? '';
        if (isset($pageData['t'])) {
            $this->t = $pageData['t'];
        }

    }

    public function setTitulo($titulo) {

        $this->utils->log($this);
        $this->titulo = $titulo;
    }

}