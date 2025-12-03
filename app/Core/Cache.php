<?php
namespace App\Core;

class Cache {

    private $cache;

    public function setVar($nome, $value) {
        $this->readCache();
        $this->cache[$nome] = $value;
        $this->saveCache();
    }

    public function setVarWithExpiry($nome, $value, $expiresInSeconds = 300) {
        $this->readCache();
        $this->cache[$nome] = [
            'data' => $value,
            'timestamp' => time(),
            'expires_in' => $expiresInSeconds
        ];
        $this->saveCache();
    }

    public function setPage($name, $value) {
        file_put_contents('cache/'.$name.".cache", $value);
    }

    public function getVar($nome) {
        $this->readCache();
        if (isset($this->cache[$nome]) && !empty($this->cache[$nome])) {
            $data = $this->cache[$nome];
            
            // Verificar se tem timestamp de expiração
            if (is_array($data) && isset($data['timestamp']) && isset($data['expires_in'])) {
                $expiresAt = $data['timestamp'] + $data['expires_in'];
                if (time() > $expiresAt) {
                    // Cache expirado - remover
                    unset($this->cache[$nome]);
                    $this->saveCache();
                    return null;
                }
                return $data['data'] ?? $data;
            }
            
            return $data;
        }
        return null;
    }

    private function readCache() {
        $this->cache = [];
        if(file_exists('cache.cache')) {
            $this->cache = json_decode(file_get_contents('cache.cache'), true);
            if ($this->cache === null) {
                $this->cache = [];
            }
        }
    }

    private function saveCache() {
        file_put_contents('cache.cache', json_encode($this->cache));
    }

}

