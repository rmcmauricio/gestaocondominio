---
name: Unificar Notificações, Anexos e Organização de Ficheiros
overview: Implementar unificação de notificações com mensagens, adicionar suporte a anexos em mensagens e ocorrências, e reorganizar ficheiros por condomínio incluindo recibos.
todos:
  - id: unify_notifications
    content: Modificar NotificationService para unificar notificações e mensagens não lidas
    status: pending
  - id: update_notification_controller
    content: Atualizar NotificationController para exibir lista unificada
    status: pending
    dependencies:
      - unify_notifications
  - id: create_attachment_tables
    content: Criar tabelas message_attachments e occurrence_attachments
    status: pending
  - id: create_attachment_models
    content: Criar modelos MessageAttachment e OccurrenceAttachment
    status: pending
    dependencies:
      - create_attachment_tables
  - id: update_file_storage
    content: Atualizar FileStorageService para organizar por condomínio/tipo
    status: pending
  - id: add_message_attachments
    content: Adicionar suporte a anexos em MessageController e views
    status: pending
    dependencies:
      - create_attachment_models
      - update_file_storage
  - id: integrate_html_editor
    content: Integrar editor TinyMCE nas mensagens com suporte a formatação HTML
    status: pending
    dependencies: []
  - id: add_inline_image_upload
    content: Implementar upload de imagens inline no editor com endpoint dedicado
    status: pending
    dependencies:
      - integrate_html_editor
      - update_file_storage
  - id: add_occurrence_attachments
    content: Adicionar suporte a anexos em OccurrenceController (se necessário)
    status: pending
    dependencies:
      - create_attachment_models
      - update_file_storage
  - id: reorganize_receipts
    content: Reorganizar recibos por condomínio em PdfService e ReceiptController
    status: pending
    dependencies:
      - update_file_storage
  - id: update_demo_seeder
    content: Atualizar DemoSeeder para usar nova estrutura de recibos
    status: pending
    dependencies:
      - reorganize_receipts
  - id: add_attachment_routes
    content: Adicionar rotas para upload/download de anexos
    status: pending
    dependencies:
      - add_message_attachments
      - add_occurrence_attachments
---

# Plano: Unificar Notificações, Anexos e Organização de Ficheiros

## 1. Unificar Notificações e Mensagens

### 1.1 Modificar NotificationService

- **Arquivo**: `app/Services/NotificationService.php`
- Adicionar método `getUnifiedNotifications()` que combina:
  - Notificações do sistema (tabela `notifications`)
  - Mensagens não lidas (tabela `messages` com `is_read = FALSE`)
- Criar notificações automáticas quando novas mensagens são recebidas

### 1.2 Atualizar NotificationController

- **Arquivo**: `app/Controllers/NotificationController.php`
- Modificar `index()` para usar `getUnifiedNotifications()`
- Adicionar tipo de notificação `message` para diferenciar mensagens de outras notificações
- Ao clicar em notificação de mensagem, redirecionar para página de mensagens

### 1.3 Atualizar Controller Base

- **Arquivo**: `app/Core/Controller.php`
- Manter contador de mensagens não lidas separado (`unread_messages_count`)
- Contador de notificações unificadas (`unread_notifications_count`) inclui mensagens

### 1.4 Atualizar Header

- **Arquivo**: `app/Views/blocks/header.html.twig`
- Manter ícone de mensagens (redireciona para `/condominiums/{id}/messages`)
- Ícone de notificações mostra lista unificada

## 2. Anexos em Mensagens e Ocorrências

### 2.1 Criar Tabelas de Anexos

- **Arquivo**: `database/migrations/051_create_message_attachments_table.php`
- Campos: `id`, `message_id`, `condominium_id`, `file_path`, `file_name`, `file_size`, `mime_type`, `uploaded_by`, `created_at`
- **Arquivo**: `database/migrations/052_create_occurrence_attachments_table.php` (se não existir)
- Campos similares com `occurrence_id` em vez de `message_id`

### 2.2 Criar Modelos de Anexos

- **Arquivo**: `app/Models/MessageAttachment.php`
- Métodos: `create()`, `getByMessage()`, `delete()`
- **Arquivo**: `app/Models/OccurrenceAttachment.php` (se necessário)
- Métodos similares

### 2.3 Atualizar FileStorageService

- **Arquivo**: `app/Services/FileStorageService.php`
- Modificar método `upload()` para aceitar tipo (`messages`, `occurrences`, `receipts`)
- Criar estrutura: `storage/condominiums/{condominium_id}/{type}/year/month/`
- Retornar caminho relativo: `condominiums/{id}/{type}/year/month/filename`

### 2.4 Atualizar MessageController

- **Arquivo**: `app/Controllers/MessageController.php`
- Adicionar suporte a upload de anexos em `store()` e `reply()`
- Método `uploadAttachment()` para processar uploads
- Exibir anexos em `show()`

### 2.5 Atualizar OccurrenceController

- **Arquivo**: `app/Controllers/OccurrenceController.php`
- Adicionar suporte a upload de anexos (se ainda não existir)
- Usar mesma estrutura de pastas por condomínio

