---
name: Gestão de Condomínio - Plano Completo
overview: Desenvolvimento completo de aplicação web de gestão de condomínio com sistema de cobranças flexível, pagamentos, recibos PDF, relatórios e assembleias, substituindo o Excel existente.
todos:
  - id: sprint0-deps
    content: Adicionar dependências FPDF e PhpSpreadsheet ao composer.json
    status: completed
  - id: sprint0-db-schema
    content: Criar schema completo da base de dados (migrations/001_initial_schema.sql) com todas as tabelas
    status: completed
  - id: sprint0-auth-rbac
    content: Implementar sistema de autenticação completo com RBAC (Admin, Tesouraria, Consulta)
    status: completed
    dependencies:
      - sprint0-db-schema
  - id: sprint0-models-base
    content: "Criar models base: User, Condominium, Unit, Owner, UnitOwner"
    status: completed
    dependencies:
      - sprint0-db-schema
  - id: sprint1-billing-models
    content: "Criar models: BillingType, BillingPlan, Charge com todos os campos e relacionamentos"
    status: completed
    dependencies:
      - sprint0-models-base
  - id: sprint1-billing-service
    content: Implementar BillingService com lógica de cálculo (permilagem, tipologia, fixo, híbrido) e geração de períodos
    status: completed
    dependencies:
      - sprint1-billing-models
  - id: sprint1-billing-controllers
    content: "Criar controllers: BillingTypeController, BillingPlanController, ChargeController com CRUD e preview"
    status: completed
    dependencies:
      - sprint1-billing-service
  - id: sprint1-billing-views
    content: Criar views Twig para gestão de tipos, planos e lançamentos com filtros e grelhas
    status: in_progress
    dependencies:
      - sprint1-billing-controllers
  - id: sprint2-payment-models
    content: "Criar models: Payment, PaymentAllocation, Receipt com campos e relacionamentos"
    status: completed
    dependencies:
      - sprint1-billing-models
  - id: sprint2-payment-service
    content: Implementar PaymentService com lógica de rateio automático (FIFO + prioridade) e manual
    status: completed
    dependencies:
      - sprint2-payment-models
  - id: sprint2-receipt-service
    content: Implementar ReceiptService com geração de numeração e templates FPDF para recibos
    status: completed
    dependencies:
      - sprint2-payment-models
  - id: sprint2-payment-controllers
    content: "Criar controllers: PaymentController, ReceiptController com registo, rateio e confirmação"
    status: completed
    dependencies:
      - sprint2-payment-service
      - sprint2-receipt-service
  - id: sprint2-payment-views
    content: Criar views para registar pagamentos, rateio manual e listagem de recibos
    status: pending
    dependencies:
      - sprint2-payment-controllers
  - id: sprint3-financial-models
    content: "Criar models: Account, Transaction com campos e métodos de cálculo de saldos"
    status: completed
    dependencies:
      - sprint0-models-base
  - id: sprint3-transaction-service
    content: Implementar TransactionService com criação automática a partir de pagamentos e lançamento de despesas
    status: completed
    dependencies:
      - sprint3-financial-models
  - id: sprint3-report-service
    content: "Implementar ReportService com relatórios: dívida por ano, extrato por fração, resumo financeiro"
    status: completed
    dependencies:
      - sprint3-financial-models
      - sprint2-payment-models
  - id: sprint3-financial-controllers
    content: "Criar controllers: AccountController, TransactionController, ReportController"
    status: completed
    dependencies:
      - sprint3-transaction-service
      - sprint3-report-service
  - id: sprint3-dashboard
    content: Criar DashboardController e view com saldos, top devedores, cobranças do mês
    status: completed
    dependencies:
      - sprint3-financial-controllers
  - id: sprint4-assembly-models
    content: "Criar models: Assembly, AssemblyAttendance com cálculo de permilagem presente"
    status: completed
    dependencies:
      - sprint0-models-base
  - id: sprint4-assembly-service
    content: Implementar AssemblyService com cálculo de permilagem e geração de relatórios
    status: completed
    dependencies:
      - sprint4-assembly-models
  - id: sprint4-export-service
    content: Implementar ExportService com PhpSpreadsheet para exportação Excel/CSV
    status: completed
    dependencies:
      - sprint3-report-service
  - id: sprint4-audit-service
    content: Implementar AuditLogService e model AuditLog para logging de ações críticas
    status: completed
    dependencies:
      - sprint0-models-base
  - id: sprint4-assembly-controllers
    content: Criar AssemblyController e views para gestão de assembleias e presenças
    status: completed
    dependencies:
      - sprint4-assembly-service
  - id: sprint4-api-endpoints
    content: Criar endpoints REST API para todas as funcionalidades principais (preparação para PWA)
    status: completed
    dependencies:
      - sprint4-assembly-controllers
