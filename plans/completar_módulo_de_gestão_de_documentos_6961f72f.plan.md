---
name: Completar Módulo de Gestão de Documentos
overview: "Completar o módulo de Gestão de Documentos com funcionalidades avançadas: histórico de versões, edição de metadados, visualização de documentos, melhor organização por pastas e filtros avançados."
todos:
  - id: edit-metadata
    content: Implementar edição de metadados de documentos (título, descrição, pasta, tipo, visibilidade)
    status: completed
  - id: document-viewer
    content: Implementar visualização de documentos (PDFs e imagens) diretamente no navegador
    status: completed
  - id: version-history
    content: Implementar sistema de histórico de versões de documentos
    status: completed
  - id: folder-management
    content: Implementar gestão completa de pastas (criar, renomear, eliminar)
    status: completed
  - id: advanced-filters
    content: Adicionar filtros avançados e busca de documentos
    status: completed
  - id: dashboard-integration
    content: Integrar documentos recentes nos dashboards
    status: completed
---

# Completar Módulo de Gestão de Documentos

## Objetivo

Completar e melhorar o módulo de Gestão de Documentos com funcionalidades avançadas que ainda estão faltando.

## Funcionalidades a Implementar

### 1. Histórico de Versões de Documentos

Implementar sistema de versões para documentos:

- **Arquivo**: `app/Controllers/DocumentController.php` (novos métodos)
- Upload de nova versão mantendo histórico
- Visualização de todas as versões de um documento
- Download de versões específicas
- Comparação entre versões

### 2. Edição de Metadados

Permitir edição de informações do documento sem reupload:

- **Arquivo**: `app/Controllers/DocumentController.php` (métodos edit/update)
- Editar título, descrição, pasta, tipo, visibilidade
- View para edição de metadados
- Validação de permissões

### 3. Visualização de Documentos

Visualizar documentos diretamente no navegador:

- **Arquivo**: `app/Controllers/DocumentController.php` (método view)
- Visualização inline de PDFs e imagens
- Preview de documentos antes do download
- Proteção de acesso baseada em visibilidade

### 4. Melhorias na Organização

Melhorar gestão de pastas e organização:

- **Arquivo**: `app/Controllers/DocumentController.php` (métodos de pastas)
- Criar/renomear/eliminar pastas
- Arrastar e soltar documentos entre pastas (via API)
- Visualização em árvore de pastas
- Busca avançada de documentos

### 5. Filtros e Busca Avançada

Adicionar filtros mais completos:

- **Arquivo**: `app/Views/pages/documents/index.html.twig`
- Filtro por tipo de documento
- Filtro por visibilidade
- Filtro por data de upload
- Busca por título/descrição
- Ordenação por diferentes critérios

### 6. Melhorias no FileStorageService

Expandir funcionalidades do serviço:

- **Arquivo**: `app/Services/FileStorageService.php`
- Validação de tipos MIME mais robusta
- Geração de thumbnails para imagens
- Compressão de imagens grandes
- Verificação de vírus (opcional, via integração futura)

### 7. Integração com Dashboard

Mostrar documentos recentes no dashboard:

- **Arquivo**: `app/Controllers/DashboardController.php`
- Lista de documentos recentes no dashboard do condómino
- Estatísticas de documentos no dashboard do admin

## Arquivos a Criar/Modificar

### Novos Arquivos

- `app/Views/pages/documents/edit.html.twig` - Editar metadados
- `app/Views/pages/documents/view.html.twig` - Visualizar documento
- `app/Views/pages/documents/versions.html.twig` - Histórico de versões
- `app/Views/pages/documents/manage-folders.html.twig` - Gestão de pastas

### Arquivos a Modificar

- `app/Controllers/DocumentController.php` - Adicionar métodos de versões, edição, visualização
- `app/Models/Document.php` - Adicionar métodos para versões e busca avançada
- `app/Services/FileStorageService.php` - Melhorar validação e adicionar thumbnails
- `app/Views/pages/documents/index.html.twig` - Adicionar filtros e busca
- `app/Views/pages/documents/create.html.twig` - Opção de upload como nova versão
- `app/Controllers/DashboardController.php` - Adicionar documentos recentes
- `routes.php` - Adicionar rotas para novas funcionalidades

## Prioridades

1. **Alta**: Edição de metadados de documentos
2. **Alta**: Visualização de documentos (PDFs e imagens)
3. **Média**: Histórico de versões de documentos
4. **Média**: Melhorias na organização (gestão de pastas)
5. **Média**: Filtros e busca avançada
6. **Baixa**: Thumbnails e compressão de imagens
7. **Baixa**: Integração com dashboard

## Notas Técnicas

- O sistema de versões usa os campos `version` e `parent_document_id` já existentes na tabela
- A visualização de PDFs deve usar PDF.js ou similar para segurança
- As pastas devem ser criadas dinamicamente e não precisam de tabela separada
- A busca deve usar FULLTEXT index se disponível no MySQL
- Os thumbnails devem ser gerados automaticamente para imagens