### 2.6 Integrar Editor HTML nas Mensagens

- **Arquivo**: `app/Views/pages/messages/create.html.twig`
- Substituir `<textarea>` por editor TinyMCE (já usado no projeto)
- Configurar plugins: `image`, `media`, `link`, `code`, `fullscreen`
- Adicionar toolbar completa com formatação de texto
- **Arquivo**: `app/Views/pages/messages/show.html.twig`
- Exibir conteúdo HTML formatado usando `|raw` filter
- **Arquivo**: `app/Views/pages/messages/reply.html.twig` (formulário de resposta)
- Usar mesmo editor HTML

### 2.7 Upload de Imagens Inline no Editor

- **Arquivo**: `app/Controllers/MessageController.php`
- Criar método `uploadInlineImage()` para processar uploads de imagens do editor
- Retornar JSON com URL da imagem para inserção no editor
- Armazenar em `storage/condominiums/{id}/messages/inline/{year}/{month}/`
- **Arquivo**: `routes.php`
- Adicionar rota: `POST /condominiums/{id}/messages/upload-image`
- **Arquivo**: `app/Views/pages/messages/create.html.twig`
- Configurar TinyMCE com `images_upload_handler` para upload automático
- Configurar `images_upload_url` apontando para endpoint de upload

### 2.8 Atualizar Views de Anexos

- **Arquivo**: `app/Views/pages/messages/create.html.twig`
- Adicionar campo de upload de ficheiros (múltiplos) além do editor
- **Arquivo**: `app/Views/pages/messages/show.html.twig`
- Exibir lista de anexos com links para download (separado do conteúdo HTML)
- **Arquivo**: `app/Views/pages/occurrences/create.html.twig` e `show.html.twig`
- Adicionar suporte a anexos se não existir

## 3. Reorganizar Recibos por Condomínio

### 3.1 Atualizar PdfService

- **Arquivo**: `app/Services/PdfService.php`
- Modificar `generateReceiptPdf()`:
  - Mudar de `storage/documents/receipts/` para `storage/condominiums/{condominium_id}/receipts/`
  - Criar estrutura de pastas: `condominiums/{id}/receipts/year/month/`
  - Retornar caminho relativo: `condominiums/{id}/receipts/year/month/receipt_{id}_{timestamp}.pdf`

### 3.2 Atualizar ReceiptController

- **Arquivo**: `app/Controllers/ReceiptController.php`
- Modificar `download()` para usar novo caminho
- Atualizar lógica de leitura de ficheiros

### 3.3 Atualizar Demo Seeder

- **Arquivo**: `database/seeders/DemoSeeder.php`
- Atualizar `createReceiptsForDemoPayments()`:
  - Usar novo caminho de armazenamento
  - Criar estrutura de pastas por condomínio
  - Mover ficheiros existentes se necessário

### 3.4 Migração de Ficheiros Existentes

- Criar script de migração (opcional):
  - **Arquivo**: `cli/migrate-receipts.php`
  - Mover ficheiros de `storage/documents/receipts/` para `storage/condominiums/{id}/receipts/`
  - Atualizar registos na base de dados

## 4. Estrutura de Pastas Final

```
storage/
├── condominiums/
│   ├── {condominium_id}/
│   │   ├── messages/
│   │   │   ├── inline/
│   │   │   │   └── {year}/{month}/  (imagens inline do editor)
│   │   │   └── attachments/
│   │   │       └── {year}/{month}/  (anexos de ficheiros)
│   │   ├── occurrences/
│   │   │   └── {year}/{month}/
│   │   └── receipts/
│   │       └── {year}/{month}/
```

## 5. Rotas Adicionais

- **Arquivo**: `routes.php`
- Adicionar rotas para upload/download:
  - `POST /condominiums/{id}/messages/upload-image` (upload de imagem inline)
  - `POST /condominiums/{id}/messages/{message_id}/attachments` (upload de anexos)
  - `GET /condominiums/{id}/messages/{message_id}/attachments/{attachment_id}/download`
  - Similar para ocorrências

## 6. Validações e Segurança

- Validar tipos MIME permitidos (imagens, PDFs, documentos)
- Limitar tamanho de ficheiros (ex: 10MB por ficheiro, 2MB para imagens inline)
- Verificar permissões de acesso ao condomínio
- Sanitizar nomes de ficheiros
- Sanitizar HTML do editor (usar `htmlspecialchars` ou biblioteca de sanitização)
- Validar que imagens inline são realmente imagens (não scripts disfarçados)

## 7. Atualizações na Interface

- Adicionar preview de imagens em mensagens/ocorrências
- Mostrar ícones por tipo de ficheiro
- Adicionar indicador de progresso no upload
- Permitir remoção de anexos (apenas pelo autor/admin)
- Editor HTML com toolbar completa (formatação, listas, links, imagens)
- Preview em tempo real do conteúdo formatado
- Suporte a inserção de imagens via drag-and-drop ou botão de upload