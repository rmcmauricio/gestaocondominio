---
name: Sistema Financeiro Completo
overview: Implementar sistema completo de gestão financeira com contas bancárias, movimentos financeiros (entradas/saídas), caixa físico, e associação obrigatória de pagamentos de quotas a movimentos financeiros. Incluir visualização de saldos atuais.
todos:
  - id: "1"
    content: Criar migration 043 para tabela bank_accounts
    status: completed
  - id: "2"
    content: Criar migration 044 para tabela financial_transactions
    status: completed
  - id: "3"
    content: Criar migration 045 para adicionar financial_transaction_id a fee_payments e criar movimentos retroativos
    status: completed
    dependencies:
      - "1"
      - "2"
  - id: "4"
    content: Criar modelo BankAccount com métodos CRUD e cálculo de saldos
    status: completed
    dependencies:
      - "1"
  - id: "5"
    content: Criar modelo FinancialTransaction com métodos CRUD e associações
    status: completed
    dependencies:
      - "2"
  - id: "6"
    content: Criar BankAccountController com todas as ações CRUD
    status: completed
    dependencies:
      - "4"
  - id: "7"
    content: Criar FinancialTransactionController com todas as ações CRUD
    status: completed
    dependencies:
      - "5"
  - id: "8"
    content: Modificar FinanceController->addPayment() para criar/associar movimentos
    status: completed
    dependencies:
      - "5"
  - id: "9"
    content: Criar views para gestão de contas bancárias (index, create, edit)
    status: completed
    dependencies:
      - "6"
  - id: "10"
    content: Criar views para gestão de movimentos financeiros (index, create, edit)
    status: completed
    dependencies:
      - "7"
  - id: "11"
    content: Modificar view de quotas para incluir seleção de movimento financeiro
    status: completed
    dependencies:
      - "7"
  - id: "12"
    content: Adicionar card de saldos atuais na página de finanças
    status: completed
    dependencies:
      - "4"
      - "5"
  - id: "13"
    content: Adicionar rotas para contas bancárias e movimentos financeiros
    status: completed
    dependencies:
      - "6"
      - "7"
  - id: "14"
    content: Implementar validações e regras de negócio (saldo, integridade)
    status: in_progress
    dependencies:
      - "4"
      - "5"
---

# Sistema Financeiro Completo

## Arquitetura

O sistema financeiro será composto por:

- **Contas Bancárias**: Contas bancárias e caixa físico (conta especial)
- **Movimentos Financeiros**: Entradas e saídas de dinheiro associadas a contas
- **Associação Obrigatória**: Pagamentos de quotas devem ter movimento financeiro associado
- **Saldos em Tempo Real**: Cálculo automático de saldos por conta

## Estrutura de Dados

### 1. Tabela `bank_accounts`

- Armazena contas bancárias e caixa físico
- Campos: `id`, `condominium_id`, `name` (obrigatório - nome de identificação), `account_type` (bank/cash), `bank_name`, `account_number`, `iban` (obrigatório para contas bancárias), `swift` (obrigatório para contas bancárias), `initial_balance`, `current_balance`, `is_active`, `created_at`, `updated_at`
- **Contas bancárias** (`account_type = 'bank'`): Devem ter `name`, `iban` e `swift` obrigatórios. Podem ter `bank_name` e `account_number` opcionais.
- **Caixa físico** (`account_type = 'cash'`): Apenas precisa de `name`. Não requer IBAN, SWIFT ou outros dados bancários.

### 2. Tabela `financial_transactions`

- Armazena todos os movimentos financeiros
- Campos: `id`, `condominium_id`, `bank_account_id`, `transaction_type` (income/expense), `amount`, `transaction_date`, `description`, `category`, `reference`, `related_type` (fee_payment/expense/revenue/manual), `related_id`, `created_by`, `created_at`, `updated_at`
- Suporta movimentos manuais e automáticos (de quotas, despesas, receitas)

### 3. Modificação `fee_payments`

- Adicionar coluna `financial_transaction_id` (NOT NULL após migração)
- Foreign key para `financial_transactions`

## Implementação

### Fase 1: Estrutura Base

**Migration 043: Criar tabela bank_accounts**

- Criar tabela com campos acima
- Índices em `condominium_id`, `account_type`, `is_active`

**Migration 044: Criar tabela financial_transactions**

- Criar tabela com campos acima
- Índices em `condominium_id`, `bank_account_id`, `transaction_date`, `related_type`, `related_id`

**Migration 045: Adicionar financial_transaction_id a fee_payments**

- Adicionar coluna `financial_transaction_id INT NULL`
- Foreign key para `financial_transactions`
- Criar movimentos retroativos para pagamentos existentes

### Fase 2: Modelos

**app/Models/BankAccount.php**

- CRUD completo
- Métodos: `getByCondominium()`, `getActiveAccounts()`, `getCashAccount()`, `calculateBalance()`
- Método especial para criar conta de caixa padrão

**app/Models/FinancialTransaction.php**

- CRUD completo
- Métodos: `getByAccount()`, `getByCondominium()`, `getByRelated()`, `calculateAccountBalance()`
- Método `createFromFeePayment()` para criar movimento automático

