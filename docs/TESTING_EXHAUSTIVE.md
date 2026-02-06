# Documento de Testes Exaustivos - Sistema de Gestão de Condomínios SaaS

## Índice

1. [Introdução](#introdução)
2. [Autenticação e Autorização](#1-autenticação-e-autorização)
3. [Sistema de Subscrições e Planos](#2-sistema-de-subscrições-e-planos)
4. [Gestão de Condomínios](#3-gestão-de-condomínios)
5. [Gestão de Frações](#4-gestão-de-frações)
6. [Sistema de Convites](#5-sistema-de-convites)
7. [Módulo Financeiro](#6-módulo-financeiro)
8. [Recibos](#7-recibos)
9. [Relatórios Financeiros](#8-relatórios-financeiros)
10. [Documentos](#9-documentos)
11. [Ocorrências](#10-ocorrências)
12. [Assembleias](#11-assembleias)
13. [Votações](#12-votações)
14. [Reservas de Espaços](#13-reservas-de-espaços)
15. [Fornecedores e Contratos](#14-fornecedores-e-contratos)
16. [Mensagens Internas](#15-mensagens-internas)
17. [Notificações](#16-notificações)
18. [Perfil de Utilizador](#17-perfil-de-utilizador)
19. [API REST](#18-api-rest)
20. [Sistema de Pagamentos](#19-sistema-de-pagamentos)
21. [Dashboard](#20-dashboard)
22. [Sistema Demo](#21-sistema-demo)
23. [Super Admin](#22-super-admin)
24. [Segurança e Compliance](#23-segurança-e-compliance)
25. [Integrações](#24-integrações)
26. [Performance e Escalabilidade](#25-performance-e-escalabilidade)

---

## Introdução

Este documento fornece um guia completo e exaustivo para testar todas as funcionalidades do Sistema de Gestão de Condomínios SaaS. O sistema é uma plataforma multi-tenant que permite a gestão completa de condomínios, incluindo finanças, documentos, assembleias, votações, ocorrências e muito mais.

### Objetivo

Garantir que todos os módulos e funcionalidades do sistema sejam testados de forma sistemática e completa, cobrindo:
- Casos de uso normais (happy paths)
- Casos de erro e validação
- Edge cases e cenários limite
- Integrações entre módulos
- Segurança e performance

### Pré-requisitos Gerais

1. Base de dados MySQL configurada e todas as migrações aplicadas
2. Servidor PHP 8.0+ configurado
3. Variáveis de ambiente configuradas (`.env`)
4. Dependências instaladas (`composer install`)
5. Acesso a conta de email para testes
6. Credenciais Google OAuth configuradas (para testes OAuth)
7. Credenciais IfThenPay configuradas (para testes de pagamento)

### Convenções

- **URL Base**: `http://localhost/predio` (ajustar conforme ambiente)
- **Roles**: `super_admin`, `admin`, `condomino`, `fornecedor`
- **Status de Subscrição**: `trial`, `active`, `cancelled`, `expired`
- **Dados de Teste**: Usar emails únicos para cada teste (ex: `teste1@example.com`, `teste2@example.com`)

---

## 1. Autenticação e Autorização

### 1.1 Registo de Utilizadores

#### Teste 1.1.1: Registo de Condómino (User)

**Objetivo**: Verificar que utilizadores podem criar conta como condómino sem necessidade de subscrição.

**Pré-requisitos**:
- Base de dados limpa ou email único disponível
- Migrações aplicadas

**Passos**:
1. Aceder a `/register`
2. Selecionar opção "Utilizador (Condomino)"
3. Preencher formulário:
   - Nome: "João Silva"
   - Email: `condomino1@test.com`
   - Password: `Test123!@#`
   - Confirmar Password: `Test123!@#`
4. Aceitar termos e condições
5. Submeter formulário

**Resultado Esperado**:
- Conta criada com sucesso
- Role = `condomino` na base de dados
- Login automático realizado
- Redirecionamento para `/dashboard`
- Sem subscrição criada
- Mensagem de sucesso exibida

**Verificação na BD**:
```sql
SELECT id, email, role, status FROM users WHERE email = 'condomino1@test.com';
-- Verificar: role = 'condomino', status = 'active'

SELECT * FROM subscriptions WHERE user_id = [user_id];
-- Deve retornar vazio
```

**Casos Negativos**:
- Email já existente → Erro "Email já está em uso"
- Password fraca → Erro de validação
- Campos obrigatórios vazios → Erro de validação
- Email inválido → Erro de validação
- Passwords não coincidem → Erro de validação

#### Teste 1.1.2: Registo de Administrador

**Objetivo**: Verificar que administradores são redirecionados para seleção de plano após registo.

**Passos**:
1. Aceder a `/register`
2. Selecionar opção "Administrador"
3. Preencher formulário:
   - Nome: "Maria Admin"
   - Email: `admin1@test.com`
   - Password: `Admin123!@#`
   - Confirmar Password: `Admin123!@#`
4. Submeter formulário
5. Verificar redirecionamento para `/auth/select-plan`
6. Selecionar plano "Condomínio Base"
7. Submeter seleção

**Resultado Esperado**:
- Redirecionamento para `/auth/select-plan` após registo
- Após seleção de plano:
  - Role = `admin` na base de dados
  - Subscrição criada com status `trial`
  - `trial_ends_at` definido (14 dias a partir de hoje)
  - Redirecionamento para `/subscription`
  - Trial ativo visível na página

**Verificação na BD**:
```sql
SELECT id, email, role FROM users WHERE email = 'admin1@test.com';
-- Verificar: role = 'admin'

SELECT id, user_id, plan_id, status, trial_ends_at 
FROM subscriptions 
WHERE user_id = [user_id];
-- Verificar: status = 'trial', trial_ends_at = DATE_ADD(NOW(), INTERVAL 14 DAY)
```

#### Teste 1.1.3: Registo com Google OAuth - Novo Utilizador (Condómino)

**Objetivo**: Verificar fluxo Google OAuth para novos utilizadores tipo condómino.

**Pré-requisitos**:
- Credenciais Google OAuth configuradas
- Conta Google de teste disponível

**Passos**:
1. Aceder a `/register`
2. Clicar em "Continuar com Google"
3. Autenticar com conta Google que não existe no sistema
4. Verificar redirecionamento para `/auth/select-account-type`
5. Selecionar "Utilizador (Condomino)"
6. Submeter

**Resultado Esperado**:
- Conta criada com `google_id` preenchido
- `auth_provider = 'google'`
- `email_verified = 1` (se Google verificar)
- Sem subscrição criada
- Login automático
- Redirecionamento para dashboard

**Verificação na BD**:
```sql
SELECT id, email, role, google_id, auth_provider, email_verified 
FROM users 
WHERE email = '[google_email]';
-- Verificar: google_id preenchido, auth_provider = 'google'
```

#### Teste 1.1.4: Registo com Google OAuth - Novo Utilizador (Admin)

**Objetivo**: Verificar fluxo Google OAuth para novos administradores.

**Passos**:
1. Aceder a `/register`
2. Clicar em "Continuar com Google"
3. Autenticar com conta Google nova
4. Selecionar "Administrador"
5. Selecionar plano
6. Submeter

**Resultado Esperado**:
- Conta criada com Google OAuth
- Trial iniciado automaticamente
- Acesso completo durante trial

### 1.2 Login

#### Teste 1.2.1: Login Tradicional Bem-Sucedido

**Objetivo**: Verificar login com credenciais válidas.

**Pré-requisitos**:
- Utilizador existente na base de dados

**Passos**:
1. Aceder a `/login`
2. Inserir email: `condomino1@test.com`
3. Inserir password: `Test123!@#`
4. Submeter formulário

**Resultado Esperado**:
- Login bem-sucedido
- Sessão criada
- Redirecionamento para `/dashboard`
- Dados do utilizador em `$_SESSION['user']`

**Verificação**:
- Verificar cookie de sessão criado
- Verificar entrada em `user_sessions` (se aplicável)
- Verificar log de auditoria

#### Teste 1.2.2: Login com Credenciais Inválidas

**Passos**:
1. Aceder a `/login`
2. Inserir email válido mas password incorreta
3. Submeter

**Resultado Esperado**:
- Erro: "Email ou senha incorretos"
- Sem criação de sessão
- Rate limiting aplicado após múltiplas tentativas

#### Teste 1.2.3: Login com Google OAuth - Utilizador Existente

**Objetivo**: Verificar login OAuth para utilizador já registado.

**Passos**:
1. Aceder a `/login`
2. Clicar em "Continuar com Google"
3. Autenticar com conta Google já existente no sistema

**Resultado Esperado**:
- Login automático sem seleção de tipo de conta
- Redirecionamento para dashboard
- Sessão criada

#### Teste 1.2.4: Rate Limiting no Login

**Objetivo**: Verificar que rate limiting funciona após múltiplas tentativas falhadas.

**Passos**:
1. Tentar fazer login 6 vezes com credenciais incorretas
2. Na 6ª tentativa, verificar comportamento

**Resultado Esperado**:
- Após 5 tentativas falhadas, bloqueio temporário
- Mensagem: "Muitas tentativas. Por favor, aguarde X minutos"
- Bloqueio por IP e/ou email

### 1.3 Recuperação de Senha

#### Teste 1.3.1: Solicitação de Recuperação de Senha

**Objetivo**: Verificar que utilizadores podem solicitar recuperação de senha.

**Passos**:
1. Aceder a `/forgot-password`
2. Inserir email válido: `condomino1@test.com`
3. Submeter formulário

**Resultado Esperado**:
- Token de recuperação gerado
- Email enviado com link de recuperação
- Token armazenado em `password_resets`
- Mensagem de sucesso (mesmo se email não existir, por segurança)

**Verificação na BD**:
```sql
SELECT * FROM password_resets 
WHERE email = 'condomino1@test.com' 
ORDER BY created_at DESC LIMIT 1;
-- Verificar: token gerado, expires_at definido
```

#### Teste 1.3.2: Redefinição de Senha com Token Válido

**Passos**:
1. Obter token de recuperação (do teste anterior ou BD)
2. Aceder a `/reset-password?token=[token]`
3. Inserir nova password: `NewPass123!@#`
4. Confirmar password: `NewPass123!@#`
5. Submeter

**Resultado Esperado**:
- Senha atualizada na base de dados
- Token invalidado
- Email de confirmação enviado
- Redirecionamento para `/login`
- Login possível com nova senha

**Verificação na BD**:
```sql
-- Verificar que token foi usado/invalidado
SELECT * FROM password_resets WHERE token = '[token]';
-- Deve retornar vazio ou marked como usado

-- Tentar login com nova senha
```

#### Teste 1.3.3: Token Expirado

**Passos**:
1. Obter token antigo (expirado)
2. Tentar aceder a `/reset-password?token=[token_expirado]`

**Resultado Esperado**:
- Erro: "Token inválido ou expirado"
- Redirecionamento para `/forgot-password`

**Verificação**:
```sql
-- Verificar expires_at < NOW()
SELECT * FROM password_resets 
WHERE token = '[token]' 
AND expires_at < NOW();
```

### 1.4 Gestão de Sessões

#### Teste 1.4.1: Logout

**Objetivo**: Verificar que logout termina sessão corretamente.

**Passos**:
1. Fazer login
2. Clicar no botão de logout no header
3. Verificar redirecionamento

**Resultado Esperado**:
- Sessão destruída
- Cookie de sessão removido
- Log de auditoria registado
- Redirecionamento para homepage (`/`)
- Mensagem de sucesso

**Verificação**:
- Tentar aceder a página protegida após logout
- Deve redirecionar para login

#### Teste 1.4.2: Sessão Expirada

**Objetivo**: Verificar comportamento quando sessão expira.

**Passos**:
1. Fazer login
2. Aguardar expiração da sessão (ou manipular cookie)
3. Tentar aceder a página protegida

**Resultado Esperado**:
- Redirecionamento para `/login`
- Mensagem: "Sessão expirada. Por favor, faça login novamente."

### 1.5 Roles e Permissões

#### Teste 1.5.1: Acesso Restrito por Role

**Objetivo**: Verificar que condóminos não podem aceder a páginas de admin.

**Passos**:
1. Fazer login como condómino
2. Tentar aceder a `/condominiums/create`
3. Tentar aceder a `/admin/users`

**Resultado Esperado**:
- Acesso negado (403 ou redirecionamento)
- Mensagem de erro apropriada

#### Teste 1.5.2: Super Admin - Acesso Total

**Objetivo**: Verificar que super_admin tem acesso a todas as funcionalidades.

**Passos**:
1. Fazer login como super_admin
2. Aceder a várias páginas administrativas:
   - `/admin/users`
   - `/admin/subscriptions`
   - `/admin/plans`
   - `/admin/audit-logs`

**Resultado Esperado**:
- Acesso permitido a todas as páginas
- Funcionalidades administrativas disponíveis

---

## 2. Sistema de Subscrições e Planos

### 2.1 Gestão de Planos

#### Teste 2.1.1: Visualização de Planos Disponíveis

**Objetivo**: Verificar que utilizadores podem ver planos disponíveis.

**Passos**:
1. Fazer login como admin sem subscrição ativa
2. Aceder a `/subscription/choose-plan`

**Resultado Esperado**:
- Lista de planos exibida:
  - Condomínio Base
  - Professional
  - Enterprise
- Informações de cada plano visíveis:
  - Preço mensal/anual
  - Licenças mínimas
  - Funcionalidades incluídas
  - Pricing tiers (se aplicável)

#### Teste 2.1.2: Criação de Subscrição - Plano Base

**Objetivo**: Verificar criação de subscrição para plano Condomínio Base.

**Pré-requisitos**:
- Admin sem subscrição
- Condomínio criado

**Passos**:
1. Aceder a `/subscription/choose-plan`
2. Selecionar plano "Condomínio Base"
3. Associar condomínio existente
4. Confirmar criação

**Resultado Esperado**:
- Subscrição criada com status `trial`
- `condominium_id` associado
- `used_licenses` calculado (número de frações ativas)
- Trial de 14 dias iniciado
- Redirecionamento para `/subscription`

**Verificação na BD**:
```sql
SELECT s.*, p.name as plan_name, p.plan_type
FROM subscriptions s
JOIN plans p ON s.plan_id = p.id
WHERE s.user_id = [user_id];

-- Verificar:
-- status = 'trial'
-- condominium_id = [condominium_id]
-- used_licenses = número de frações ativas
```

#### Teste 2.1.3: Criação de Subscrição - Plano Professional

**Objetivo**: Verificar criação de subscrição Professional com múltiplos condomínios.

**Passos**:
1. Selecionar plano "Professional"
2. Criar subscrição
3. Associar primeiro condomínio
4. Associar segundo condomínio

**Resultado Esperado**:
- Subscrição criada sem `condominium_id` (permite múltiplos)
- Associações criadas em `subscription_condominiums`
- `used_licenses` = soma de frações ativas de todos os condomínios
- Ambos condomínios desbloqueados

**Verificação na BD**:
```sql
-- Verificar subscrição
SELECT * FROM subscriptions WHERE user_id = [user_id];

-- Verificar associações
SELECT * FROM subscription_condominiums 
WHERE subscription_id = [subscription_id];

-- Verificar condomínios desbloqueados
SELECT id, name, is_locked FROM condominiums 
WHERE id IN ([condominium_1_id], [condominium_2_id]);
-- is_locked deve ser 0 (false)
```

### 2.2 Gestão de Licenças

#### Teste 2.2.1: Cálculo Automático de Licenças

**Objetivo**: Verificar que licenças são calculadas automaticamente.

**Passos**:
1. Criar subscrição com condomínio que tem 15 frações ativas
2. Verificar `used_licenses` na subscrição
3. Adicionar 5 frações ao condomínio
4. Recalcular licenças

**Resultado Esperado**:
- `used_licenses` inicial = 15
- Após adicionar frações e recalcular = 20
- Pricing tier atualizado se necessário

**Verificação**:
```sql
-- Recalcular licenças
UPDATE subscriptions 
SET used_licenses = (
    SELECT COUNT(*) 
    FROM fractions 
    WHERE condominium_id = [condominium_id] 
    AND is_archived = 0
)
WHERE id = [subscription_id];

SELECT used_licenses FROM subscriptions WHERE id = [subscription_id];
```

#### Teste 2.2.2: Adicionar Licenças Extras

**Objetivo**: Verificar adição de licenças extras à subscrição.

**Passos**:
1. Aceder a `/subscription`
2. Clicar em "Adicionar Licenças Extras"
3. Inserir número de licenças: 10
4. Confirmar

**Resultado Esperado**:
- `extra_licenses` atualizado
- Preço recalculado
- Mudança pendente até aprovação (se aplicável)

**Verificação na BD**:
```sql
SELECT extra_licenses, pending_extra_licenses 
FROM subscriptions 
WHERE id = [subscription_id];
```

#### Teste 2.2.3: Exceder Limite de Licenças

**Objetivo**: Verificar comportamento quando limite de licenças é excedido.

**Passos**:
1. Subscrição com limite de 50 licenças
2. Tentar adicionar frações que excedam o limite
3. Verificar bloqueio

**Resultado Esperado**:
- Bloqueio do condomínio (`is_locked = 1`)
- Notificação ao administrador
- Mensagem de erro ao tentar adicionar frações
- Opção de upgrade ou adicionar licenças extras

**Verificação**:
```sql
SELECT is_locked FROM condominiums WHERE id = [condominium_id];
-- Deve ser 1 (locked)
```

### 2.3 Trial Management

#### Teste 2.3.1: Início de Trial

**Objetivo**: Verificar que trial é iniciado corretamente.

**Passos**:
1. Criar conta admin
2. Selecionar plano
3. Verificar criação de trial

**Resultado Esperado**:
- `status = 'trial'`
- `trial_ends_at = DATE_ADD(NOW(), INTERVAL 14 DAY)`
- Acesso completo durante trial
- Contador de dias restantes visível

#### Teste 2.3.2: Trial Expirado - Bloqueio de Acesso

**Objetivo**: Verificar bloqueio quando trial expira.

**Passos**:
1. Criar subscrição em trial
2. Na BD, atualizar `trial_ends_at` para data passada:
   ```sql
   UPDATE subscriptions 
   SET trial_ends_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
   WHERE id = [subscription_id];
   ```
3. Fazer logout e login
4. Tentar aceder a funcionalidades

**Resultado Esperado**:
- Redirecionamento para `/subscription` ao tentar aceder a funcionalidades
- Mensagem: "O seu período experimental expirou..."
- Opção de ativar subscrição
- Acesso apenas a `/subscription` e `/profile`

#### Teste 2.3.3: Ativação de Subscrição Após Trial

**Objetivo**: Verificar ativação de subscrição após trial.

**Passos**:
1. Subscrição em trial (próxima a expirar)
2. Processar pagamento
3. Verificar ativação

**Resultado Esperado**:
- `status` muda de `trial` para `active`
- `current_period_start` e `current_period_end` definidos
- `trial_ends_at` mantido (histórico)
- Acesso completo restaurado

### 2.4 Pricing Tiers e Escalões

#### Teste 2.4.1: Cálculo de Preço por Escalão

**Objetivo**: Verificar cálculo correto de preço baseado em escalões.

**Passos**:
1. Plano com pricing tiers:
   - 10-14 frações: €20/mês
   - 15-19 frações: €25/mês
   - 20-29 frações: €30/mês
2. Criar subscrição com 12 frações
3. Verificar preço = €20/mês
4. Adicionar frações até 17
5. Recalcular preço

**Resultado Esperado**:
- Preço inicial: €20/mês (escalão 10-14)
- Após adicionar frações: €25/mês (escalão 15-19)
- `price_monthly` atualizado automaticamente

**Verificação**:
```sql
SELECT s.price_monthly, pt.min_licenses, pt.max_licenses, pt.price_monthly as tier_price
FROM subscriptions s
JOIN plans p ON s.plan_id = p.id
JOIN plan_pricing_tiers pt ON p.id = pt.plan_id
WHERE s.id = [subscription_id]
AND s.used_licenses BETWEEN pt.min_licenses AND pt.max_licenses;
```

#### Teste 2.4.2: Mudança de Escalão

**Objetivo**: Verificar que mudança de escalão atualiza preço corretamente.

**Passos**:
1. Subscrição com 14 frações (último do escalão)
2. Adicionar 1 fração (total = 15)
3. Recalcular

**Resultado Esperado**:
- Preço atualizado para escalão superior
- Notificação de mudança de preço (se aplicável)

### 2.5 Associação de Condomínios

#### Teste 2.5.1: Associar Condomínio a Subscrição Professional

**Objetivo**: Verificar associação de condomínio a subscrição que permite múltiplos.

**Passos**:
1. Subscrição Professional ativa
2. Aceder a `/subscription/attach-condominium`
3. Selecionar condomínio existente
4. Confirmar associação

**Resultado Esperado**:
- Associação criada em `subscription_condominiums`
- Licenças recalculadas
- Condomínio desbloqueado
- Preview de preço atualizado

**Verificação na BD**:
```sql
SELECT * FROM subscription_condominiums 
WHERE subscription_id = [subscription_id] 
AND condominium_id = [condominium_id];
```

#### Teste 2.5.2: Desassociar Condomínio

**Objetivo**: Verificar desassociação de condomínio.

**Passos**:
1. Subscrição com múltiplos condomínios associados
2. Desassociar um condomínio
3. Verificar bloqueio

**Resultado Esperado**:
- Associação removida
- Condomínio bloqueado (`is_locked = 1`)
- Licenças recalculadas
- Preço atualizado

### 2.6 Promoções e Códigos Promocionais

#### Teste 2.6.1: Aplicar Código Promocional

**Objetivo**: Verificar aplicação de código promocional.

**Pré-requisitos**:
- Promoção ativa na base de dados

**Passos**:
1. Aceder a `/subscription`
2. Inserir código promocional válido
3. Validar código
4. Aplicar promoção

**Resultado Esperado**:
- Código validado
- Desconto aplicado
- `promotion_id` associado à subscrição
- `promotion_ends_at` definido
- Preço atualizado com desconto

**Verificação na BD**:
```sql
SELECT s.*, p.code as promo_code, p.discount_percentage
FROM subscriptions s
JOIN promotions p ON s.promotion_id = p.id
WHERE s.id = [subscription_id];
```

#### Teste 2.6.2: Código Promocional Inválido

**Passos**:
1. Tentar aplicar código inexistente
2. Tentar aplicar código expirado
3. Tentar aplicar código já usado

**Resultado Esperado**:
- Erro apropriado para cada caso
- Sem alteração na subscrição

#### Teste 2.6.3: Expiração de Promoção

**Objetivo**: Verificar que promoções expiradas são removidas automaticamente.

**Passos**:
1. Subscrição com promoção ativa
2. Na BD, atualizar `promotion_ends_at` para data passada
3. Executar processo de verificação de promoções expiradas

**Resultado Esperado**:
- `promotion_id` removido
- `original_price_monthly` restaurado (se aplicável)
- `price_monthly` atualizado para preço sem desconto

### 2.7 Pagamentos e Faturas

#### Teste 2.7.1: Criar Pagamento - Multibanco

**Objetivo**: Verificar criação de referência Multibanco.

**Passos**:
1. Aceder a `/payments/[subscription_id]/create`
2. Selecionar método "Multibanco"
3. Confirmar criação

**Resultado Esperado**:
- Referência Multibanco gerada
- Entidade e referência exibidas
- Pagamento criado com status `pending`
- Fatura gerada (se aplicável)

**Verificação na BD**:
```sql
SELECT * FROM payments 
WHERE subscription_id = [subscription_id] 
ORDER BY created_at DESC LIMIT 1;
-- Verificar: method = 'multibanco', status = 'pending'
```

#### Teste 2.7.2: Processar Pagamento via Webhook

**Objetivo**: Verificar processamento de pagamento via webhook IfThenPay.

**Passos**:
1. Pagamento pendente criado
2. Simular callback do IfThenPay:
   ```
   POST /webhooks/ifthenpay
   {
     "reference": "[reference]",
     "status": "paid",
     "amount": 25.00
   }
   ```

**Resultado Esperado**:
- Pagamento atualizado para `status = 'paid'`
- Subscrição ativada/renovada
- `current_period_end` atualizado
- Email de confirmação enviado

**Verificação**:
```sql
SELECT status FROM payments WHERE id = [payment_id];
-- Deve ser 'paid'

SELECT status, current_period_end 
FROM subscriptions 
WHERE id = [subscription_id];
-- status deve ser 'active'
```

#### Teste 2.7.3: Cancelamento de Subscrição

**Objetivo**: Verificar cancelamento de subscrição.

**Passos**:
1. Aceder a `/subscription`
2. Clicar em "Cancelar Subscrição"
3. Confirmar cancelamento

**Resultado Esperado**:
- `status` muda para `cancelled`
- `cancelled_at` definido
- Condomínios bloqueados após fim do período
- Acesso mantido até `current_period_end`

### 2.8 Upgrade/Downgrade de Plano

#### Teste 2.8.1: Upgrade de Plano

**Objetivo**: Verificar upgrade de Condomínio Base para Professional.

**Passos**:
1. Subscrição Condomínio Base ativa
2. Aceder a `/subscription`
3. Selecionar "Mudar para Professional"
4. Confirmar mudança

**Resultado Esperado**:
- Mudança pendente até aprovação/pagamento
- Preview de novo preço
- `pending_plan_change` atualizado
- Após aprovação: plano atualizado, preço recalculado

#### Teste 2.8.2: Downgrade de Plano

**Objetivo**: Verificar downgrade com validação de limites.

**Passos**:
1. Subscrição Professional com 100 frações
2. Tentar fazer downgrade para Condomínio Base (limite 40 frações)

**Resultado Esperado**:
- Erro: "Não é possível fazer downgrade. Excede limite do plano."
- Sugestão de reduzir frações ou manter plano atual

---

## 3. Gestão de Condomínios

### 3.1 CRUD de Condomínios

#### Teste 3.1.1: Criar Condomínio

**Objetivo**: Verificar criação de novo condomínio.

**Pré-requisitos**:
- Admin com subscrição ativa ou em trial
- Permissão para criar condomínios

**Passos**:
1. Aceder a `/condominiums/create`
2. Preencher formulário:
   - Nome: "Edifício Sol"
   - Morada: "Rua das Flores, 123"
   - Código Postal: "1000-001"
   - Cidade: "Lisboa"
   - País: "Portugal"
   - NIF: "123456789"
   - IBAN: "PT50000000000000000000000"
   - Telefone: "+351912345678"
   - Email: "condominio@example.com"
   - Tipo: "Habitacional"
   - Total de Frações: 20
   - Regulamento: "Texto do regulamento..."
3. Submeter formulário

**Resultado Esperado**:
- Condomínio criado com sucesso
- `user_id` = ID do admin criador
- `is_active = 1`
- Associação criada em `condominium_users` com role `admin`
- Redirecionamento para `/condominiums/[id]`
- Mensagem de sucesso

**Verificação na BD**:
```sql
SELECT * FROM condominiums WHERE name = 'Edifício Sol';
-- Verificar todos os campos preenchidos corretamente

SELECT * FROM condominium_users 
WHERE condominium_id = [condominium_id] 
AND user_id = [user_id] 
AND role = 'admin';
-- Verificar associação criada
```

**Casos Negativos**:
- Campos obrigatórios vazios → Erro de validação
- NIF inválido → Erro de validação
- IBAN inválido → Erro de validação
- Email inválido → Erro de validação
- Limite de condomínios excedido → Erro e sugestão de upgrade

#### Teste 3.1.2: Visualizar Condomínio

**Objetivo**: Verificar visualização de detalhes do condomínio.

**Passos**:
1. Aceder a `/condominiums/[id]`
2. Verificar informações exibidas

**Resultado Esperado**:
- Todos os dados do condomínio exibidos
- Estatísticas (frações, condóminos, etc.)
- Ações disponíveis (editar, personalizar, etc.)
- Lista de frações associadas
- Histórico de atividades (se aplicável)

#### Teste 3.1.3: Editar Condomínio

**Objetivo**: Verificar edição de condomínio existente.

**Passos**:
1. Aceder a `/condominiums/[id]/edit`
2. Alterar campos:
   - Nome: "Edifício Sol Renovado"
   - Telefone: "+351987654321"
3. Submeter alterações

**Resultado Esperado**:
- Alterações salvas
- `updated_at` atualizado
- Log de auditoria criado
- Mensagem de sucesso
- Redirecionamento para página do condomínio

**Verificação na BD**:
```sql
SELECT name, phone, updated_at 
FROM condominiums 
WHERE id = [condominium_id];
-- Verificar campos atualizados
```

#### Teste 3.1.4: Eliminar Condomínio

**Objetivo**: Verificar eliminação de condomínio.

**Pré-requisitos**:
- Condomínio sem dados críticos (ou aceitar eliminação em cascata)

**Passos**:
1. Aceder a `/condominiums/[id]`
2. Clicar em "Eliminar Condomínio"
3. Confirmar eliminação

**Resultado Esperado**:
- Confirmação solicitada
- Após confirmação: condomínio eliminado
- Dados relacionados eliminados em cascata (frações, documentos, etc.)
- Log de auditoria
- Redirecionamento para dashboard

**Verificação na BD**:
```sql
SELECT * FROM condominiums WHERE id = [condominium_id];
-- Deve retornar vazio

-- Verificar eliminação em cascata
SELECT * FROM fractions WHERE condominium_id = [condominium_id];
-- Deve retornar vazio
```

### 3.2 Personalização

#### Teste 3.2.1: Upload de Logo

**Objetivo**: Verificar upload e gestão de logo do condomínio.

**Passos**:
1. Aceder a `/condominiums/[id]/customize`
2. Fazer upload de imagem (logo)
3. Verificar preview
4. Salvar

**Resultado Esperado**:
- Logo guardado em `/storage/condominiums/[id]/logo.[ext]`
- Caminho guardado na BD (`logo_path`)
- Logo exibido no header do condomínio
- Mensagem de sucesso

**Verificação**:
- Verificar ficheiro em `storage/condominiums/[id]/`
- Verificar campo `logo_path` na BD

#### Teste 3.2.2: Remover Logo

**Passos**:
1. Condomínio com logo existente
2. Clicar em "Remover Logo"
3. Confirmar

**Resultado Esperado**:
- Logo removido do servidor
- `logo_path` = NULL na BD
- Logo não exibido mais

#### Teste 3.2.3: Personalizar Template de Atas

**Objetivo**: Verificar personalização de template de atas.

**Passos**:
1. Aceder a `/condominiums/[id]/customize`
2. Editar template de atas
3. Inserir variáveis disponíveis
4. Salvar template

**Resultado Esperado**:
- Template guardado
- Template usado na geração de novas atas
- Preview disponível

### 3.3 Associação de Administradores

#### Teste 3.3.1: Associar Administrador por Email

**Objetivo**: Verificar associação de novo administrador ao condomínio.

**Passos**:
1. Aceder a `/condominiums/[id]/assign-admin`
2. Inserir email de utilizador existente
3. Confirmar associação

**Resultado Esperado**:
- Associação criada em `condominium_users`
- Role = `admin`
- Utilizador recebe notificação
- Utilizador vê condomínio no seu dashboard

**Verificação na BD**:
```sql
SELECT * FROM condominium_users 
WHERE condominium_id = [condominium_id] 
AND user_id = [user_id] 
AND role = 'admin';
```

#### Teste 3.3.2: Remover Administrador

**Passos**:
1. Condomínio com múltiplos administradores
2. Remover um administrador
3. Confirmar

**Resultado Esperado**:
- Associação removida ou `ended_at` definido
- Administrador perde acesso ao condomínio
- Notificação enviada

### 3.4 Transferência de Administração

#### Teste 3.4.1: Transferir Administração Principal

**Objetivo**: Verificar transferência de propriedade do condomínio.

**Passos**:
1. Admin proprietário acede a `/condominiums/[id]/assign-admin`
2. Seleciona outro admin para transferir propriedade
3. Confirma transferência

**Resultado Esperado**:
- `user_id` do condomínio atualizado
- Novo proprietário recebe notificação
- Transferência pendente até aprovação (se aplicável)
- Log de auditoria

**Verificação na BD**:
```sql
SELECT user_id FROM condominiums WHERE id = [condominium_id];
-- Deve ser o novo admin

SELECT * FROM admin_transfer_pending 
WHERE condominium_id = [condominium_id];
-- Verificar se há transferência pendente
```

#### Teste 3.4.2: Aceitar Transferência

**Passos**:
1. Transferência pendente criada
2. Novo admin acede a `/admin-transfers/pending`
3. Aceita transferência

**Resultado Esperado**:
- `user_id` atualizado
- Transferência removida da lista de pendentes
- Antigo admin mantém acesso como admin (não proprietário)

### 3.5 Bloqueio/Desbloqueio por Licenças

#### Teste 3.5.1: Bloqueio Automático por Excesso de Licenças

**Objetivo**: Verificar bloqueio quando limite de licenças é excedido.

**Passos**:
1. Subscrição com limite de 50 licenças
2. Condomínio com 50 frações ativas
3. Adicionar mais 1 fração

**Resultado Esperado**:
- Condomínio bloqueado (`is_locked = 1`)
- Notificação ao administrador
- Mensagem de erro ao tentar adicionar frações
- Opções: upgrade, adicionar licenças extras, arquivar frações

**Verificação**:
```sql
SELECT is_locked FROM condominiums WHERE id = [condominium_id];
-- Deve ser 1 (locked)
```

#### Teste 3.5.2: Desbloqueio após Resolver Limite

**Passos**:
1. Condomínio bloqueado
2. Adicionar licenças extras ou arquivar frações
3. Verificar desbloqueio

**Resultado Esperado**:
- `is_locked = 0` automaticamente
- Acesso restaurado
- Notificação de desbloqueio

### 3.6 Modo de Visualização

#### Teste 3.6.1: Alternar Modo de Visualização

**Objetivo**: Verificar alternância entre modos de visualização.

**Passos**:
1. Admin com múltiplos condomínios
2. Alternar modo de visualização (grid/lista)
3. Verificar persistência

**Resultado Esperado**:
- Modo alterado
- Preferência guardada em sessão ou BD
- Modo mantido em navegação

---

## 4. Gestão de Frações

### 4.1 CRUD de Frações

#### Teste 4.1.1: Criar Fração

**Objetivo**: Verificar criação de nova fração.

**Pré-requisitos**:
- Condomínio existente
- Acesso de admin ao condomínio

**Passos**:
1. Aceder a `/condominiums/[id]/fractions/create`
2. Preencher formulário:
   - Identificador: "A1"
   - Permilagem: 50.0000
   - Andar: "1"
   - Tipologia: "T2"
   - Área: 75.50
   - Notas: "Fração com varanda"
3. Submeter

**Resultado Esperado**:
- Fração criada
- `condominium_id` associado
- `is_active = 1`
- Permilagem validada (soma não excede 1000)
- Log de auditoria
- Licenças recalculadas (se aplicável)

**Verificação na BD**:
```sql
SELECT * FROM fractions 
WHERE condominium_id = [condominium_id] 
AND identifier = 'A1';
-- Verificar todos os campos

-- Verificar soma de permilagem
SELECT SUM(permillage) as total_permillage 
FROM fractions 
WHERE condominium_id = [condominium_id] 
AND is_active = 1;
-- Não deve exceder 1000
```

**Casos Negativos**:
- Identificador duplicado → Erro "Identificador já existe"
- Permilagem inválida → Erro de validação
- Soma de permilagem > 1000 → Erro de validação
- Limite de licenças excedido → Bloqueio do condomínio

#### Teste 4.1.2: Listar Frações

**Objetivo**: Verificar listagem de frações do condomínio.

**Passos**:
1. Aceder a `/condominiums/[id]/fractions`
2. Verificar lista

**Resultado Esperado**:
- Todas as frações ativas listadas
- Ordenação por identificador
- Informações principais visíveis
- Ações disponíveis (editar, eliminar, associar condómino)

#### Teste 4.1.3: Editar Fração

**Passos**:
1. Aceder a `/condominiums/[id]/fractions/[fraction_id]/edit`
2. Alterar permilagem: 55.0000
3. Salvar

**Resultado Esperado**:
- Alterações salvas
- Validação de permilagem
- Licenças recalculadas se necessário
- Log de auditoria

#### Teste 4.1.4: Eliminar/Arquivar Fração

**Objetivo**: Verificar arquivo de fração (soft delete).

**Passos**:
1. Fração existente sem condómino associado
2. Eliminar fração
3. Confirmar

**Resultado Esperado**:
- `is_active = 0` (arquivada)
- Fração não aparece em listagens normais
- Condómino desassociado (se houver)
- Licenças recalculadas

**Verificação**:
```sql
SELECT is_active FROM fractions WHERE id = [fraction_id];
-- Deve ser 0 (false)
```

### 4.2 Associação Condómino-Fração

#### Teste 4.2.1: Associar Condómino a Fração

**Objetivo**: Verificar associação de condómino a fração.

**Pré-requisitos**:
- Fração sem proprietário
- Utilizador existente (condómino)

**Passos**:
1. Aceder a fração
2. Clicar em "Associar Condómino"
3. Selecionar utilizador existente ou criar convite
4. Confirmar

**Resultado Esperado**:
- Associação criada em `condominium_users`
- `fraction_id` associado
- Condómino recebe notificação
- Condómino vê fração no dashboard

**Verificação na BD**:
```sql
SELECT * FROM condominium_users 
WHERE fraction_id = [fraction_id] 
AND user_id = [user_id];
-- Verificar associação criada
```

#### Teste 4.2.2: Remover Associação

**Passos**:
1. Fração com condómino associado
2. Remover associação
3. Confirmar

**Resultado Esperado**:
- Associação removida ou `ended_at` definido
- Condómino perde acesso à fração
- Notificação enviada

#### Teste 4.2.3: Auto-Associação (Admin)

**Objetivo**: Verificar que admin pode associar-se a fração.

**Passos**:
1. Admin acede a fração
2. Clicar em "Associar-me a esta fração"
3. Confirmar

**Resultado Esperado**:
- Admin associado à fração
- Mantém role `admin` em `condominium_users`
- Pode ver finanças da fração

### 4.3 Permilagem e Cálculos

#### Teste 4.3.1: Validação de Soma de Permilagem

**Objetivo**: Verificar que soma de permilagem não excede 1000.

**Passos**:
1. Condomínio com frações totalizando 950 permilagem
2. Tentar adicionar fração com 100 permilagem (total = 1050)

**Resultado Esperado**:
- Erro: "Soma de permilagem excede 1000"
- Fração não criada
- Sugestão de ajustar permilagem

#### Teste 4.3.2: Cálculo de Quotas por Permilagem

**Objetivo**: Verificar cálculo de quotas baseado em permilagem.

**Passos**:
1. Condomínio com orçamento mensal de €1000
2. Fração A com 50 permilagem
3. Fração B com 100 permilagem
4. Gerar quotas mensais

**Resultado Esperado**:
- Fração A: quota = €50 (5% de €1000)
- Fração B: quota = €100 (10% de €1000)
- Cálculo correto em todas as frações

### 4.4 Associações (Garagens, Arrecadações)

#### Teste 4.4.1: Associar Garagem a Fração

**Objetivo**: Verificar associação de garagem como fração associada.

**Passos**:
1. Criar fração tipo "Garagem" (G1)
2. Associar a fração habitacional (A1)
3. Confirmar

**Resultado Esperado**:
- Associação criada em `fraction_associations`
- Tipo = `garagem`
- Garagem aparece na lista de associações da fração A1

**Verificação na BD**:
```sql
SELECT * FROM fraction_associations 
WHERE fraction_id = [fraction_id] 
AND associated_fraction_id = [garage_fraction_id] 
AND type = 'garagem';
```

#### Teste 4.4.2: Associar Múltiplas Garagens

**Passos**:
1. Fração habitacional
2. Associar garagem G1
3. Associar garagem G2

**Resultado Esperado**:
- Ambas associações criadas
- Lista de associações mostra ambas

### 4.5 Contactos dos Proprietários

#### Teste 4.5.1: Atualizar Contacto do Proprietário

**Objetivo**: Verificar atualização de contacto do proprietário da fração.

**Passos**:
1. Fração com condómino associado
2. Aceder a `/condominiums/[id]/fractions/[fraction_id]`
3. Atualizar contacto:
   - Telefone: "+351912345678"
   - Email: "proprietario@example.com"
4. Salvar

**Resultado Esperado**:
- Contactos atualizados em `condominium_users`
- Dados visíveis na ficha da fração
- Utilizado em comunicações

**Verificação na BD**:
```sql
SELECT phone, email 
FROM condominium_users 
WHERE fraction_id = [fraction_id];
```

### 4.6 Arquivo de Frações

#### Teste 4.6.1: Arquivar Fração

**Objetivo**: Verificar arquivo de fração (soft delete).

**Passos**:
1. Fração ativa
2. Arquivar fração
3. Confirmar

**Resultado Esperado**:
- `is_active = 0`
- `is_archived = 1` (se campo existir)
- Fração não aparece em listagens normais
- Licenças recalculadas
- Histórico mantido

---

## 5. Sistema de Convites

### 5.1 Criação de Convites

#### Teste 5.1.1: Criar Convite por Email

**Objetivo**: Verificar criação e envio de convite por email.

**Pré-requisitos**:
- Condomínio existente
- Acesso de admin

**Passos**:
1. Aceder a `/condominiums/[id]/invitations/create`
2. Preencher:
   - Email: `novo.condomino@example.com`
   - Fração: Selecionar fração disponível
   - Mensagem personalizada (opcional)
3. Enviar convite

**Resultado Esperado**:
- Convite criado na BD
- Token único gerado
- Email enviado com link de aceitação
- `expires_at` definido (ex: 7 dias)
- Status = `pending`

**Verificação na BD**:
```sql
SELECT * FROM invitations 
WHERE condominium_id = [condominium_id] 
AND email = 'novo.condomino@example.com';
-- Verificar: token gerado, expires_at definido, status = 'pending'
```

#### Teste 5.1.2: Criar Convite sem Email (Apenas Fração)

**Objetivo**: Verificar criação de convite sem email (para associar depois).

**Passos**:
1. Criar convite sem email
2. Associar apenas fração
3. Salvar

**Resultado Esperado**:
- Convite criado com `email = NULL`
- Fração associada
- Convite pode ser atualizado com email depois

### 5.2 Aceitação de Convites

#### Teste 5.2.1: Aceitar Convite - Utilizador Novo

**Objetivo**: Verificar aceitação de convite por utilizador não registado.

**Passos**:
1. Obter link de convite: `/invitation/accept?token=[token]`
2. Aceder ao link
3. Preencher formulário de registo:
   - Nome: "João Condómino"
   - Email: `novo.condomino@example.com` (mesmo do convite)
   - Password: `Test123!@#`
4. Aceitar convite

**Resultado Esperado**:
- Conta criada
- Utilizador associado à fração
- Convite marcado como aceite (`accepted_at` definido)
- Status = `accepted`
- Login automático
- Redirecionamento para dashboard

**Verificação na BD**:
```sql
-- Verificar utilizador criado
SELECT * FROM users WHERE email = 'novo.condomino@example.com';

-- Verificar associação
SELECT * FROM condominium_users 
WHERE user_id = [user_id] 
AND fraction_id = [fraction_id];

-- Verificar convite aceite
SELECT accepted_at, status FROM invitations WHERE token = '[token]';
-- accepted_at deve estar preenchido, status = 'accepted'
```

#### Teste 5.2.2: Aceitar Convite - Utilizador Existente

**Objetivo**: Verificar aceitação por utilizador já registado.

**Passos**:
1. Utilizador já registado
2. Aceder a link de convite
3. Fazer login (se necessário)
4. Aceitar convite

**Resultado Esperado**:
- Associação criada sem criar nova conta
- Convite aceite
- Fração visível no dashboard

#### Teste 5.2.3: Convite Expirado

**Passos**:
1. Convite com `expires_at` no passado
2. Tentar aceitar convite

**Resultado Esperado**:
- Erro: "Convite expirado"
- Opção de solicitar novo convite
- Sem associação criada

### 5.3 Gestão de Convites

#### Teste 5.3.1: Revogar Convite

**Objetivo**: Verificar revogação de convite pendente.

**Passos**:
1. Convite pendente
2. Revogar convite
3. Confirmar

**Resultado Esperado**:
- Status = `revoked`
- `revoked_at` definido
- Token invalidado
- Link de aceitação não funciona mais

#### Teste 5.3.2: Reenviar Convite

**Passos**:
1. Convite pendente
2. Reenviar email
3. Verificar envio

**Resultado Esperado**:
- Novo email enviado
- Token mantido (ou regenerado)
- `expires_at` pode ser renovado

#### Teste 5.3.3: Atualizar Email do Convite

**Passos**:
1. Convite com email incorreto
2. Atualizar email
3. Reenviar

**Resultado Esperado**:
- Email atualizado
- Novo email enviado
- Convite válido com novo email

---

## 6. Módulo Financeiro

### 6.1 Orçamentos Anuais

#### Teste 6.1.1: Criar Orçamento Anual

**Objetivo**: Verificar criação de orçamento anual para o condomínio.

**Pré-requisitos**:
- Condomínio existente
- Acesso de admin

**Passos**:
1. Aceder a `/condominiums/[id]/budgets/create`
2. Preencher:
   - Ano: 2025
   - Status: "Rascunho"
3. Adicionar itens de orçamento:
   - Categoria: "Receita: Quotas Mensais"
   - Valor: €12000 (anual)
   - Categoria: "Despesa: Manutenção"
   - Valor: €5000 (anual)
4. Salvar orçamento

**Resultado Esperado**:
- Orçamento criado
- Itens de orçamento criados
- Status = `draft`
- Total de receitas e despesas calculados

**Verificação na BD**:
```sql
SELECT * FROM budgets 
WHERE condominium_id = [condominium_id] 
AND year = 2025;

SELECT * FROM budget_items 
WHERE budget_id = [budget_id];
-- Verificar itens criados
```

#### Teste 6.1.2: Aprovar Orçamento

**Passos**:
1. Orçamento em rascunho
2. Aprovar orçamento
3. Confirmar

**Resultado Esperado**:
- Status = `approved`
- `approved_at` definido
- Orçamento disponível para geração de quotas

#### Teste 6.1.3: Gerar Quotas Mensais

**Objetivo**: Verificar geração automática de quotas baseada no orçamento.

**Pré-requisitos**:
- Orçamento aprovado
- Frações com permilagem definida

**Passos**:
1. Aceder a `/condominiums/[id]/fees`
2. Clicar em "Gerar Quotas"
3. Selecionar mês(es): Janeiro, Fevereiro, Março
4. Confirmar geração

**Resultado Esperado**:
- Quotas geradas para todas as frações ativas
- Valor calculado proporcionalmente à permilagem
- Data de vencimento definida (ex: dia 10 de cada mês)
- Status = `pending`

**Verificação na BD**:
```sql
SELECT COUNT(*) as total_fees 
FROM fees 
WHERE condominium_id = [condominium_id] 
AND year = 2025 
AND month IN (1, 2, 3);
-- Deve ser igual a (número de frações * 3 meses)

-- Verificar cálculo de quota
SELECT f.*, fr.permillage 
FROM fees f
JOIN fractions fr ON f.fraction_id = fr.id
WHERE f.condominium_id = [condominium_id] 
AND f.month = 1;
-- Verificar que amount é proporcional à permilagem
```

#### Teste 6.1.4: Geração de Quotas Extras

**Objetivo**: Verificar geração de quotas extras (não mensais).

**Passos**:
1. Aceder a geração de quotas
2. Selecionar "Quota Extra"
3. Definir descrição: "Reparação elevador"
4. Valor total: €500
5. Gerar

**Resultado Esperado**:
- Quotas extras geradas
- Valor distribuído por permilagem
- Descrição incluída em cada quota

### 6.2 Despesas

#### Teste 6.2.1: Registar Despesa

**Objetivo**: Verificar registo de despesa do condomínio.

**Passos**:
1. Aceder a `/condominiums/[id]/expenses/create`
2. Preencher:
   - Descrição: "Manutenção elevador"
   - Valor: €150.00
   - Data: 2025-01-15
   - Categoria: "Manutenção"
   - Fornecedor: Selecionar fornecedor
   - Conta bancária: Selecionar conta
3. Salvar

**Resultado Esperado**:
- Despesa criada
- Movimento financeiro criado automaticamente (tipo `expense`)
- Saldo da conta atualizado
- Associação com fornecedor

**Verificação na BD**:
```sql
SELECT * FROM expenses 
WHERE condominium_id = [condominium_id] 
ORDER BY created_at DESC LIMIT 1;

-- Verificar movimento financeiro criado
SELECT * FROM financial_transactions 
WHERE related_type = 'expense' 
AND related_id = [expense_id];
```

#### Teste 6.2.2: Editar Despesa

**Passos**:
1. Despesa existente
2. Editar valor: €175.00
3. Salvar

**Resultado Esperado**:
- Despesa atualizada
- Movimento financeiro atualizado
- Saldo recalculado

#### Teste 6.2.3: Eliminar Despesa

**Passos**:
1. Despesa existente
2. Eliminar
3. Confirmar

**Resultado Esperado**:
- Despesa eliminada
- Movimento financeiro eliminado (ou marcado como cancelado)
- Saldo recalculado

### 6.3 Receitas

#### Teste 6.3.1: Registar Receita

**Objetivo**: Verificar registo de receita não relacionada com quotas.

**Passos**:
1. Aceder a `/condominiums/[id]/finances/revenues/create`
2. Preencher:
   - Descrição: "Aluguer espaço comum"
   - Valor: €200.00
   - Data: 2025-01-20
   - Categoria: "Rendimentos"
   - Conta bancária: Selecionar conta
3. Salvar

**Resultado Esperado**:
- Receita criada
- Movimento financeiro criado (tipo `income`)
- Saldo atualizado

### 6.4 Pagamentos de Quotas

#### Teste 6.4.1: Registar Pagamento de Quota

**Objetivo**: Verificar registo de pagamento de quota.

**Pré-requisitos**:
- Quota pendente existente
- Conta bancária configurada

**Passos**:
1. Aceder a quota pendente
2. Clicar em "Registar Pagamento"
3. Preencher:
   - Valor: Valor da quota (ou parcial)
   - Data: 2025-01-12
   - Método: "Transferência"
   - Referência: "T123456"
   - Conta bancária: Selecionar conta
   - Notas: "Pagamento completo"
4. Salvar

**Resultado Esperado**:
- Pagamento criado em `fee_payments`
- Quota atualizada: `status = 'paid'` (se valor completo)
- Movimento financeiro criado automaticamente
- `financial_transaction_id` associado ao pagamento
- Saldo da conta atualizado

**Verificação na BD**:
```sql
-- Verificar pagamento
SELECT * FROM fee_payments 
WHERE fee_id = [fee_id] 
ORDER BY created_at DESC LIMIT 1;

-- Verificar movimento financeiro
SELECT * FROM financial_transactions 
WHERE related_type = 'fee_payment' 
AND related_id = [payment_id];

-- Verificar quota atualizada
SELECT status, paid_amount FROM fees WHERE id = [fee_id];
```

#### Teste 6.4.2: Pagamento Parcial

**Objetivo**: Verificar pagamento parcial de quota.

**Passos**:
1. Quota de €100
2. Pagar €60
3. Registar pagamento parcial

**Resultado Esperado**:
- Pagamento de €60 registado
- Quota mantém `status = 'pending'`
- `paid_amount = 60`
- `remaining_amount = 40`
- Pode adicionar mais pagamentos

#### Teste 6.4.3: Pagamento em Massa

**Objetivo**: Verificar marcação de múltiplas quotas como pagas.

**Passos**:
1. Selecionar múltiplas quotas
2. Clicar em "Marcar como Pagas"
3. Definir data e método
4. Confirmar

**Resultado Esperado**:
- Todas as quotas selecionadas marcadas como pagas
- Pagamentos criados para cada quota
- Movimentos financeiros criados

### 6.5 Contas Bancárias

#### Teste 6.5.1: Criar Conta Bancária

**Objetivo**: Verificar criação de conta bancária.

**Passos**:
1. Aceder a `/condominiums/[id]/bank-accounts/create`
2. Preencher:
   - Tipo: "Bancária"
   - Nome: "Conta Principal"
   - Banco: "Banco Exemplo"
   - Número de Conta: "123456789"
   - IBAN: "PT50000000000000000000000"
   - SWIFT: "BANKPTXXX"
   - Saldo Inicial: €5000.00
3. Salvar

**Resultado Esperado**:
- Conta criada
- `account_type = 'bank'`
- `current_balance = initial_balance`
- Validação de IBAN e SWIFT

**Verificação na BD**:
```sql
SELECT * FROM bank_accounts 
WHERE condominium_id = [condominium_id] 
AND name = 'Conta Principal';
```

#### Teste 6.5.2: Criar Caixa Físico

**Passos**:
1. Criar conta tipo "Caixa Físico"
2. Preencher apenas nome: "Caixa"
3. Saldo inicial: €500.00

**Resultado Esperado**:
- Conta criada
- `account_type = 'cash'`
- Campos bancários não obrigatórios

#### Teste 6.5.3: Calcular Saldo Atual

**Objetivo**: Verificar cálculo automático de saldo.

**Passos**:
1. Conta com saldo inicial €5000
2. Adicionar receita de €200
3. Adicionar despesa de €150
4. Verificar saldo

**Resultado Esperado**:
- Saldo = €5050 (5000 + 200 - 150)
- Cálculo automático baseado em movimentos

**Verificação**:
```sql
SELECT current_balance FROM bank_accounts WHERE id = [account_id];
-- Deve ser 5050

-- Verificar cálculo manual
SELECT 
    initial_balance + 
    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) -
    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) as calculated_balance
FROM bank_accounts ba
LEFT JOIN financial_transactions ft ON ba.id = ft.bank_account_id
WHERE ba.id = [account_id];
```

### 6.6 Transações Financeiras

#### Teste 6.6.1: Criar Movimento Manual

**Objetivo**: Verificar criação de movimento financeiro manual.

**Passos**:
1. Aceder a `/condominiums/[id]/financial-transactions/create`
2. Preencher:
   - Tipo: "Receita"
   - Valor: €300.00
   - Data: 2025-01-25
   - Descrição: "Reembolso seguro"
   - Categoria: "Outros"
   - Conta: Selecionar conta
3. Salvar

**Resultado Esperado**:
- Movimento criado
- `related_type = 'manual'`
- Saldo atualizado

#### Teste 6.6.2: Importar Transações (CSV)

**Objetivo**: Verificar importação de transações via CSV.

**Passos**:
1. Aceder a `/condominiums/[id]/financial-transactions/import`
2. Fazer upload de ficheiro CSV com formato:
   ```
   Data,Descrição,Valor,Tipo
   2025-01-10,Quota A1,50.00,Receita
   2025-01-15,Manutenção,-75.00,Despesa
   ```
3. Preview de importação
4. Confirmar importação

**Resultado Esperado**:
- Transações importadas
- Validação de dados
- Preview antes de confirmar
- Movimentos criados

#### Teste 6.6.3: Liquidação Automática de Quotas

**Objetivo**: Verificar liquidação automática de quotas a partir de transação.

**Passos**:
1. Transação de receita de €500
2. Associar a liquidação de quotas
3. Selecionar fração e quotas pendentes
4. Confirmar liquidação

**Resultado Esperado**:
- Quotas liquidadas automaticamente
- Pagamentos criados
- Associação entre transação e pagamentos

### 6.7 Contas de Frações

#### Teste 6.7.1: Visualizar Conta de Fração

**Objetivo**: Verificar visualização de conta corrente da fração.

**Passos**:
1. Aceder a `/condominiums/[id]/fraction-accounts/[fraction_id]`
2. Verificar informações

**Resultado Esperado**:
- Saldo atual da fração
- Histórico de quotas
- Histórico de pagamentos
- Dívidas pendentes
- Movimentos detalhados

#### Teste 6.7.2: Dívidas Históricas

**Objetivo**: Verificar registo de dívidas históricas.

**Passos**:
1. Aceder a `/condominiums/[id]/finances/historical-debts`
2. Adicionar dívida histórica:
   - Fração: Selecionar
   - Valor: €200.00
   - Descrição: "Dívida anterior"
   - Data: 2024-12-01
3. Salvar

**Resultado Esperado**:
- Dívida registada
- Aparece na conta da fração
- Pode ser liquidada normalmente

---

## 7. Recibos

### 7.1 Geração Automática

#### Teste 7.1.1: Gerar Recibo de Pagamento

**Objetivo**: Verificar geração automática de recibo após pagamento.

**Passos**:
1. Pagamento de quota registado
2. Aceder a `/condominiums/[id]/receipts`
3. Verificar recibo gerado automaticamente

**Resultado Esperado**:
- Recibo criado automaticamente
- Número único atribuído
- Dados do pagamento incluídos
- PDF disponível para download

**Verificação na BD**:
```sql
SELECT * FROM receipts 
WHERE fee_payment_id = [payment_id];
```

#### Teste 7.1.2: Download de Recibo PDF

**Passos**:
1. Recibo existente
2. Clicar em "Download PDF"
3. Verificar ficheiro

**Resultado Esperado**:
- PDF gerado
- Dados corretos no PDF
- Formato profissional
- Logo do condomínio (se configurado)

### 7.2 Histórico de Recibos

#### Teste 7.2.1: Listar Recibos

**Passos**:
1. Aceder a `/condominiums/[id]/receipts`
2. Verificar lista

**Resultado Esperado**:
- Todos os recibos listados
- Filtros por fração, período
- Informações principais visíveis
- Links para download

---

## 8. Relatórios Financeiros

### 8.1 Balancete

#### Teste 8.1.1: Gerar Balancete

**Objetivo**: Verificar geração de balancete contabilístico.

**Passos**:
1. Aceder a `/condominiums/[id]/finances/reports`
2. Selecionar "Balancete"
3. Definir período: Janeiro 2025
4. Gerar relatório

**Resultado Esperado**:
- Relatório gerado
- Receitas e despesas por categoria
- Saldo final
- Opção de impressão/exportação

### 8.2 Relatório de Quotas

#### Teste 8.2.1: Relatório de Quotas por Período

**Passos**:
1. Selecionar "Relatório de Quotas"
2. Período: Q1 2025
3. Gerar

**Resultado Esperado**:
- Lista de todas as quotas do período
- Status de pagamento
- Totais por fração
- Totais gerais

### 8.3 Relatório de Despesas

#### Teste 8.3.1: Relatório de Despesas por Categoria

**Passos**:
1. Selecionar "Relatório de Despesas"
2. Agrupar por categoria
3. Período: 2025
4. Gerar

**Resultado Esperado**:
- Despesas agrupadas por categoria
- Totais por categoria
- Gráficos (se aplicável)

### 8.4 Fluxo de Caixa

#### Teste 8.4.1: Relatório de Fluxo de Caixa

**Passos**:
1. Selecionar "Fluxo de Caixa"
2. Período: 2025
3. Gerar

**Resultado Esperado**:
- Entradas e saídas por mês
- Saldo acumulado
- Projeção futura (se aplicável)

### 8.5 Orçamento vs Realizado

#### Teste 8.5.1: Comparar Orçamento com Realizado

**Passos**:
1. Selecionar "Orçamento vs Realizado"
2. Ano: 2025
3. Gerar

**Resultado Esperado**:
- Comparação lado a lado
- Variações calculadas
- Percentagens de execução

### 8.6 Relatório de Morosidade

#### Teste 8.6.1: Relatório de Condóminos em Atraso

**Passos**:
1. Selecionar "Relatório de Morosidade"
2. Data de corte: Hoje
3. Gerar

**Resultado Esperado**:
- Lista de frações com quotas em atraso
- Valor total em dívida
- Dias de atraso
- Histórico de pagamentos

### 8.7 Exportação e Impressão

#### Teste 8.7.1: Exportar Relatório para PDF

**Passos**:
1. Relatório gerado
2. Clicar em "Exportar PDF"
3. Verificar ficheiro

**Resultado Esperado**:
- PDF gerado
- Formatação correta
- Dados completos

#### Teste 8.7.2: Exportar para Excel

**Passos**:
1. Relatório gerado
2. Clicar em "Exportar Excel"
3. Verificar ficheiro

**Resultado Esperado**:
- Ficheiro Excel gerado
- Dados em formato tabular
- Pronto para análise

---

## 9. Documentos

### 9.1 Upload e Gestão

#### Teste 9.1.1: Upload de Documento

**Objetivo**: Verificar upload de documento para o condomínio.

**Passos**:
1. Aceder a `/condominiums/[id]/documents/create`
2. Selecionar pasta (ou criar nova)
3. Fazer upload de ficheiro PDF
4. Preencher:
   - Nome: "Regulamento Interno"
   - Descrição: "Regulamento atualizado 2025"
   - Visibilidade: "Todos os condóminos"
5. Salvar

**Resultado Esperado**:
- Documento guardado em `/storage/condominiums/[id]/documents/`
- Entrada criada na BD
- Visibilidade configurada
- Log de auditoria

**Verificação na BD**:
```sql
SELECT * FROM documents 
WHERE condominium_id = [condominium_id] 
AND name = 'Regulamento Interno';
```

#### Teste 9.1.2: Organização por Pastas

**Passos**:
1. Criar pasta "Assembleias"
2. Criar pasta "Contratos"
3. Mover documentos para pastas apropriadas

**Resultado Esperado**:
- Pastas criadas
- Documentos organizados
- Estrutura hierárquica mantida

#### Teste 9.1.3: Versões de Documentos

**Passos**:
1. Documento existente
2. Upload de nova versão
3. Verificar histórico

**Resultado Esperado**:
- Nova versão criada
- Versão anterior mantida
- Histórico de versões visível

### 9.2 Controlo de Visibilidade

#### Teste 9.2.1: Documento Privado (Apenas Admin)

**Passos**:
1. Criar documento com visibilidade "Apenas Administradores"
2. Login como condómino
3. Tentar aceder

**Resultado Esperado**:
- Condómino não vê documento
- Admin vê documento

#### Teste 9.2.2: Download de Documento

**Passos**:
1. Documento visível
2. Clicar em "Download"
3. Verificar ficheiro

**Resultado Esperado**:
- Download bem-sucedido
- Ficheiro correto
- Log de acesso registado

---

## 10. Ocorrências

### 10.1 Criação de Ocorrências

#### Teste 10.1.1: Condómino Cria Ocorrência

**Objetivo**: Verificar criação de ocorrência por condómino.

**Passos**:
1. Login como condómino
2. Aceder a `/condominiums/[id]/occurrences/create`
3. Preencher:
   - Título: "Elevador avariado"
   - Descrição: "Elevador não funciona no 3º andar"
   - Prioridade: "Alta"
   - Anexar foto
4. Submeter

**Resultado Esperado**:
- Ocorrência criada
- Status = `pending`
- Notificação enviada aos admins
- Foto guardada

**Verificação na BD**:
```sql
SELECT * FROM occurrences 
WHERE condominium_id = [condominium_id] 
ORDER BY created_at DESC LIMIT 1;
```

### 10.2 Workflow de Estados

#### Teste 10.2.1: Atribuir Ocorrência a Fornecedor

**Passos**:
1. Ocorrência pendente
2. Admin atribui a fornecedor
3. Status muda para `assigned`

**Resultado Esperado**:
- Status atualizado
- Fornecedor recebe notificação
- Histórico atualizado

#### Teste 10.2.2: Resolver Ocorrência

**Passos**:
1. Ocorrência em progresso
2. Marcar como resolvida
3. Adicionar comentário final

**Resultado Esperado**:
- Status = `resolved`
- Data de resolução definida
- Condómino notificado

### 10.3 Comentários e Histórico

#### Teste 10.3.1: Adicionar Comentário

**Passos**:
1. Ocorrência existente
2. Adicionar comentário: "Fornecedor contactado"
3. Salvar

**Resultado Esperado**:
- Comentário adicionado
- Histórico atualizado
- Notificações enviadas

---

## 11. Assembleias

### 11.1 Criação e Gestão

#### Teste 11.1.1: Criar Assembleia

**Objetivo**: Verificar criação de assembleia geral.

**Passos**:
1. Aceder a `/condominiums/[id]/assemblies/create`
2. Preencher:
   - Tipo: "Assembleia Geral"
   - Data: 2025-02-15
   - Hora: 18:00
   - Local: "Sala de Reuniões"
   - Adicionar pontos de ordem do dia
3. Salvar

**Resultado Esperado**:
- Assembleia criada
- Status = `scheduled`
- Pontos de ordem do dia criados

**Verificação na BD**:
```sql
SELECT * FROM assemblies 
WHERE condominium_id = [condominium_id] 
ORDER BY created_at DESC LIMIT 1;
```

### 11.2 Convocatórias

#### Teste 11.2.1: Enviar Convocatória

**Passos**:
1. Assembleia criada
2. Clicar em "Enviar Convocatória"
3. Confirmar envio

**Resultado Esperado**:
- PDF de convocatória gerado
- Email enviado a todos os condóminos
- `convocation_sent_at` definido
- Link para PDF disponível

### 11.3 Registo de Presenças

#### Teste 11.3.1: Iniciar Assembleia

**Passos**:
1. Data da assembleia chegou
2. Clicar em "Iniciar Assembleia"
3. Status muda para `in_progress`

**Resultado Esperado**:
- Status = `in_progress`
- Registo de presenças disponível
- Votações podem ser iniciadas

#### Teste 11.3.2: Registar Presença

**Passos**:
1. Assembleia em curso
2. Registar presença de condómino
3. Verificar quórum

**Resultado Esperado**:
- Presença registada
- Quórum calculado automaticamente
- Procuração pode ser registada

### 11.4 Geração de Atas

#### Teste 11.4.1: Gerar Template de Ata

**Passos**:
1. Assembleia concluída
2. Clicar em "Gerar Ata"
3. Template preenchido automaticamente

**Resultado Esperado**:
- Template gerado com dados da assembleia
- Pontos de ordem do dia incluídos
- Resultados de votações incluídos
- Edição disponível

#### Teste 11.4.2: Editar e Aprovar Ata

**Passos**:
1. Template de ata gerado
2. Editar conteúdo
3. Enviar para revisão
4. Aprovar ata

**Resultado Esperado**:
- Ata editável
- Workflow de aprovação funcionando
- Versões mantidas
- Ata final aprovada

---

## 12. Votações

### 12.1 Votações em Assembleias

#### Teste 12.1.1: Criar Tópico de Votação

**Objetivo**: Verificar criação de tópico de votação em assembleia.

**Passos**:
1. Assembleia em curso
2. Criar tópico: "Aprovar orçamento 2025"
3. Adicionar opções: "A Favor", "Contra", "Abstenção"
4. Salvar

**Resultado Esperado**:
- Tópico criado
- Opções de voto criadas
- Votação pode ser iniciada

**Verificação na BD**:
```sql
SELECT * FROM vote_topics 
WHERE assembly_id = [assembly_id] 
ORDER BY created_at DESC LIMIT 1;
```

#### Teste 12.1.2: Votação Ponderada por Permilagem

**Passos**:
1. Iniciar votação
2. Condómino A (50 permilagem) vota "A Favor"
3. Condómino B (100 permilagem) vota "Contra"
4. Ver resultados

**Resultado Esperado**:
- Votos registados
- Resultado ponderado: 50 a favor, 100 contra
- Percentagens calculadas corretamente

#### Teste 12.1.3: Votação em Massa

**Passos**:
1. Múltiplos tópicos de votação
2. Selecionar vários
3. Votar em massa

**Resultado Esperado**:
- Votos aplicados a todos os tópicos selecionados
- Histórico mantido

### 12.2 Votações Independentes

#### Teste 12.2.1: Criar Votação Independente

**Passos**:
1. Aceder a `/condominiums/[id]/votes/create`
2. Criar votação não relacionada com assembleia
3. Definir período de votação
4. Publicar

**Resultado Esperado**:
- Votação criada
- Status = `open`
- Condóminos podem votar
- Resultados visíveis em tempo real

---

## 13. Reservas de Espaços

### 13.1 Definição de Espaços

#### Teste 13.1.1: Criar Espaço Comum

**Objetivo**: Verificar criação de espaço comum para reservas.

**Passos**:
1. Aceder a `/condominiums/[id]/spaces/create`
2. Preencher:
   - Nome: "Sala de Festas"
   - Capacidade: 50 pessoas
   - Regras: "Proibido fumar"
   - Caução: €100.00
3. Salvar

**Resultado Esperado**:
- Espaço criado
- Disponível para reservas
- Regras visíveis

**Verificação na BD**:
```sql
SELECT * FROM spaces 
WHERE condominium_id = [condominium_id] 
AND name = 'Sala de Festas';
```

### 13.2 Criação de Reservas

#### Teste 13.2.1: Condómino Reserva Espaço

**Passos**:
1. Login como condómino
2. Aceder a `/condominiums/[id]/reservations/create`
3. Selecionar espaço
4. Definir data e hora
5. Submeter

**Resultado Esperado**:
- Reserva criada
- Status = `pending` (requer aprovação)
- Admin notificado
- Conflitos verificados

### 13.3 Aprovação de Reservas

#### Teste 13.3.1: Aprovar Reserva

**Passos**:
1. Reserva pendente
2. Admin aprova
3. Confirmar

**Resultado Esperado**:
- Status = `approved`
- Condómino notificado
- Calendário atualizado

#### Teste 13.3.2: Bloquear Espaço

**Passos**:
1. Espaço disponível
2. Admin bloqueia para manutenção
3. Período de bloqueio definido

**Resultado Esperado**:
- Espaço bloqueado
- Reservas não permitidas durante bloqueio
- Motivo visível

---

## 14. Fornecedores e Contratos

### 14.1 Gestão de Fornecedores

#### Teste 14.1.1: Criar Fornecedor

**Objetivo**: Verificar criação de fornecedor.

**Passos**:
1. Aceder a `/condominiums/[id]/suppliers/create`
2. Preencher:
   - Nome: "Empresa Manutenção XYZ"
   - NIF: "123456789"
   - Contacto: "+351912345678"
   - Email: "contacto@xyz.pt"
   - Especialidade: "Manutenção"
3. Salvar

**Resultado Esperado**:
- Fornecedor criado
- Disponível para associação a despesas
- Contratos podem ser criados

**Verificação na BD**:
```sql
SELECT * FROM suppliers 
WHERE condominium_id = [condominium_id] 
AND name = 'Empresa Manutenção XYZ';
```

### 14.2 Gestão de Contratos

#### Teste 14.2.1: Criar Contrato

**Passos**:
1. Fornecedor existente
2. Criar contrato:
   - Tipo: "Prestação de Serviços"
   - Valor: €500/mês
   - Início: 2025-01-01
   - Fim: 2025-12-31
   - Upload de documento
3. Salvar

**Resultado Esperado**:
- Contrato criado
- Documento guardado
- Alertas de renovação configurados

#### Teste 14.2.2: Alerta de Renovação

**Passos**:
1. Contrato próximo do fim (30 dias)
2. Verificar alertas

**Resultado Esperado**:
- Alerta gerado
- Admin notificado
- Lista de contratos a renovar visível

---

## 15. Mensagens Internas

### 15.1 Criação de Mensagens

#### Teste 15.1.1: Condómino Envia Mensagem

**Objetivo**: Verificar envio de mensagem/ticket interno.

**Passos**:
1. Login como condómino
2. Aceder a `/condominiums/[id]/messages/create`
3. Preencher:
   - Assunto: "Dúvida sobre quotas"
   - Mensagem: "Gostaria de esclarecimento..."
   - Anexar documento (opcional)
4. Enviar

**Resultado Esperado**:
- Mensagem criada
- Status = `open`
- Admin notificado
- Thread iniciada

**Verificação na BD**:
```sql
SELECT * FROM messages 
WHERE condominium_id = [condominium_id] 
ORDER BY created_at DESC LIMIT 1;
```

### 15.2 Respostas e Threads

#### Teste 15.2.1: Responder a Mensagem

**Passos**:
1. Mensagem existente
2. Admin responde
3. Adicionar resposta

**Resultado Esperado**:
- Resposta adicionada ao thread
- Condómino notificado
- Status pode mudar para `closed`

---

## 16. Notificações

### 16.1 Criação Automática

#### Teste 16.1.1: Notificação de Nova Quota

**Objetivo**: Verificar criação automática de notificação.

**Passos**:
1. Quota gerada para fração
2. Verificar notificação criada

**Resultado Esperado**:
- Notificação criada automaticamente
- Condómino recebe notificação
- Tipo = `fee_created`

**Verificação na BD**:
```sql
SELECT * FROM notifications 
WHERE user_id = [user_id] 
AND type = 'fee_created' 
ORDER BY created_at DESC LIMIT 1;
```

### 16.2 Gestão de Notificações

#### Teste 16.2.1: Marcar como Lida

**Passos**:
1. Notificação não lida
2. Clicar em "Marcar como Lida"

**Resultado Esperado**:
- `read_at` definido
- Contador atualizado
- Notificação removida da lista de não lidas

#### Teste 16.2.2: Marcar Todas como Lidas

**Passos**:
1. Múltiplas notificações não lidas
2. Clicar em "Marcar Todas como Lidas"

**Resultado Esperado**:
- Todas marcadas como lidas
- Contador = 0

#### Teste 16.2.3: Filtragem por Condomínio

**Passos**:
1. Utilizador com acesso a múltiplos condomínios
2. Ver notificações

**Resultado Esperado**:
- Apenas notificações dos condomínios acessíveis
- Filtro por condomínio funciona

---

## 17. Perfil de Utilizador

### 17.1 Edição de Dados

#### Teste 17.1.1: Atualizar Perfil

**Objetivo**: Verificar atualização de dados pessoais.

**Passos**:
1. Aceder a `/profile`
2. Editar:
   - Nome: "João Silva Atualizado"
   - Telefone: "+351987654321"
3. Salvar

**Resultado Esperado**:
- Dados atualizados
- `updated_at` atualizado
- Alterações visíveis imediatamente

**Verificação na BD**:
```sql
SELECT name, phone FROM users WHERE id = [user_id];
```

### 17.2 Alteração de Senha

#### Teste 17.2.1: Alterar Senha

**Passos**:
1. Aceder a `/profile`
2. Secção "Alterar Senha"
3. Inserir:
   - Senha atual: `OldPass123!@#`
   - Nova senha: `NewPass123!@#`
   - Confirmar nova senha
4. Salvar

**Resultado Esperado**:
- Senha atualizada
- Hash atualizado na BD
- Login possível com nova senha
- Email de confirmação enviado

### 17.3 Preferências de Email

#### Teste 17.3.1: Configurar Preferências

**Passos**:
1. Aceder a preferências de email
2. Desativar notificações de quotas
3. Manter notificações de ocorrências
4. Salvar

**Resultado Esperado**:
- Preferências guardadas
- Notificações respeitam preferências
- Email de confirmação não enviado para quotas

**Verificação na BD**:
```sql
SELECT * FROM user_email_preferences 
WHERE user_id = [user_id];
```

### 17.4 Gestão de API Keys

#### Teste 17.4.1: Gerar API Key

**Objetivo**: Verificar geração de chave API para acesso REST.

**Passos**:
1. Aceder a `/api-keys`
2. Clicar em "Gerar Nova Chave"
3. Definir nome: "Integração Externa"
4. Confirmar

**Resultado Esperado**:
- API key gerada
- Hash guardado na BD
- Chave exibida uma vez (copiar)
- Lista de chaves atualizada

**Verificação na BD**:
```sql
SELECT * FROM api_keys 
WHERE user_id = [user_id] 
ORDER BY created_at DESC LIMIT 1;
```

#### Teste 17.4.2: Revogar API Key

**Passos**:
1. API key existente
2. Revogar chave
3. Confirmar

**Resultado Esperado**:
- Chave revogada
- Acesso API bloqueado
- `revoked_at` definido

---

## 18. API REST

### 18.1 Autenticação

#### Teste 18.1.1: Autenticação com API Key

**Objetivo**: Verificar autenticação via API key.

**Passos**:
1. Obter API key válida
2. Fazer requisição:
   ```
   GET /api/condominiums
   Headers:
     Authorization: Bearer [api_key]
   ```

**Resultado Esperado**:
- Autenticação bem-sucedida
- Dados retornados
- Status 200

#### Teste 18.1.2: API Key Inválida

**Passos**:
1. Usar API key inválida
2. Fazer requisição

**Resultado Esperado**:
- Status 401 Unauthorized
- Mensagem de erro apropriada

### 18.2 Endpoints de Condomínios

#### Teste 18.2.1: Listar Condomínios

**Passos**:
```
GET /api/condominiums
Authorization: Bearer [api_key]
```

**Resultado Esperado**:
- Lista de condomínios acessíveis
- Formato JSON
- Paginação (se aplicável)

#### Teste 18.2.2: Obter Condomínio Específico

**Passos**:
```
GET /api/condominiums/[id]
Authorization: Bearer [api_key]
```

**Resultado Esperado**:
- Dados do condomínio
- Apenas se tiver acesso

### 18.3 Endpoints Financeiros

#### Teste 18.3.1: Listar Quotas

**Passos**:
```
GET /api/condominiums/[id]/fees
Authorization: Bearer [api_key]
```

**Resultado Esperado**:
- Lista de quotas
- Filtros disponíveis (período, status)

#### Teste 18.3.2: Obter Quota Específica

**Passos**:
```
GET /api/fees/[id]
Authorization: Bearer [api_key]
```

**Resultado Esperado**:
- Dados completos da quota
- Pagamentos associados

### 18.4 Rate Limiting

#### Teste 18.4.1: Exceder Limite de Requisições

**Passos**:
1. Fazer 100 requisições em 1 minuto
2. Verificar resposta

**Resultado Esperado**:
- Após limite, Status 429 Too Many Requests
- Headers indicam tempo de espera
- Limite resetado após período

---

## 19. Sistema de Pagamentos

### 19.1 Multibanco

#### Teste 19.1.1: Gerar Referência Multibanco

**Objetivo**: Verificar geração de referência Multibanco.

**Passos**:
1. Criar pagamento de subscrição
2. Selecionar método "Multibanco"
3. Gerar referência

**Resultado Esperado**:
- Entidade gerada
- Referência gerada
- Valor correto
- Data de validade definida

### 19.2 MBWay

#### Teste 19.2.1: Gerar Pagamento MBWay

**Passos**:
1. Selecionar método "MBWay"
2. Inserir número de telemóvel
3. Gerar pagamento

**Resultado Esperado**:
- Referência MBWay gerada
- Notificação enviada ao telemóvel
- Link de pagamento disponível

### 19.3 Webhooks IfThenPay

#### Teste 19.3.1: Processar Callback de Pagamento

**Objetivo**: Verificar processamento de webhook.

**Passos**:
1. Simular callback:
   ```
   POST /webhooks/ifthenpay
   {
     "reference": "[reference]",
     "status": "paid",
     "amount": 25.00
   }
   ```

**Resultado Esperado**:
- Pagamento atualizado
- Subscrição ativada
- Email de confirmação enviado
- Log registado

---

## 20. Dashboard

### 20.1 Dashboard do Administrador

#### Teste 20.1.1: Visualizar Estatísticas

**Objetivo**: Verificar exibição de estatísticas no dashboard.

**Passos**:
1. Login como admin
2. Aceder a `/dashboard`
3. Verificar métricas

**Resultado Esperado**:
- Saldo atual
- Quotas pendentes
- Condóminos em atraso
- Próximas despesas
- Gráficos e visualizações

### 20.2 Dashboard do Condómino

#### Teste 20.2.1: Visualizar Informações Pessoais

**Passos**:
1. Login como condómino
2. Aceder a dashboard
3. Verificar informações

**Resultado Esperado**:
- Saldo da fração
- Quotas pendentes
- Próximos eventos
- Documentos recentes

---

## 21. Sistema Demo

### 21.1 Acesso Demo

#### Teste 21.1.1: Solicitar Acesso Demo

**Objetivo**: Verificar solicitação de acesso demo.

**Passos**:
1. Aceder a `/demo/access`
2. Inserir email
3. Solicitar acesso

**Resultado Esperado**:
- Token gerado
- Email enviado com link
- Token válido por período limitado

#### Teste 21.1.2: Acesso com Token

**Passos**:
1. Obter token válido
2. Aceder a `/demo/access/token?token=[token]`
3. Login automático como demo

**Resultado Esperado**:
- Login automático
- Acesso a conta demo
- Dados de demonstração carregados

### 21.2 Proteção de Dados Demo

#### Teste 21.2.1: Restauração Automática

**Objetivo**: Verificar que dados demo são restaurados.

**Passos**:
1. Modificar dados na conta demo
2. Aguardar restauração automática (ou executar manualmente)
3. Verificar restauração

**Resultado Esperado**:
- Dados restaurados ao estado original
- Alterações perdidas
- Sistema demo sempre consistente

---

## 22. Super Admin

### 22.1 Gestão de Utilizadores

#### Teste 22.1.1: Listar Utilizadores

**Passos**:
1. Login como super_admin
2. Aceder a `/admin/users`
3. Verificar lista

**Resultado Esperado**:
- Todos os utilizadores listados
- Filtros disponíveis
- Ações administrativas

#### Teste 22.1.2: Atribuir Super Admin

**Passos**:
1. Utilizador existente
2. Atribuir role super_admin
3. Confirmar

**Resultado Esperado**:
- Role atualizado
- Acesso administrativo concedido
- Log de auditoria

### 22.2 Gestão de Subscrições

#### Teste 22.2.1: Ativar Subscrição Manualmente

**Passos**:
1. Subscrição pendente
2. Ativar manualmente
3. Definir período

**Resultado Esperado**:
- Subscrição ativada
- Período definido
- Acesso concedido

### 22.3 Gestão de Planos

#### Teste 22.3.1: Criar Plano

**Passos**:
1. Aceder a `/admin/plans/create`
2. Criar novo plano
3. Definir pricing tiers
4. Salvar

**Resultado Esperado**:
- Plano criado
- Pricing tiers associados
- Disponível para seleção

### 22.4 Logs de Auditoria

#### Teste 22.4.1: Visualizar Logs

**Passos**:
1. Aceder a `/admin/audit-logs`
2. Filtrar por utilizador/condomínio
3. Verificar registos

**Resultado Esperado**:
- Logs exibidos
- Filtros funcionando
- Detalhes completos

---

## 23. Segurança e Compliance

### 23.1 Proteção CSRF

#### Teste 23.1.1: Tentativa sem Token CSRF

**Passos**:
1. Formulário sem token CSRF
2. Submeter formulário

**Resultado Esperado**:
- Erro 403 ou validação falha
- Ação não executada
- Mensagem de erro

### 23.2 Rate Limiting

#### Teste 23.2.1: Rate Limiting em Login

**Passos**:
1. Múltiplas tentativas de login falhadas
2. Verificar bloqueio

**Resultado Esperado**:
- Após X tentativas, bloqueio temporário
- Mensagem apropriada
- Bloqueio por IP/email

### 23.3 Validação de Inputs

#### Teste 23.3.1: SQL Injection

**Passos**:
1. Tentar inserir: `'; DROP TABLE users; --`
2. Verificar comportamento

**Resultado Esperado**:
- Input sanitizado
- Query preparada (prepared statements)
- Sem execução de código malicioso

#### Teste 23.3.2: XSS (Cross-Site Scripting)

**Passos**:
1. Tentar inserir: `<script>alert('XSS')</script>`
2. Verificar exibição

**Resultado Esperado**:
- Input sanitizado
- HTML escapado na exibição
- Script não executado

### 23.4 GDPR Compliance

#### Teste 23.4.1: Consentimento de Cookies

**Passos**:
1. Primeira visita ao site
2. Verificar banner de cookies
3. Aceitar/rejeitar

**Resultado Esperado**:
- Banner exibido
- Preferência guardada
- Cookies respeitam preferência

#### Teste 23.4.2: Exportar Dados do Utilizador

**Passos**:
1. Utilizador solicita exportação de dados
2. Verificar ficheiro gerado

**Resultado Esperado**:
- Dados exportados em formato legível
- Todos os dados pessoais incluídos
- Formato JSON ou similar

---

## 24. Integrações

### 24.1 Google OAuth

#### Teste 24.1.1: Login com Google

**Passos**:
1. Clicar em "Continuar com Google"
2. Autenticar com conta Google
3. Verificar login

**Resultado Esperado**:
- Autenticação bem-sucedida
- Dados do Google obtidos
- Conta criada ou login realizado

### 24.2 IfThenPay

#### Teste 24.2.1: Integração de Pagamentos

**Passos**:
1. Criar pagamento
2. Processar via IfThenPay
3. Verificar callback

**Resultado Esperado**:
- Comunicação com API IfThenPay
- Referências geradas
- Webhooks processados

### 24.3 PHPMailer

#### Teste 24.3.1: Envio de Email

**Passos**:
1. Ação que dispara email (ex: convite)
2. Verificar envio

**Resultado Esperado**:
- Email enviado
- Template correto
- Destinatário correto
- Log de envio

---

## 25. Performance e Escalabilidade

### 25.1 Carga de Dados

#### Teste 25.1.1: Listagem com Muitos Registos

**Objetivo**: Verificar performance com grande volume de dados.

**Passos**:
1. Condomínio com 1000+ frações
2. Listar frações
3. Medir tempo de resposta

**Resultado Esperado**:
- Paginação implementada
- Tempo de resposta < 2 segundos
- Queries otimizadas

### 25.2 Queries Otimizadas

#### Teste 25.2.1: Verificar Índices

**Passos**:
1. Analisar queries lentas
2. Verificar índices na BD
3. Otimizar se necessário

**Resultado Esperado**:
- Índices apropriados
- Queries rápidas
- EXPLAIN mostra uso de índices

### 25.3 Cache

#### Teste 25.3.1: Cache de Dados Estáticos

**Passos**:
1. Dados que raramente mudam (ex: planos)
2. Implementar cache
3. Verificar performance

**Resultado Esperado**:
- Cache funcionando
- Redução de queries
- Performance melhorada

### 25.4 Importação em Lote

#### Teste 25.4.1: Importar Múltiplas Transações

**Passos**:
1. CSV com 1000+ transações
2. Importar
3. Verificar performance

**Resultado Esperado**:
- Importação em lote eficiente
- Progresso visível
- Sem timeout
- Dados corretos

---

## Conclusão

Este documento cobre todos os módulos e funcionalidades principais do Sistema de Gestão de Condomínios SaaS. Para testes completos:

1. **Execute os testes sistematicamente** por módulo
2. **Documente resultados** e problemas encontrados
3. **Verifique integrações** entre módulos
4. **Teste edge cases** e cenários limite
5. **Valide segurança** em todas as funcionalidades
6. **Monitore performance** em operações críticas

### Checklist Geral de Validação

- [ ] Autenticação e autorização funcionando
- [ ] Subscrições e planos corretos
- [ ] Gestão de condomínios e frações completa
- [ ] Módulo financeiro integrado
- [ ] Documentos e ocorrências funcionais
- [ ] Assembleias e votações operacionais
- [ ] Reservas e fornecedores funcionando
- [ ] API REST documentada e testada
- [ ] Pagamentos integrados
- [ ] Segurança validada
- [ ] Performance aceitável
- [ ] Compliance GDPR verificado

---

**Última atualização**: 2025-02-06
**Versão do Documento**: 1.0
