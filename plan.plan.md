<!-- a02fb798-bdad-416d-a7b3-d9aea85026b5 7bf3e092-637e-4f7a-99ec-77d15d5f8830 -->
# Plano de Implementação - Sistema de Gestão de Condomínios SaaS

## Arquitetura e Tecnologias

**Stack Tecnológico:**

- Backend: PHP 8.0+ (framework MVC existente)
- Base de Dados: MySQL 8.0+
- Template Engine: Twig (já configurado)
- Email: PHPMailer (já configurado)
- Pagamentos: Integração com PSP Português (Multibanco, MBWay, SEPA)
- Autenticação: Argon2/Bcrypt para passwords
- Frontend: HTML5, CSS3, JavaScript (responsivo)

## Estrutura de Base de Dados

### Tabelas Principais

**Autenticação e Utilizadores:**

- `users` - Utilizadores do sistema (super_admin, admin, condomino, fornecedor)
- `user_sessions` - Sessões ativas
- `password_resets` - Tokens de recuperação de senha
- `audit_logs` - Logs de auditoria

**Sistema de Subscrições:**

- `plans` - Planos (START, PRO, BUSINESS)
- `subscriptions` - Subscrições ativas dos administradores
- `subscription_addons` - Addons contratados
- `invoices` - Faturas geradas
- `payments` - Pagamentos recebidos

**Gestão de Condomínios:**

- `condominiums` - Dados dos condomínios
- `fractions` - Frações de cada condomínio
- `condominium_users` - Associação condóminos ↔ frações
- `fraction_associations` - Associações (garagem, arrecadações)

**Finanças:**

- `budgets` - Orçamentos anuais
- `budget_items` - Itens do orçamento por categoria
- `expenses` - Despesas registadas
- `revenues` - Receitas
- `fees` - Quotas mensais/trimestrais
- `fee_payments` - Pagamentos de quotas

**Assembleias e Votações:**

- `assemblies` - Assembleias convocadas
- `assembly_attendees` - Presenças e procurações
- `assembly_votes` - Votações online
- `assembly_documents` - Documentos das assembleias

**Outros Módulos:**

- `occurrences` - Ocorrências reportadas
- `documents` - Documentos do condomínio
- `reservations` - Reservas de espaços comuns
- `suppliers` - Fornecedores
- `contracts` - Contratos com fornecedores
- `notifications` - Notificações do sistema
- `messages` - Mensagens internas (tickets)

## Fase 1: MVP (Core System)

### 1.1 Base de Dados e Migrations

- Criar schema completo da base de dados
- Sistema de migrations para versionamento
- Seeders para dados iniciais (planos, super_admin)

**Ficheiros:**

- `database/migrations/` - Ficheiros de migração
- `database/seeders/` - Seeders de dados iniciais
- `app/Core/DatabaseMigration.php` - Classe para executar migrations

### 1.2 Sistema de Autenticação Completo

- Model `User` com roles e permissões
- Controller `AuthController` completo (registro, login, recuperação)
- Middleware de autenticação e autorização
- 2FA opcional (TOTP)
- Encriptação Argon2 para passwords

**Ficheiros:**

- `app/Models/User.php`
- `app/Controllers/AuthController.php` (expandir existente)
- `app/Middleware/AuthMiddleware.php`
- `app/Middleware/RoleMiddleware.php`
- `app/Core/Security.php` - Helpers de segurança

### 1.3 Sistema de Subscrições e Planos

- Model `Plan` e `Subscription`
- Controller `SubscriptionController`
- Verificação de limites por plano
- Página de gestão de subscrição
- Integração com PSP para pagamentos

**Ficheiros:**

- `app/Models/Plan.php`
- `app/Models/Subscription.php`
- `app/Controllers/SubscriptionController.php`
- `app/Services/PaymentService.php` - Integração PSP
- `app/Services/SubscriptionService.php` - Lógica de subscrições
- `app/Views/pages/subscription/` - Views de subscrição

### 1.4 Gestão de Condomínios (Básico)

- CRUD de condomínios
- CRUD de frações
- Associação condóminos ↔ frações
- Convites por email

**Ficheiros:**

- `app/Models/Condominium.php`
- `app/Models/Fraction.php`
- `app/Controllers/CondominiumController.php`
- `app/Controllers/FractionController.php`
- `app/Services/InvitationService.php`

### 1.5 Finanças Básicas

- Orçamento anual simples
- Registo de despesas
- Emissão de quotas mensais
- Registo de pagamentos
- Relatórios básicos (balancete, saldos)

**Ficheiros:**