### Fase 3: Controllers

**app/Controllers/BankAccountController.php**

- `index()`: Listar contas
- `create()`: Formulário criar conta
- `store()`: Criar conta com validação:
- Se `account_type = 'bank'`: Validar que `name`, `iban` e `swift` estão preenchidos
- Se `account_type = 'cash'`: Validar que apenas `name` está preenchido, ignorar campos bancários
- `edit()`: Formulário editar
- `update()`: Atualizar conta com mesmas validações
- `delete()`: Eliminar conta (com validação de movimentos)

**app/Controllers/FinancialTransactionController.php**

- `index()`: Listar movimentos com filtros
- `create()`: Formulário criar movimento manual
- `store()`: Criar movimento
- `edit()`: Formulário editar
- `update()`: Atualizar movimento
- `delete()`: Eliminar movimento (com validações)
- `getAccountBalance()`: API para obter saldo atual

**Modificar app/Controllers/FinanceController.php**

- Atualizar `addPayment()` para criar movimento automático ou associar existente
- Adicionar opção de selecionar movimento existente no formulário

### Fase 4: Views

**app/Views/pages/bank-accounts/index.html.twig**

- Lista de contas com saldos
- Botões para criar, editar, eliminar
- Indicador visual para conta de caixa

**app/Views/pages/bank-accounts/create.html.twig** e **edit.html.twig**

- Formulário para criar/editar conta
- Campo especial para tipo (banco/caixa)
- **Para contas bancárias**: Campos obrigatórios - Nome, IBAN, SWIFT. Campos opcionais - Banco, Número de conta
- **Para caixa**: Apenas campo obrigatório - Nome. Campos bancários ocultos/desabilitados
- Validação JavaScript para mostrar/ocultar campos conforme tipo selecionado
- Validação no servidor para garantir IBAN/SWIFT obrigatórios apenas para contas bancárias

**app/Views/pages/financial-transactions/index.html.twig**

- Lista de movimentos com filtros (conta, tipo, período)
- Tabela com colunas: Data, Conta, Tipo, Descrição, Valor, Saldo Acumulado
- Botões para criar, editar, eliminar
- Filtros avançados

**app/Views/pages/financial-transactions/create.html.twig** e **edit.html.twig**

- Formulário para criar/editar movimento
- Seleção de conta (incluindo caixa)
- Tipo (entrada/saída)
- Categoria e descrição
- Opção de associar a quota existente

**Modificar app/Views/pages/fees/index.html.twig**

- Adicionar campo para selecionar movimento existente ou criar novo
- Mostrar movimento associado em cada pagamento

**Modificar app/Views/pages/finances/index.html.twig**

- Adicionar card com saldos atuais por conta
- Gráfico de evolução de saldos
- Resumo financeiro

### Fase 5: Funcionalidades Especiais

**Migração Retroativa (Migration 045)**

- Script para criar movimentos financeiros para todos os `fee_payments` existentes
- Criar conta de caixa padrão se não existir
- Associar movimentos criados aos pagamentos

**Validações**

- **Contas bancárias**: Validar que `name`, `iban` e `swift` são obrigatórios
- **Caixa**: Validar que apenas `name` é obrigatório, IBAN/SWIFT não devem ser preenchidos
- Não permitir eliminar conta com movimentos
- Não permitir eliminar movimento associado a pagamento de quota
- Validar saldo suficiente antes de criar saída
- Garantir que pagamento de quota sempre tem movimento
- Validar formato de IBAN (opcional mas recomendado)

**Cálculo de Saldos**

- Saldo = `initial_balance` + soma de entradas - soma de saídas
- Cachear saldo atual na tabela `bank_accounts` (coluna `current_balance`)
- Atualizar saldo ao criar/editar/eliminar movimento

### Fase 6: Rotas

Adicionar em `routes.php`:

- `/condominiums/{id}/bank-accounts` (GET, POST)
- `/condominiums/{id}/bank-accounts/{account_id}/edit` (GET, POST)
- `/condominiums/{id}/bank-accounts/{account_id}/delete` (POST)
- `/condominiums/{id}/financial-transactions` (GET, POST)
- `/condominiums/{id}/financial-transactions/{transaction_id}/edit` (GET, POST)
- `/condominiums/{id}/financial-transactions/{transaction_id}/delete` (POST)
- `/condominiums/{id}/financial-transactions/balance/{account_id}` (GET - API)

## Fluxo de Trabalho

1. **Admin cria contas bancárias** (incluindo caixa físico)
2. **Ao registar pagamento de quota**:

- Opção 1: Criar movimento automático (seleciona conta)
- Opção 2: Associar a movimento existente

3. **Movimentos manuais** podem ser criados independentemente
4. **Saldos são calculados automaticamente** e exibidos no dashboard

## Considerações Técnicas

- Usar transações de base de dados ao criar pagamento + movimento
- Atualizar saldo em tempo real ou via trigger
- Validação de integridade referencial
- Logs de auditoria para movimentos financeiros críticos