---
name: Sistema de Geração de Quotas com Múltiplos Meses e Quotas Extras
overview: Implementar sistema para gerar quotas em múltiplos meses de uma vez e permitir criação de quotas extras (para obras ou outros motivos), com visualização no mapa de quotas.
todos:
  - id: migration_fee_type
    content: Criar migration para adicionar campo fee_type à tabela fees
    status: completed
  - id: update_fee_model
    content: Atualizar Fee model para suportar fee_type e métodos para buscar quotas por mês/fração
    status: completed
    dependencies:
      - migration_fee_type
  - id: update_fee_service_multiple
    content: Modificar FeeService para gerar quotas em múltiplos meses de uma vez
    status: completed
    dependencies:
      - update_fee_model
  - id: update_fee_service_extra
    content: Adicionar método generateExtraFee() no FeeService para criar quotas extras
    status: completed
    dependencies:
      - update_fee_model
  - id: update_controller_generate
    content: Atualizar FinanceController@generateFees para processar array de meses e quotas extras
    status: completed
    dependencies:
      - update_fee_service_multiple
      - update_fee_service_extra
  - id: update_controller_fees_map
    content: Atualizar FinanceController@fees para somar quotas do mês no fees_map
    status: completed
    dependencies:
      - update_fee_model
  - id: update_modal_multiple_months
    content: Modificar modal de gerar quotas para permitir seleção múltipla de meses com checkboxes
    status: completed
  - id: update_modal_extra_fees
    content: Adicionar funcionalidade de quota extra no modal (checkbox, campos de valor e descrição)
    status: completed
    dependencies:
      - update_modal_multiple_months
  - id: update_fees_map_display
    content: Atualizar fees-map.html.twig para somar e exibir todas as quotas do mês (regulares + extras)
    status: completed
    dependencies:
      - update_controller_fees_map
  - id: test_generation
    content: Testar geração de quotas em múltiplos meses e criação de quotas extras
    status: completed
    dependencies:
      - update_controller_generate
      - update_modal_extra_fees
      - update_fees_map_display
---

# Sistema de Geração de Quotas com Múltiplos Meses e Quotas Extras

## Objetivos

1. Permitir seleção múltipla de meses no modal de gerar quotas
2. Adicionar suporte para quotas extras com valor manual e descrição
3. Atualizar mapa de quotas para somar todas as quotas do mês (regulares + extras)

## Mudanças no Banco de Dados

### Migration: Adicionar campo `fee_type` à tabela `fees`

- Criar migration `055_add_fee_type_to_fees.php`
- Adicionar coluna `fee_type ENUM('regular', 'extra') DEFAULT 'regular'`
- Adicionar índice para melhor performance em queries

## Mudanças no Backend

### 1. Model Fee (`app/Models/Fee.php`)

- Atualizar método `create()` para aceitar `fee_type`
- Adicionar método `getByMonthAndFraction()` para buscar todas as quotas de um mês/fração
- Atualizar queries para considerar `fee_type`

### 2. Service FeeService (`app/Services/FeeService.php`)

- Modificar `generateMonthlyFees()` para aceitar array de meses
- Criar método `generateExtraFee()` para criar quotas extras
- Atualizar lógica de verificação de quotas existentes para considerar apenas quotas regulares (evitar duplicar extras)

### 3. Controller FinanceController (`app/Controllers/FinanceController.php`)

- Modificar `generateFees()` para processar múltiplos meses
- Adicionar lógica para detectar se é quota extra (campo `is_extra` no POST)
- Criar método `createExtraFee()` para criar quotas extras individuais
- Atualizar método `fees()` para incluir quotas extras no cálculo do mapa

## Mudanças no Frontend

### 1. Modal de Gerar Quotas (`app/Views/pages/finances/fees.html.twig`)

- Substituir dropdown de mês único por checkboxes para seleção múltipla
- Adicionar checkbox "Quota Extra" que ao marcar:
  - Mostra campo de valor manual
  - Mostra campo de descrição/motivo
  - Permite selecionar frações específicas (ou todas)
- Atualizar formulário para enviar array de meses (`months[]`)
- Adicionar validação JavaScript para garantir pelo menos um mês selecionado

### 2. Mapa de Quotas (`app/Views/blocks/fees-map.html.twig`)

- Modificar lógica de cálculo para somar todas as quotas do mês (regulares + extras)
- Atualizar tooltip para mostrar detalhes de todas as quotas quando houver múltiplas
- Adicionar indicador visual se houver quotas extras no mês (ex: badge "Extra")

### 3. Controller - Preparação de Dados (`app/Controllers/FinanceController.php`)

- No método `fees()`, atualizar query do `fees_map` para:
  - Agrupar por mês e fração
  - Somar valores de todas as quotas (regulares + extras)
  - Incluir informação sobre presença de quotas extras
  - Manter referência à quota principal para o modal de detalhes

## Fluxo de Geração

### Quotas Regulares (Múltiplos Meses)

1. Usuário seleciona múltiplos meses no modal
2. Sistema gera quotas para todos os meses selecionados
3. Cada quota usa cálculo baseado no orçamento

### Quotas Extras

1. Usuário marca checkbox "Quota Extra"
2. Sistema mostra campos adicionais (valor, descrição, seleção de frações)
3. Usuário define valor manual e motivo
4. Sistema cria quotas extras para frações selecionadas no(s) mês(es) escolhido(s)

## Estrutura de Dados

### POST Request para Gerar Quotas

```php
[
    'year' => 2025,
    'months' => [1, 2, 3],  // Array de meses
    'is_extra' => false,     // true para quota extra
    'extra_amount' => null,  // Valor manual se for extra
    'extra_description' => null, // Motivo se for extra
    'extra_fractions' => []  // IDs das frações (vazio = todas)
]
```

## Considerações Técnicas

1. **Validação**: Verificar se já existem quotas regulares antes de gerar (evitar duplicação)
2. **Quotas Extras**: Podem coexistir com quotas regulares no mesmo mês
3. **Referência**: Quotas extras devem ter referência diferente (ex: Q052-513-202501-E)
4. **Mapa de Quotas**: Deve mostrar soma total quando houver múltiplas quotas

## Arquivos a Modificar

- `database/migrations/055_add_fee_type_to_fees.php` (novo)
- `app/Models/Fee.php`
- `app/Services/FeeService.php`
- `app/Controllers/FinanceController.php`
- `app/Views/pages/finances/fees.html.twig`
- `app/Views/blocks/fees-map.html.twig`
- `routes.php` (se necessário adicionar rota para quota extra)

## Ordem de Implementação

1. Criar migration e executar
2. Atualizar Model Fee
3. Atualizar FeeService para múltiplos meses
4. Atualizar Controller para processar múltiplos meses
5. Atualizar modal para seleção múltipla
6. Implementar funcionalidade de quotas extras
7. Atualizar mapa de quotas para somar valores
8. Testar geração e visualização