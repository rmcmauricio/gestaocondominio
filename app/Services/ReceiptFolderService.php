<?php

namespace App\Services;

use App\Models\Folder;

/**
 * Service para garantir que as pastas de recibos existem no sistema de folders
 */
class ReceiptFolderService
{
    /**
     * Garante que as pastas de recibos existem no sistema de folders
     * Cria a estrutura: recibos/{year}/{fraction_identifier}
     * 
     * @param int $condominiumId
     * @param string $year Ano do recibo
     * @param string $fractionIdentifier Identificador da fração (ex: "A1", "B2")
     * @param int $userId ID do utilizador que está a criar (para created_by)
     * @return string Path completo da pasta final: "recibos/{year}/{fraction_identifier}"
     */
    public function ensureReceiptFolders(int $condominiumId, string $year, string $fractionIdentifier, int $userId): string
    {
        $folderModel = new Folder();
        
        // 1. Criar/verificar pasta raiz "recibos"
        $rootPath = 'recibos';
        $rootFolder = $folderModel->findByPath($condominiumId, $rootPath);
        
        if (!$rootFolder) {
            $folderModel->create([
                'condominium_id' => $condominiumId,
                'name' => 'recibos',
                'parent_folder_id' => null,
                'path' => $rootPath,
                'created_by' => $userId
            ]);
        }
        
        // 2. Criar/verificar pasta do ano "recibos/{year}"
        $yearPath = "recibos/{$year}";
        $yearFolder = $folderModel->findByPath($condominiumId, $yearPath);
        
        if (!$yearFolder) {
            // Precisamos do ID da pasta raiz
            $rootFolder = $folderModel->findByPath($condominiumId, $rootPath);
            if (!$rootFolder) {
                throw new \Exception("Erro ao criar pasta raiz de recibos");
            }
            
            $folderModel->create([
                'condominium_id' => $condominiumId,
                'name' => $year,
                'parent_folder_id' => $rootFolder['id'],
                'path' => $yearPath,
                'created_by' => $userId
            ]);
        }
        
        // 3. Criar/verificar pasta da fração "recibos/{year}/{fraction_identifier}"
        $fractionPath = "recibos/{$year}/{$fractionIdentifier}";
        $fractionFolder = $folderModel->findByPath($condominiumId, $fractionPath);
        
        if (!$fractionFolder) {
            // Precisamos do ID da pasta do ano
            $yearFolder = $folderModel->findByPath($condominiumId, $yearPath);
            if (!$yearFolder) {
                throw new \Exception("Erro ao criar pasta do ano");
            }
            
            $folderModel->create([
                'condominium_id' => $condominiumId,
                'name' => $fractionIdentifier,
                'parent_folder_id' => $yearFolder['id'],
                'path' => $fractionPath,
                'created_by' => $userId
            ]);
        }
        
        return $fractionPath;
    }
}