---

# P

lano de Desenvolvimento - App de Gestão de Condomínio

## Arquitetura Geral

Aplicação PHP MVC com:

- **Backend**: PHP 8.0+ com PDO/MySQL

- **Frontend**: Twig templates + JavaScript (vanilla ou framework leve)

- **PDF**: FPDF para recibos

- **Excel**: PhpSpreadsheet para exportações

- **API**: REST endpoints para futuro PWA
- **Autenticação**: RBAC (Admin, Tesouraria, Consulta)

## Estrutura de Base de Dados

### Tabelas Principais

1. **condominiums** - Informação do condomínio

2. **users** - Utilizadores do sistema (com perfis RBAC)

3. **units** - Frações

4. **owners** - Condóminos

5. **unit_owners** - Histórico de titularidade

6. **billing_types** - Tipos de cobrança (Quota Ordinária, Seguro, etc.)

7. **billing_plans** - Planos de emissão (calendário)

8. **charges** - Lançamentos por fração/período

9. **payments** - Pagamentos registados

10. **payment_allocations** - Rateio de pagamentos
11. **receipts** - Recibos emitidos
12. **accounts** - Contas financeiras (Caixa/Banco)

13. **transactions** - Movimentos financeiros

14. **assemblies** - Assembleias

15. **assembly_attendance** - Presenças

16. **audit_logs** - Logs de auditoria

## Sprint 0 - Fundação e Infraestrutura

### Tarefas

1. **Configuração de dependências**

- Adicionar FPDF ao `composer.json`

- Adicionar PhpSpreadsheet ao `composer.json`
- Atualizar dependências

2. **Schema de base de dados**

- Criar script SQL de migração (`database/migrations/001_initial_schema.sql`)

- Tabelas: condominiums, users, units, owners, unit_owners

- Constraints e índices

3. **Sistema de autenticação e RBAC**

- Model `User` com perfis (admin, tesouraria, consulta)

- Middleware de autorização

- Atualizar `AuthController` com autenticação real

- Tabela `users` com campos: id, email, password_hash, profile, condominium_id, active

4. **Model base e helpers**

- Estender `Model` base com métodos comuns

- Helper para cálculos de permilagem

- Helper para formatação monetária

5. **Estrutura de pastas**

- `app/Models/` - Todos os models

- `app/Controllers/` - Controllers organizados
- `app/Services/` - Lógica de negócio (BillingService, PaymentService, etc.)

- `app/Helpers/` - Helpers utilitários

- `database/migrations/` - Scripts SQL

- `storage/receipts/` - PDFs de recibos

- `public/uploads/` - Uploads diversos

## Sprint 1 - Tipos de Cobrança e Emissão Base

### Tarefas

1. **Model BillingType**

- CRUD completo

- Campos: name, category, calculation_method, allowed_frequencies (JSON), default_account_id, priority, active

- Métodos: getByCondominium(), getActive()

2. **Model BillingPlan**

- CRUD completo

- Campos: billing_type_id, frequency, start_date, end_date, amount_mode, amount_config_json, due_day, active

- Métodos: generateChargesPreview(), generateCharges()

3. **Model Charge**

- Campos: unit_id, billing_type_id, plan_id, period_start, period_end, due_date, amount, amount_paid, status, issued_at

- Estados: draft, issued, partial, paid, cancelled

- Métodos: calculateByPermillage(), calculateByTypology(), calculateFixed(), calculateHybrid()

