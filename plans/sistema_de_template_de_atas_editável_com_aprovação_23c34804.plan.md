---
name: Sistema de Template de Atas Editável com Aprovação
overview: Implementar sistema de template de atas editável que é gerado automaticamente quando uma assembleia é encerrada, permitindo ao admin editar o conteúdo usando um editor WYSIWYG. Quando a ata for aprovada, gera automaticamente o PDF final e salva nos documentos do condomínio.
todos:
  - id: migration_assembly_id
    content: Criar migration para adicionar coluna assembly_id e status à tabela documents
    status: pending
  - id: update_document_model
    content: Atualizar Document model para suportar assembly_id e status, adicionar método getByAssemblyId()
    status: pending
    dependencies:
      - migration_assembly_id
  - id: create_template_generator
    content: Criar método generateMinutesTemplate() em AssemblyController para gerar template automaticamente
    status: pending
    dependencies:
      - update_document_model
  - id: modify_close_method
    content: Modificar método close() para chamar generateMinutesTemplate() automaticamente
    status: pending
    dependencies:
      - create_template_generator
  - id: create_edit_methods
    content: Criar métodos editMinutesTemplate() e updateMinutesTemplate() em AssemblyController
    status: pending
    dependencies:
      - create_template_generator
  - id: create_approve_method
    content: Criar método approveMinutes() que gera PDF e salva nos documentos quando aprovado
    status: pending
    dependencies:
      - create_edit_methods
  - id: create_pdf_generator
    content: Criar método generateMinutesPdf() em PdfService para gerar PDF final
    status: pending
    dependencies:
      - create_approve_method
  - id: modify_generate_minutes
    content: Modificar generateMinutes() para usar template editado se existir
    status: pending
    dependencies:
      - create_edit_methods
  - id: create_edit_view
    content: Criar view edit-minutes-template.html.twig com editor WYSIWYG e botão de aprovação
    status: pending
    dependencies:
      - create_edit_methods
  - id: update_show_view
    content: Atualizar show.html.twig para adicionar botão de editar template e mostrar status de aprovação
    status: pending
    dependencies:
      - create_edit_view
  - id: add_routes
    content: Adicionar rotas para edição e aprovação de template em routes.php
    status: pending
    dependencies:
      - create_approve_method
  - id: integrate_wysiwyg
    content: Integrar editor WYSIWYG (TinyMCE) na view de edição
    status: pending
    dependencies:
      - create_edit_view
---

# Sistema de Template de Atas Editável com Aprovação

## Objetivo

Criar um sistema onde, ao encerrar uma assembleia, um template de ata é gerado automaticamente e armazenado como documento. O admin pode editar este template usando um editor WYSIWYG. Quando a ata for aprovada, gera automaticamente o PDF final e salva nos documentos do condomínio.

## Arquitetura

```mermaid
flowchart TD
    A[Assembleia Encerrada] --> B[Gerar Template Automático]
    B --> C[Armazenar como Documento Template]
    C --> D[Admin Visualiza Template]
    D --> E[Admin Edita com WYSIWYG]
    E --> F[Salvar Alterações]
    F --> G[Admin Aprova Ata]
    G --> H[Gerar PDF Final]
    H --> I[Salvar PDF nos Documentos]
    I --> J[Disponível para Condôminos]
    
    B --> K[Popular com Dados]
    K --> L[Informações da Assembleia]
    K --> M[Lista de Presenças]
    K --> N[Tópicos de Votação]
    K --> O[Resultados das Votações]
```

## Implementação

### 1. Modificar método `close` em `AssemblyController`

- Após encerrar a assembleia (`status = 'closed'`), gerar automaticamente o template de ata
- Chamar novo método `generateMinutesTemplate` que cria o documento editável
- Armazenar o documento com `document_type = 'minutes_template'` para diferenciar de atas finais

### 2. Criar método `generateMinutesTemplate` em `AssemblyController`

- Buscar dados da assembleia, presenças, tópicos e resultados de votação
- Gerar HTML template usando `PdfService::getMinutesHtml` como base
- Criar documento no sistema com:
  - `title`: "Template de Atas: [Título da Assembleia]"
  - `document_type`: "minutes_template"
  - `file_path`: caminho do arquivo HTML
  - `visibility`: "admin" (apenas admin pode editar)
  - `assembly_id`: ID da assembleia relacionada
- Retornar ID do documento criado

### 3. Criar método `editMinutesTemplate` em `AssemblyController`

- Verificar se assembleia está encerrada
- Buscar documento do template (`document_type = 'minutes_template'` e `assembly_id` relacionado)
- Carregar conteúdo HTML do arquivo
- Renderizar view de edição com editor WYSIWYG

### 4. Criar método `updateMinutesTemplate` em `AssemblyController`

- Validar CSRF token
- Verificar permissões (apenas admin)
- Atualizar conteúdo HTML do arquivo
- Opcionalmente atualizar metadata do documento
- Redirecionar com mensagem de sucesso

### 5. Criar método `approveMinutes` em `AssemblyController`

- Validar CSRF token
- Verificar permissões (apenas admin)
- Buscar template editado (`document_type = 'minutes_template'`)
- Gerar PDF final usando conteúdo do template
- Salvar PDF nos documentos do condomínio com:
  - `title`: "Atas Aprovadas: [Título da Assembleia]"
  - `document_type`: "minutes"
  - `file_path`: caminho do arquivo PDF
  - `visibility`: "condominos" (visível para todos)
  - `assembly_id`: ID da assembleia relacionada
