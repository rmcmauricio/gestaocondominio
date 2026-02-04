---
name: Associação Condóminos-Frações + Sistema Multi-Tenant com Subscrição
overview: Implementar associação de condóminos a frações no formulário de edição e transformar o sistema de single-tenant para multi-tenant com sistema de subscrição via MB Way, permitindo múltiplos condomínios com isolamento de dados e controlo de acesso baseado em planos.
todos:
  - id: assoc-owners-units-form
    content: Adicionar secção de gestão de titularidade no formulário de fração (units/form.html.twig)
    status: completed
  - id: assoc-owners-units-controller
    content: Atualizar UnitController para processar associação de condóminos
    status: completed
    dependencies:
      - assoc-owners-units-form
  - id: migration-subscription-tables
    content: Criar migração 003_subscription_system.sql com tabelas de planos, subscrições e pagamentos
    status: completed
  - id: migration-owners-condominium
    content: Adicionar condominium_id à tabela owners e migrar dados existentes
    status: completed
    dependencies:
      - migration-subscription-tables
  - id: models-subscription
    content: "Criar models: SubscriptionPlan, Subscription, SubscriptionPayment"
    status: completed
    dependencies:
      - migration-subscription-tables
  - id: service-subscription
    content: Criar SubscriptionService com lógica de verificação de acesso e gestão de subscrições
    status: completed
    dependencies:
      - models-subscription
  - id: service-payment-gateway
    content: Criar PaymentGatewayService para integração MB Way (inicialmente simulado)
    status: completed
    dependencies:
      - service-subscription
  - id: controller-subscription-plan
    content: Criar SubscriptionPlanController para gestão de planos (super-admin)
    status: completed
    dependencies:
      - models-subscription
  - id: controller-subscription
    content: Criar SubscriptionController para processo de subscrição e renovação
    status: completed
    dependencies:
      - service-subscription
      - service-payment-gateway
  - id: controller-condominium
    content: Criar CondominiumController para registo self-service de condomínios
    status: completed
    dependencies:
      - service-subscription
  - id: middleware-access-control
    content: Adicionar verificação de acesso em Controller base e métodos de escrita
    status: completed
    dependencies:
      - service-subscription
  - id: auth-subscription-check
    content: Atualizar AuthController para verificar subscrição após login
    status: completed
    dependencies:
      - service-subscription
  - id: isolation-owners
    content: Atualizar Owner model e OwnerController para filtrar por condominium_id
    status: completed
    dependencies:
      - migration-owners-condominium
  - id: views-subscription
    content: "Criar views: registo condomínio, gestão planos, subscrição, status pagamento"
    status: completed
    dependencies:
      - controller-subscription
      - controller-condominium
  - id: dashboard-subscription-alerts
    content: Adicionar alertas de subscrição no dashboard
    status: completed
    dependencies:
      - service-subscription
  - id: read-only-mode-ui
    content: Adicionar indicadores visuais e desabilitar ações quando em modo read-only
    status: completed
    dependencies:
      - middleware-access-control
  - id: routes-subscription
    content: Adicionar rotas para subscrições, planos e registo de condomínios
    status: completed
    dependencies:
      - controller-subscription
      - controller-condominium
---

# Plano

: Associação Condóminos-Frações + Sistema Multi-Tenant com Subscrição

## 1. Associação de Condóminos a Frações

### 1.1 Atualizar formulário de fração

- **Arquivo**: `app/Views/pages/units/form.html.twig`

- Adicionar secção para gestão de titularidade:

- Select para escolher condómino atual (filtrado por condomínio)

- Campo de data de início da titularidade

- Botão para adicionar condómino secundário (casal)

- Lista de condóminos associados com opção de remover

- Histórico de titularidades (opcional, em accordion)

### 1.2 Atualizar UnitController

- **Arquivo**: `app/Controllers/UnitController.php`

- Método `form()`: Carregar condóminos do condomínio e titularidade atual

- Método `save()`: Processar associação de condóminos após salvar fração

- Novo método `assignOwner()`: Associar condómino a fração via AJAX

- Novo método `removeOwner()`: Remover associação de condómino

