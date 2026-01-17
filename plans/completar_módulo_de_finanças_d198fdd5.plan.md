---
name: Completar Módulo de Finanças
overview: "Implementar funcionalidades faltantes no módulo de Finanças: geração automática de quotas via CLI, melhorias nos dashboards financeiros, relatórios avançados e notificações automáticas."
todos:
  - id: cli-generate-fees
    content: Criar comando CLI (cli/generate-fees.php) para geração automática mensal de quotas
    status: completed
  - id: improve-dashboard-financial
    content: Melhorar dashboard administrativo com estatísticas financeiras e gráficos
    status: completed
  - id: notifications-overdue
    content: Implementar sistema de notificações automáticas para quotas em atraso
    status: completed
  - id: advanced-reports
    content: Expandir relatórios financeiros com exportação Excel/PDF e mais análises
    status: completed
  - id: revenues-management
    content: Implementar CRUD completo de receitas além das quotas
    status: completed
  - id: improve-fees-ui
    content: Melhorar interface de gestão de quotas com filtros e ações em lote
    status: completed
---

# Completar Módulo de Finanças

## Objetivo

Completar e melhorar o módulo de Finanças com funcionalidades essenciais que ainda estão faltando ou podem ser melhoradas.

## Funcionalidades a Implementar

### 1. Sistema Automático de Geração de Quotas (CLI)

Criar comando CLI para gerar quotas automaticamente todos os meses:

- **Arquivo**: `cli/generate-fees.php`
- Gera quotas mensais automaticamente para todos os condomínios com orçamento aprovado
- Verifica se já existem quotas para o mês antes de gerar
- Pode ser executado via cron job mensalmente
- Log de operações realizadas

### 2. Melhorias no Dashboard Financeiro

Adicionar visualizações e estatísticas mais completas:

- **Arquivo**: `app/Controllers/DashboardController.php` (método `adminDashboard`)
- Gráficos de receitas vs despesas
- Lista de condóminos em atraso com valores
- Previsão de receitas do mês atual
- Indicadores de saúde financeira

### 3. Relatórios Financeiros Avançados

Expandir funcionalidades de relatórios:

- **Arquivo**: `app/Controllers/ReportController.php`
- Relatório de fluxo de caixa mensal/anual
- Relatório de inadimplência detalhado
- Exportação para Excel/PDF dos relatórios
- Comparativo de orçamento vs realizado

### 4. Notificações Automáticas de Quotas

Sistema de notificações para quotas em atraso:

- **Arquivo**: `app/Services/NotificationService.php` (expandir)
- Envio automático de emails para condóminos com quotas em atraso
- Comando CLI para verificar e enviar notificações diárias
- Template de email para notificação de quotas

### 5. Melhorias na Interface de Quotas

Melhorar UX da página de gestão de quotas:

- **Arquivo**: `app/Views/pages/finances/fees.html.twig`
- Filtros mais intuitivos
- Visualização em calendário de vencimentos
- Exportação de lista de quotas para Excel/PDF
- Ações em lote (marcar múltiplas quotas como pagas)

### 6. Receitas (Revenues)

Implementar gestão completa de receitas:

- **Arquivo**: `app/Controllers/FinanceController.php` (novos métodos)
- CRUD de receitas além das quotas
- Categorização de receitas
- Relatório de receitas por período

## Arquivos a Criar/Modificar

### Novos Arquivos

- `cli/generate-fees.php` - Comando CLI para geração automática
- `cli/notify-overdue-fees.php` - Comando CLI para notificações
- `app/Views/pages/finances/revenues.html.twig` - Página de receitas
- `app/Views/pages/finances/reports-advanced.html.twig` - Relatórios avançados

### Arquivos a Modificar

- `app/Controllers/DashboardController.php` - Adicionar estatísticas financeiras
- `app/Controllers/FinanceController.php` - Adicionar métodos de receitas
- `app/Controllers/ReportController.php` - Expandir relatórios
- `app/Services/NotificationService.php` - Adicionar notificações de quotas
- `app/Views/pages/dashboard/admin.html.twig` - Melhorar visualizações
- `app/Views/pages/finances/fees.html.twig` - Melhorar interface
- `app/Views/pages/finances/index.html.twig` - Adicionar resumo financeiro

## Prioridades

1. **Alta**: Sistema automático de geração de quotas (CLI)
2. **Alta**: Melhorias no dashboard financeiro
3. **Média**: Notificações automáticas de quotas em atraso
4. **Média**: Relatórios financeiros avançados
5. **Baixa**: Gestão de receitas (além de quotas)
6. **Baixa**: Melhorias na interface de quotas

## Notas Técnicas

- Os comandos CLI devem ser executáveis via cron jobs
- As notificações devem usar o EmailService existente
- Os relatórios devem suportar exportação para Excel usando biblioteca PHPExcel ou similar
- As melhorias no dashboard devem usar gráficos (Chart.js ou similar)