- `app/Models/Budget.php`
- `app/Models/Expense.php`
- `app/Models/Fee.php`
- `app/Controllers/FinanceController.php`
- `app/Services/FeeService.php` - Cálculo automático de quotas

### 1.6 Dashboard Administrador

- Visão geral financeira
- Condóminos em atraso
- Próximas quotas
- Estatísticas básicas

**Ficheiros:**

- `app/Controllers/DashboardController.php`
- `app/Views/pages/dashboard/admin.html.twig`

### 1.7 Dashboard Condómino

- Saldo atual
- Quotas pendentes
- Últimas despesas
- Documentos recentes

**Ficheiros:**

- `app/Views/pages/dashboard/condomino.html.twig`

## Fase 2: Módulos Essenciais

### 2.1 Gestão de Documentos

- Upload de documentos
- Organização por pastas
- Controlo de visibilidade
- Histórico de versões

**Ficheiros:**

- `app/Models/Document.php`
- `app/Controllers/DocumentController.php`
- `app/Services/FileStorageService.php`

### 2.2 Ocorrências e Manutenções

- Criação de ocorrências por condóminos
- Workflow de estados
- Atribuição a fornecedores
- Notificações automáticas

**Ficheiros:**

- `app/Models/Occurrence.php`
- `app/Controllers/OccurrenceController.php`
- `app/Services/NotificationService.php`

### 2.3 Assembleias e Convocatórias

- Criação de assembleias
- Envio automático de convocatórias (email + PDF)
- Registo de presenças
- Geração de atas

**Ficheiros:**

- `app/Models/Assembly.php`
- `app/Controllers/AssemblyController.php`
- `app/Services/PdfService.php` - Geração de PDFs
- `app/Services/EmailService.php` (expandir existente)

## Fase 3: Funcionalidades Avançadas

### 3.1 Votações Online

- Sistema de votações ponderadas por permilagem
- Votação durante assembleias
- Resultados em tempo real

**Ficheiros:**

- `app/Models/Vote.php`
- `app/Controllers/VoteController.php`
- `app/Services/VotingService.php`

### 3.2 Reservas de Espaços Comuns

- Definição de espaços
- Calendário de reservas
- Aprovação manual/automática
- Gestão de cauções

**Ficheiros:**

- `app/Models/Reservation.php`
- `app/Models/Space.php`
- `app/Controllers/ReservationController.php`

### 3.3 Gestão de Fornecedores

- CRUD de fornecedores
- Contratos com alertas de renovação
- Associação a despesas

**Ficheiros:**

- `app/Models/Supplier.php`
- `app/Models/Contract.php`
- `app/Controllers/SupplierController.php`
- `app/Services/ContractAlertService.php`

### 3.4 Comunicação Avançada

- Mural de avisos
- Sistema de mensagens interno
- Notificações push (opcional)

**Ficheiros:**

- `app/Models/Message.php`
- `app/Controllers/MessageController.php`
- `app/Services/PushNotificationService.php` (opcional)

## Fase 4: Integrações e Melhorias

### 4.1 Integração de Pagamentos

- Referências Multibanco automáticas
- MBWay
- Débito direto SEPA
- Webhooks para confirmação de pagamentos

**Ficheiros:**

- `app/Services/MultibancoService.php`
- `app/Services/MBWayService.php`
- `app/Controllers/WebhookController.php`

### 4.2 Relatórios Avançados

- Exportação para Excel/PDF
- Relatórios contabilísticos
- Gráficos e visualizações

**Ficheiros:**

- `app/Services/ReportService.php`
- `app/Services/ExportService.php`

### 4.3 API REST (para planos BUSINESS)

- Endpoints para integrações externas
- Autenticação por API key
- Documentação Swagger

**Ficheiros:**

- `app/Controllers/Api/` - Controllers de API
- `app/Middleware/ApiAuthMiddleware.php`

## Estrutura de Ficheiros Proposta