- Validação: evitar duplicação (unique constraint: unit_id + billing_type_id + period_start)

4. **BillingService**

- Lógica de cálculo por permilagem

- Lógica de cálculo por tipologia
- Lógica de cálculo fixo

- Lógica de cálculo híbrido
- Arredondamento e tratamento de diferenças de cêntimos

- Geração de períodos (mensal, bimensal, trimestral, semestral, anual)

5. **Controllers**

- `BillingTypeController` - CRUD tipos

- `BillingPlanController` - CRUD planos + preview + geração

- `ChargeController` - Listagem, filtros, emissão, anulação

6. **Views**

- Listagem de tipos de cobrança
- Formulário criar/editar tipo

- Listagem de planos

- Formulário criar/editar plano

- Preview antes de gerar

- Grelha de lançamentos com filtros

## Sprint 2 - Pagamentos, Rateio e Recibos

### Tarefas

1. **Model Payment**

- Campos: unit_id, owner_id, date, amount, method, reference, notes, status

- Métodos: allocate(), confirm(), getUnallocated()

2. **Model PaymentAllocation**

- Campos: payment_id, charge_id, amount

- Relacionamento com Payment e Charge

3. **Model Receipt**

- Campos: payment_id, series, number, issued_at, pdf_path

- Métodos: generateNumber(), generatePDF()

4. **PaymentService**

- Lógica de rateio automático (FIFO + prioridade por tipo)

- Lógica de rateio manual

- Atualização de estados de charges (partial/paid)

- Geração automática de transactions

5. **ReceiptService**

- Geração de numeração por série/ano
- Template FPDF para recibos

- Inclusão de dados: condomínio, condómino, fração, linhas de pagamento

6. **Controllers**

- `PaymentController` - Registar pagamento, rateio, confirmação

- `ReceiptController` - Listagem, download PDF, reemissão

7. **Views**

- Formulário registar pagamento
- Interface de rateio (sugestão + edição manual)
- Listagem de pagamentos

- Listagem de recibos

- Visualização de recibo PDF

## Sprint 3 - Financeiro e Relatórios

### Tarefas

1. **Model Account**

- Campos: name, type (cash/bank), iban, condominium_id
- Métodos: getBalance(), getTransactions()

2. **Model Transaction**

- Campos: account_id, date, description, entity_type, unit_id, owner_id, debit, credit, balance_after, linked_payment_id

- Métodos: createFromPayment(), createExpense(), createTransfer()

3. **TransactionService**

- Criação automática de transactions a partir de pagamentos
- Lançamento de despesas
- Transferências entre contas
- Cálculo de saldos

4. **ReportService**

- Dívida por ano (tabela estilo Excel)

- Extrato por fração (lançamentos + pagamentos + saldos)

- Resumo financeiro (início/exercício/final)

- Exportação PDF/Excel/CSV

5. **Controllers**

- `AccountController` - CRUD contas

- `TransactionController` - Listagem, filtros, lançamento despesas/transferências

- `ReportController` - Todos os relatórios + exportações

6. **Views**

- Dashboard com saldos e resumos

- Listagem de contas

- Listagem de movimentos financeiros
- Formulário lançar despesa/transferência
- Relatório dívida por ano
- Extrato por fração

- Resumo financeiro

## Sprint 4 - Assembleias, Exportações e Melhorias

### Tarefas

1. **Model Assembly**

- Campos: condominium_id, date, title, notes

2. **Model AssemblyAttendance**

- Campos: assembly_id, unit_id, present, represented_by, notes

- Métodos: calculatePermillagePresent()

3. **AssemblyService**

- Cálculo de permilagem presente

- Geração de relatório de presenças

4. **ExportService**

- Exportação Excel (PhpSpreadsheet) - dívidas, extratos, resumos

- Exportação CSV - movimentos, pagamentos

- Templates Excel com formatação

5. **AuditLogService**

- Logging de ações críticas (criar/editar/anular cobranças, pagamentos, etc.)
- Model AuditLog

- Visualização de logs

6. **Fecho de Exercício (Opcional)**

- Model FiscalYear