### 1.3 Criar view de histórico

- **Arquivo**: `app/Views/pages/units/ownership-history.html.twig` (opcional)

- Mostrar histórico completo de titularidades de uma fração

## 2. Sistema Multi-Tenant com Subscrição

### 2.1 Migração de Base de Dados

- **Arquivo**: `database/migrations/003_subscription_system.sql`

- Adicionar `condominium_id` à tabela `owners` (isolamento de dados)

- Criar tabela `subscription_plans`:

- `id`, `name`, `price`, `currency`, `period_days`, `features_json`, `active`

- Criar tabela `subscriptions`:

- `id`, `condominium_id`, `plan_id`, `status` (active, expired, cancelled, pending)
- `start_date`, `end_date`, `renewal_date`

- `payment_reference` (referência MB Way)

- `payment_status` (pending, paid, failed)
- `created_at`, `updated_at`

- Criar tabela `subscription_payments`:

- `id`, `subscription_id`, `amount`, `payment_method`, `reference`

- `status`, `paid_at`, `expires_at`

- Adicionar perfil `super_admin` ao enum de `users.profile`

- Adicionar índices e foreign keys apropriados

### 2.2 Isolamento de Dados

- **Arquivo**: `app/Models/Owner.php`

- Adicionar método `getByCondominium(int $condominiumId)`

- Atualizar queries para filtrar por `condominium_id`

- **Arquivo**: `app/Controllers/OwnerController.php`

- Filtrar condóminos por `condominium_id` do utilizador logado

- **Arquivo**: `app/Models/UnitOwner.php`

- Garantir que queries respeitam isolamento via `unit.condominium_id`

### 2.3 Sistema de Subscrição

#### 2.3.1 Models

- **Arquivo**: `app/Models/SubscriptionPlan.php` (novo)
- CRUD de planos de subscrição

- Métodos: `getActive()`, `findById()`, `create()`, `update()`

- **Arquivo**: `app/Models/Subscription.php` (novo)

- Gestão de subscrições: `create()`, `activate()`, `expire()`, `renew()`

- Métodos: `getByCondominium()`, `isActive()`, `getDaysRemaining()`

- **Arquivo**: `app/Models/SubscriptionPayment.php` (novo)

- Gestão de pagamentos: `create()`, `confirm()`, `getByReference()`

#### 2.3.2 Service de Subscrição

- **Arquivo**: `app/Services/SubscriptionService.php` (novo)
- Lógica de negócio:

- `checkAccess()`: Verificar se condomínio tem acesso ativo

- `createSubscription()`: Criar nova subscrição

- `processPayment()`: Processar pagamento MB Way

- `expireSubscription()`: Expirar subscrição e bloquear acesso

- `renewSubscription()`: Renovar subscrição

#### 2.3.3 Controllers

**SubscriptionPlanController** (novo)

- **Arquivo**: `app/Controllers/SubscriptionPlanController.php`

- CRUD de planos (apenas super-admin)

- Rotas: `/subscription-plans`, `/subscription-plans/form`, `/subscription-plans/save`

**SubscriptionController** (novo)

- **Arquivo**: `app/Controllers/SubscriptionController.php`

- Gestão de subscrições:

- `index()`: Lista de subscrições (super-admin) ou detalhes da própria (admin condomínio)

- `subscribe()`: Processo de subscrição (escolher plano, gerar referência MB Way)

- `paymentCallback()`: Callback após pagamento MB Way

- `renew()`: Renovar subscrição

**CondominiumController** (novo)

- **Arquivo**: `app/Controllers/CondominiumController.php`
- Registo de novo condomínio (self-service)

- Gestão de condomínios (super-admin)

- Rotas: `/condominiums/register`, `/condominiums`, `/condominiums/form`

### 2.4 Middleware de Verificação de Acesso

- **Arquivo**: `app/Core/Controller.php`

- Adicionar método `checkSubscriptionAccess()`:

- Verificar se condomínio tem subscrição ativa

- Se expirada: permitir apenas leitura (read-only)

- Se sem subscrição: redirecionar para página de subscrição