- Marcar template como aprovado (adicionar campo `status = 'approved'` ou flag)
- Redirecionar com mensagem de sucesso

### 6. Modificar método `generateMinutes` em `AssemblyController`

- Verificar se existe template editado (`document_type = 'minutes_template'`)
- Se existir, usar conteúdo do template editado
- Se não existir, usar geração automática padrão
- Gerar PDF/HTML final usando conteúdo escolhido
- Salvar nos documentos do condomínio

### 7. Adicionar campo `assembly_id` na tabela `documents` (migration)

- Criar migration para adicionar coluna `assembly_id INT NULL` com foreign key
- Adicionar coluna `status` ENUM('draft', 'approved') DEFAULT 'draft' para controlar aprovação
- Permitir relacionar documentos com assembleias
- Adicionar índice para melhor performance

### 8. Criar view `edit-minutes-template.html.twig`

- Formulário com editor WYSIWYG (TinyMCE ou CKEditor)
- Campo hidden para CSRF token
- Botão "Guardar" e "Cancelar"
- Botão "Aprovar e Gerar PDF" que chama `approveMinutes`
- Preview do template (opcional)
- Instruções de uso do editor
- Indicador de status (rascunho/aprovado)

### 9. Integrar editor WYSIWYG

- Adicionar TinyMCE via CDN ou CKEditor
- Configurar toolbar com formatação básica (negrito, itálico, listas, etc.)
- Permitir edição de HTML completo
- Sanitizar HTML na submissão para segurança

### 10. Atualizar view `show.html.twig` de assembleias

- Adicionar botão "Editar Template de Atas" quando assembleia estiver encerrada
- Mostrar link para visualizar template se existir
- Mostrar link para PDF aprovado se existir
- Indicar se template foi editado ou não
- Indicar se ata foi aprovada ou está em rascunho

### 11. Adicionar método de geração de PDF em `PdfService`

- Criar método `generateMinutesPdf()` que converte HTML para PDF
- Usar biblioteca como TCPDF, DomPDF ou mPDF
- Ou manter HTML e usar conversão no navegador (print to PDF)

### 12. Adicionar rotas em `routes.php`

- `GET /condominiums/{condominium_id}/assemblies/{id}/minutes-template/edit` → `editMinutesTemplate`
- `POST /condominiums/{condominium_id}/assemblies/{id}/minutes-template/update` → `updateMinutesTemplate`
- `POST /condominiums/{condominium_id}/assemblies/{id}/minutes-template/approve` → `approveMinutes`

## Arquivos a Modificar

1. `app/Controllers/AssemblyController.php`

   - Modificar `close()` para gerar template automaticamente
   - Adicionar `generateMinutesTemplate()`
   - Adicionar `editMinutesTemplate()`
   - Adicionar `updateMinutesTemplate()`
   - Adicionar `approveMinutes()` - NOVO
   - Modificar `generateMinutes()` para usar template editado se existir

2. `app/Models/Document.php`

   - Adicionar método `getByAssemblyId()` para buscar documentos relacionados
   - Adicionar suporte para `assembly_id` em queries
   - Adicionar suporte para `status` em queries

3. `app/Services/PdfService.php`

   - Criar método `getMinutesTemplateHtml()` que retorna HTML editável (sem formatação final de PDF)
   - Criar método `generateMinutesPdf()` para gerar PDF final - NOVO
   - Manter `getMinutesHtml()` para geração final

4. `app/Views/pages/assemblies/show.html.twig`

   - Adicionar botão para editar template quando assembleia estiver encerrada
   - Mostrar status do template (editado/não editado, aprovado/rascunho)
   - Mostrar link para PDF aprovado se existir

5. `app/Views/pages/assemblies/edit-minutes-template.html.twig` (NOVO)

   - Formulário com editor WYSIWYG
   - Campos para edição do conteúdo
   - Botão "Aprovar e Gerar PDF"

6. `database/migrations/041_add_assembly_id_to_documents.php` (NOVO)

   - Adicionar coluna `assembly_id` à tabela `documents`
   - Adicionar coluna `status` ENUM('draft', 'approved') DEFAULT 'draft'

7. `routes.php`

   - Adicionar rotas para edição de template
   - Adicionar rota para aprovação

## Considerações de Segurança

- Validar que apenas admins podem editar e aprovar templates
- Sanitizar HTML usando `Security::sanitize()` ou biblioteca HTMLPurifier
- Validar CSRF tokens em todas as submissões
- Verificar que assembleia pertence ao condomínio correto
- Validar que apenas templates aprovados podem ser visualizados por condôminos

## Dependências

- Editor WYSIWYG (TinyMCE via CDN recomendado por simplicidade)
- Biblioteca de sanitização HTML (opcional, usar `htmlspecialchars` básico ou HTMLPurifier)
- Biblioteca de geração de PDF (TCPDF, DomPDF ou mPDF) - se necessário

## Fluxo de Uso

1. Admin encerra assembleia → Template é gerado automaticamente (status: draft)
2. Admin clica em "Editar Template de Atas"
3. Editor WYSIWYG abre com conteúdo pré-populado
4. Admin faz alterações necessárias
5. Admin salva → Template é atualizado (mantém status: draft)
6. Admin clica em "Aprovar e Gerar PDF"
7. Sistema gera PDF final usando template editado
8. PDF é salvo nos documentos do condomínio (visível para condôminos)
9. Template é marcado como aprovado (status: approved)
10. Condôminos podem visualizar e baixar PDF aprovado