- Bloqueio de edições retroativas

- Período de retificação

7. **Controllers**

- `AssemblyController` - CRUD assembleias + presenças
- `ExportController` - Endpoints de exportação

- `AuditController` - Visualização de logs

8. **Views**

- Formulário criar assembleia
- Interface marcar presenças

- Relatório de presenças com permilagem

- Visualização de logs de auditoria

## Melhorias e Funcionalidades Adicionais

1. **Importação do Excel**

- Script para importar dados do Excel "condominio-Fanqueiro.xlsx"

- Importação de frações, condóminos, permilagens

2. **Dashboard melhorado**

- Gráficos (Chart.js ou similar)

- Top devedores

- Cobranças do mês

- Últimos pagamentos

3. **Notificações**

- Avisos de vencimento

- Alertas de dívidas

4. **Portal do Condómino (Futuro)**

- Extrato pessoal
- Recibos

- Dívida atual

- Avisos

## Arquivos Principais a Criar

### Models

- `app/Models/User.php`
- `app/Models/Condominium.php`

- `app/Models/Unit.php`
- `app/Models/Owner.php`

- `app/Models/UnitOwner.php`

- `app/Models/BillingType.php`

- `app/Models/BillingPlan.php`

- `app/Models/Charge.php`

- `app/Models/Payment.php`
- `app/Models/PaymentAllocation.php`

- `app/Models/Receipt.php`

- `app/Models/Account.php`

- `app/Models/Transaction.php`

- `app/Models/Assembly.php`

- `app/Models/AssemblyAttendance.php`
- `app/Models/AuditLog.php`

### Services

- `app/Services/BillingService.php`

- `app/Services/PaymentService.php`

- `app/Services/ReceiptService.php`

- `app/Services/TransactionService.php`
- `app/Services/ReportService.php`

- `app/Services/AssemblyService.php`

- `app/Services/ExportService.php`

- `app/Services/AuditLogService.php`

### Controllers

- `app/Controllers/DashboardController.php`

- `app/Controllers/UnitController.php`

- `app/Controllers/OwnerController.php`

- `app/Controllers/BillingTypeController.php`

- `app/Controllers/BillingPlanController.php`
- `app/Controllers/ChargeController.php`
- `app/Controllers/PaymentController.php`

- `app/Controllers/ReceiptController.php`

- `app/Controllers/AccountController.php`

- `app/Controllers/TransactionController.php`

- `app/Controllers/ReportController.php`
- `app/Controllers/AssemblyController.php`

- `app/Controllers/ExportController.php`

- `app/Controllers/AuditController.php`

### Database

- `database/migrations/001_initial_schema.sql`

- `database/migrations/002_add_indexes.sql`

- `database/seeders/` (dados iniciais se necessário)

### Helpers

- `app/Helpers/PermillageHelper.php`

- `app/Helpers/MoneyHelper.php`

- `app/Helpers/DateHelper.php`

## Regras de Negócio Críticas

1. **Cálculo de Cobranças**

- Permilagem: `valor_fração = arred(valor_total * (permilagem_fração / soma_permilagens))`

- Diferenças de cêntimos atribuídas à última fração (ou maior permilagem)

- Validação de duplicação: não permitir mesmo tipo/período/fração

2. **Rateio de Pagamentos**

- Default: FIFO (mais antigo primeiro)

- Prioridade por tipo de cobrança

- Depois por período mais antigo

3. **Estados de Charges**

- draft → editável

- issued → bloqueado (exceto Admin)

- partial/paid → não editável

- cancelled → com motivo + audit

4. **Numeração de Recibos**

- Série por ano: 2026/0001

- Ou contínua: 0001, 0002, ...

- Configurável por condomínio

## Segurança e Validações

1. **RBAC em todos os endpoints**

2. **Validação de dados de entrada**

3. **CSRF protection**

4. **SQL injection prevention (PDO prepared statements)**

5. **XSS protection (Twig auto-escape)**

6. **Audit logs para ações críticas**

## Testes

1. **Testes unitários** para Services (BillingService, PaymentService)

2. **Testes de integração** para fluxos completos