- Aplicar em todos os métodos de escrita (save, update, delete)

### 2.5 Integração MB Way

- **Arquivo**: `app/Services/PaymentGatewayService.php` (novo)

- Métodos:

- `generateMBWayReference()`: Gerar referência MB Way única

- `validateMBWayPayment()`: Validar pagamento (simulado ou API real)

- `processMBWayCallback()`: Processar callback de pagamento

- **Nota**: Implementação inicial pode ser simulada; depois integrar API real MB Way

### 2.6 Views

**Registo de Condomínio**

- **Arquivo**: `app/Views/pages/condominiums/register.html.twig` (novo)

- Formulário de registo com dados do condomínio

- Criação automática de utilizador admin

**Gestão de Planos** (super-admin)

- **Arquivo**: `app/Views/pages/subscription-plans/index.html.twig` (novo)

- **Arquivo**: `app/Views/pages/subscription-plans/form.html.twig` (novo)

**Subscrição**

- **Arquivo**: `app/Views/pages/subscriptions/index.html.twig` (novo)

- **Arquivo**: `app/Views/pages/subscriptions/subscribe.html.twig` (novo)

- Mostrar planos disponíveis
- Gerar referência MB Way

- Instruções de pagamento

- **Arquivo**: `app/Views/pages/subscriptions/payment-status.html.twig` (novo)

**Dashboard com Avisos**

- **Arquivo**: `app/Views/pages/dashboard/index.html.twig`

- Adicionar alertas sobre estado da subscrição

- Mostrar dias restantes

- Aviso quando em modo read-only

### 2.7 Atualizações no Sistema Existente

**AuthController**

- **Arquivo**: `app/Controllers/AuthController.php`

- Após login: verificar subscrição ativa

- Se expirada: definir flag de read-only na sessão

- Se sem subscrição: redirecionar para página de subscrição

**Base Controller**

- **Arquivo**: `app/Core/Controller.php`

- Adicionar verificação automática de acesso em métodos de escrita

- Método `requireActiveSubscription()` para proteger ações críticas

**Todas as Views**

- Adicionar indicadores visuais quando em modo read-only

- Desabilitar botões de ação (editar, eliminar, criar) quando expirado

### 2.8 Rotas

- **Arquivo**: `routes.php`
- Adicionar rotas:

- `/condominiums/register` (público)

- `/subscription-plans/*` (super-admin)

- `/subscriptions/*` (admin condomínio + super-admin)

- `/payment/callback` (público, para MB Way)

### 2.9 Configuração

- **Arquivo**: `config.php`

- Adicionar constantes:

- `SUPER_ADMIN_EMAIL` (email do super-admin)

- `MBWAY_API_KEY` (quando integrar API real)
- `SUBSCRIPTION_GRACE_PERIOD_DAYS` (período de graça)

## 3. Fluxo de Funcionamento

### 3.1 Registo de Novo Condomínio

1. Administrador acede a `/condominiums/register`

2. Preenche dados do condomínio e cria conta admin

3. Redirecionado para escolher plano de subscrição

4. Gera referência MB Way

5. Após pagamento confirmado (manual ou callback), subscrição ativada

### 3.2 Uso Normal

1. Login verifica subscrição ativa

2. Se ativa: acesso completo

3. Se expirada: modo read-only (pode consultar, não pode editar)

4. Avisos no dashboard sobre renovação

### 3.3 Renovação

1. Admin acede a `/subscriptions`

2. Vê dias restantes e botão "Renovar"

3. Gera nova referência MB Way

4. Após pagamento, subscrição renovada

## 4. Considerações Técnicas

- **Isolamento**: Todos os dados filtrados por `condominium_id`

- **Segurança**: Middleware verifica acesso em todas as operações

- **Performance**: Índices em `condominium_id` em todas as tabelas relevantes

- **Backup**: Manter histórico de subscrições e pagamentos

- **Notificações**: Email quando subscrição está a expirar (futuro)

## 5. Migração de Dados Existentes

- Script de migração para:

- Adicionar `condominium_id` aos `owners` existentes (associar ao condomínio do utilizador)

- Criar subscrição inicial para condomínios existentes