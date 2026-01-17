---
name: Completar Sistema de Ocorrências
overview: "Completar e melhorar o sistema de ocorrências com funcionalidades avançadas: sistema de comentários, histórico de mudanças, filtros avançados, busca, visualização de anexos, estatísticas e relatórios."
todos:
  - id: occurrence-comments
    content: Implementar sistema de comentários em ocorrências (migration, model, controller, views)
    status: completed
  - id: occurrence-history
    content: Implementar histórico de mudanças de ocorrências (migration, model, controller, views)
    status: completed
  - id: advanced-filters-occurrences
    content: Adicionar filtros avançados e busca de ocorrências
    status: completed
  - id: attachment-management
    content: Melhorar visualização e gestão de anexos de ocorrências
    status: completed
  - id: occurrence-statistics
    content: Adicionar estatísticas de ocorrências no dashboard
    status: completed
  - id: occurrence-reports
    content: Implementar relatórios de ocorrências com exportação
    status: completed
---

# Completar Sistema de Ocorrências

## Objetivo

Completar e melhorar o sistema de ocorrências com funcionalidades avançadas que ainda estão faltando, incluindo sistema de comentários, histórico de mudanças, filtros avançados, busca, visualização de anexos, estatísticas e relatórios.

## Funcionalidades a Implementar

### 1. Sistema de Comentários

Permitir que utilizadores adicionem comentários/atualizações nas ocorrências:

- **Arquivo**: Criar `app/Models/OccurrenceComment.php`
- **Arquivo**: Adicionar métodos em `app/Controllers/OccurrenceController.php`
- Tabela `occurrence_comments` (criar migration)
- Adicionar comentários com timestamps
- Editar/eliminar comentários próprios
- Notificações quando novos comentários são adicionados

### 2. Histórico de Mudanças

Registar todas as alterações feitas na ocorrência:

- **Arquivo**: Criar `app/Models/OccurrenceHistory.php`
- **Arquivo**: Adicionar métodos em `app/Controllers/OccurrenceController.php`
- Tabela `occurrence_history` (criar migration)
- Registar mudanças de status, atribuições, atualizações
- Visualizar timeline de eventos
- Mostrar quem fez cada alteração e quando

### 3. Filtros Avançados e Busca

Melhorar a interface de filtros e adicionar busca:

- **Arquivo**: `app/Views/pages/occurrences/index.html.twig`
- **Arquivo**: `app/Models/Occurrence.php` (método `search`)
- Filtros por categoria, prioridade, fração, data, atribuído a
- Busca por título/descrição
- Ordenação por diferentes critérios
- Filtros combinados

### 4. Visualização de Anexos

Melhorar gestão e visualização de anexos:

- **Arquivo**: `app/Views/pages/occurrences/show.html.twig`
- **Arquivo**: Adicionar método `downloadAttachment` em `app/Controllers/OccurrenceController.php`
- Lista de anexos com preview
- Download individual de anexos
- Visualização inline de imagens
- Eliminar anexos

### 5. Estatísticas e Dashboard

Adicionar estatísticas de ocorrências:

- **Arquivo**: `app/Controllers/DashboardController.php`
- **Arquivo**: `app/Views/pages/dashboard/admin.html.twig`
- Estatísticas por status, prioridade, categoria
- Gráficos de ocorrências ao longo do tempo
- Ocorrências recentes no dashboard
- Métricas de tempo médio de resolução

### 6. Relatórios de Ocorrências

Gerar relatórios de ocorrências:

- **Arquivo**: `app/Controllers/ReportController.php`
- **Arquivo**: `app/Services/ReportService.php`
- Relatório por período, status, categoria
- Exportação para Excel/CSV
- Relatório de ocorrências por fornecedor
- Relatório de tempo de resolução

## Arquivos a Criar/Modificar

### Novos Arquivos

- `database/migrations/029_create_occurrence_comments_table.php` - Tabela de comentários
- `database/migrations/030_create_occurrence_history_table.php` - Tabela de histórico
- `app/Models/OccurrenceComment.php` - Model para comentários
- `app/Models/OccurrenceHistory.php` - Model para histórico
- `app/Views/pages/occurrences/comments.html.twig` - Seção de comentários (pode ser incluída em show.html.twig)

### Arquivos a Modificar

- `app/Controllers/OccurrenceController.php` - Adicionar métodos para comentários, histórico, busca, anexos
- `app/Models/Occurrence.php` - Adicionar métodos de busca e estatísticas
- `app/Views/pages/occurrences/index.html.twig` - Adicionar filtros avançados e busca
- `app/Views/pages/occurrences/show.html.twig` - Adicionar seção de comentários, histórico e anexos
- `app/Controllers/DashboardController.php` - Adicionar estatísticas de ocorrências
- `app/Views/pages/dashboard/admin.html.twig` - Mostrar estatísticas de ocorrências
- `app/Controllers/ReportController.php` - Adicionar relatórios de ocorrências
- `app/Services/ReportService.php` - Adicionar geração de dados de relatórios
- `routes.php` - Adicionar rotas para comentários, histórico, anexos

## Prioridades

1. **Alta**: Sistema de comentários
2. **Alta**: Histórico de mudanças
3. **Alta**: Filtros avançados e busca
4. **Média**: Visualização de anexos
5. **Média**: Estatísticas no dashboard
6. **Baixa**: Relatórios de ocorrências

## Notas Técnicas

- Os comentários devem ter timestamps e referência ao utilizador
- O histórico deve registar automaticamente todas as mudanças importantes
- Os filtros devem usar query parameters e manter estado na URL
- Os anexos já estão armazenados em JSON, precisa de melhorar a visualização
- As estatísticas devem ser calculadas eficientemente usando queries agregadas
- Os relatórios devem seguir o mesmo padrão dos relatórios financeiros existentes