```
predio/
├── app/
│   ├── Controllers/
│   │   ├── AuthController.php (expandir)
│   │   ├── DashboardController.php
│   │   ├── CondominiumController.php
│   │   ├── FractionController.php
│   │   ├── FinanceController.php
│   │   ├── SubscriptionController.php
│   │   ├── DocumentController.php
│   │   ├── OccurrenceController.php
│   │   ├── AssemblyController.php
│   │   ├── VoteController.php
│   │   ├── ReservationController.php
│   │   ├── SupplierController.php
│   │   ├── MessageController.php
│   │   └── Api/ (para API REST)
│   ├── Models/
│   │   ├── User.php
│   │   ├── Plan.php
│   │   ├── Subscription.php
│   │   ├── Condominium.php
│   │   ├── Fraction.php
│   │   ├── Budget.php
│   │   ├── Expense.php
│   │   ├── Fee.php
│   │   ├── Document.php
│   │   ├── Occurrence.php
│   │   ├── Assembly.php
│   │   ├── Vote.php
│   │   ├── Reservation.php
│   │   ├── Supplier.php
│   │   └── Contract.php
│   ├── Services/
│   │   ├── PaymentService.php
│   │   ├── SubscriptionService.php
│   │   ├── FeeService.php
│   │   ├── InvitationService.php
│   │   ├── NotificationService.php
│   │   ├── PdfService.php
│   │   ├── VotingService.php
│   │   ├── FileStorageService.php
│   │   └── ReportService.php
│   ├── Middleware/
│   │   ├── AuthMiddleware.php
│   │   ├── RoleMiddleware.php
│   │   └── ApiAuthMiddleware.php
│   ├── Core/
│   │   ├── DatabaseMigration.php
│   │   └── Security.php
│   └── Views/
│       ├── pages/
│       │   ├── dashboard/
│       │   ├── condominiums/
│       │   ├── finances/
│       │   ├── subscription/
│       │   └── ...
│       └── templates/
├── database/
│   ├── migrations/
│   └── seeders/
├── storage/
│   ├── documents/
│   └── uploads/
├── config/
│   └── plans.php (configuração de planos)
└── routes.php (expandir com todas as rotas)
```

## Configurações Necessárias

### Variáveis de Ambiente (.env)

- Configurações de base de dados (já existe)
- Chaves de API do PSP
- Configurações de email (já existe)
- URLs de webhooks
- Configurações de armazenamento

### Configuração de Planos

- Ficheiro `config/plans.php` com definição de limites e preços

## Segurança e Compliance

- HTTPS obrigatório (configurar no servidor)
- GDPR compliance (política de privacidade, consentimentos)
- Backups automáticos (cron jobs)
- Logs de auditoria para todas as ações críticas
- Sanitização de inputs
- Proteção CSRF
- Rate limiting em endpoints sensíveis

## Testes

- Testes unitários para serviços críticos
- Testes de integração para fluxos principais
- Testes de aceitação para funcionalidades de negócio

## Notas de Implementação

1. **Prioridade MVP**: Focar primeiro em autenticação, subscrições, gestão básica de condomínios e finanças
2. **Pagamentos**: Começar com integração básica do PSP, expandir depois
3. **UI/UX**: Interface responsiva e moderna, usar Bootstrap ou framework similar
4. **Performance**: Implementar cache onde necessário, paginação em listas grandes
5. **Multi-idioma**: Expandir sistema de traduções existente para todos os módulos

### To-dos

- [ ] Criar schema completo da base de dados com todas as tabelas (users, subscriptions, plans, condominiums, fractions, budgets, expenses, fees, documents, occurrences, assemblies, votes, reservations, suppliers, contracts, etc.)
- [ ] Implementar sistema de migrations para versionamento da base de dados
- [ ] Implementar sistema completo de autenticação (registro, login, recuperação de senha, 2FA opcional) com encriptação Argon2
- [ ] Criar middleware de autenticação e autorização por roles (super_admin, admin, condomino, fornecedor)
- [ ] Implementar sistema de subscrições e planos (models, controllers, verificação de limites, página de gestão)
- [ ] Integrar sistema de pagamentos com PSP Português (Multibanco, MBWay, SEPA)
- [ ] Implementar CRUD completo de condomínios e frações com associação de condóminos
- [ ] Sistema de convites por email para condóminos criarem conta
- [ ] Implementar módulo básico de finanças (orçamentos, despesas, emissão de quotas, registo de pagamentos)
- [ ] Serviço automático de cálculo e emissão de quotas mensais baseado em permilagem
- [ ] Criar dashboard do administrador com visão geral financeira, condóminos em atraso, estatísticas
- [ ] Criar dashboard do condómino com saldo, quotas pendentes, documentos recentes
- [ ] Sistema de gestão de documentos com upload, organização por pastas, controlo de visibilidade
- [ ] Sistema de ocorrências com workflow de estados, atribuição a fornecedores, notificações
- [ ] Sistema de assembleias com convocatórias automáticas (email + PDF), registo de presenças, geração de atas
- [ ] Sistema de votações online ponderadas por permilagem para assembleias
- [ ] Sistema de reservas de espaços comuns com calendário e aprovação
- [ ] Gestão de fornecedores e contratos com alertas de renovação
- [ ] Sistema de comunicação (mural de avisos, mensagens internas, notificações)
- [ ] Sistema de relatórios avançados com exportação Excel/PDF e visualizações
- [ ] API REST para planos BUSINESS com autenticação por API key e documentação