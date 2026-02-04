# Guia de Testes - Account Type Selection and Trial Management

Este guia descreve como testar todas as funcionalidades implementadas relacionadas com seleção de tipo de conta, subscrições e gestão de trial.

## Pré-requisitos

1. Base de dados configurada com todas as migrações aplicadas
2. Tabela `users` com colunas `google_id` e `auth_provider`
3. Tabela `subscriptions` com coluna `trial_ends_at`
4. Planos de subscrição criados na base de dados

## Script de Verificação Automática

Execute o script de verificação antes de começar os testes manuais:

```bash
php cli/test-account-flow.php
```

Este script verifica:
- Schema da base de dados
- Métodos dos models e services
- Existência de views
- Registro de rotas
- Integração do middleware

## Testes Manuais

### Teste 1: Registo Normal - Conta User (Condomino)

**Objetivo:** Verificar que utilizadores normais podem criar conta sem subscrição

**Passos:**
1. Aceder a `http://localhost/predio/register`
2. Selecionar opção "Utilizador (Condomino)"
3. Preencher todos os campos obrigatórios
4. Submeter formulário

**Resultado esperado:**
- Conta criada com role `condomino`
- Login automático realizado
- Redirecionamento para dashboard
- Sem subscrição criada na base de dados
- Acesso completo a todas as funcionalidades

**Verificação na BD:**
```sql
SELECT id, email, role FROM users WHERE email = 'teste@example.com';
-- Verificar que role = 'condomino'
SELECT * FROM subscriptions WHERE user_id = [user_id];
-- Deve retornar vazio
```

### Teste 2: Registo Normal - Conta Admin

**Objetivo:** Verificar que admins são redirecionados para seleção de plano

**Passos:**
1. Aceder a `http://localhost/predio/register`
2. Selecionar opção "Administrador"
3. Preencher todos os campos obrigatórios
4. Submeter formulário
5. Verificar redirecionamento para `/auth/select-plan`
6. Selecionar um plano (ex: START)
7. Submeter seleção

**Resultado esperado:**
- Redirecionamento para `/auth/select-plan` após registo
- Conta criada com role `admin` após seleção de plano
- Trial de 14 dias iniciado
- Redirecionamento para `/subscription`
- Trial ativo visível na página de subscrição

**Verificação na BD:**
```sql
SELECT id, email, role FROM users WHERE email = 'admin@example.com';
-- Verificar que role = 'admin'
SELECT * FROM subscriptions WHERE user_id = [user_id];
-- Verificar que status = 'trial' e trial_ends_at está definido
```

### Teste 3: Login Google - Novo Utilizador (User)

**Objetivo:** Verificar fluxo Google OAuth para novos utilizadores tipo user

**Passos:**
1. Aceder a `http://localhost/predio/register`
2. Clicar em "Continuar com Google"
3. Autenticar com conta Google que não existe no sistema
4. Verificar redirecionamento para `/auth/select-account-type`
5. Selecionar "Utilizador (Condomino)"
6. Submeter

**Resultado esperado:**
- Conta criada com `google_id` e `auth_provider = 'google'`
- Sem subscrição criada
- Login automático
- Acesso completo

**Verificação na BD:**
```sql
SELECT id, email, role, google_id, auth_provider FROM users WHERE email = '[google_email]';
-- Verificar google_id e auth_provider preenchidos
```

### Teste 4: Login Google - Novo Utilizador (Admin)

**Objetivo:** Verificar fluxo Google OAuth para novos utilizadores tipo admin

**Passos:**
1. Aceder a `http://localhost/predio/register`
2. Clicar em "Continuar com Google"
3. Autenticar com conta Google que não existe no sistema
4. Selecionar "Administrador"
5. Selecionar plano
6. Submeter

**Resultado esperado:**
- Conta criada com Google OAuth
- Trial iniciado
- Acesso completo durante trial

### Teste 5: Bloqueio de Acesso - Trial Expirado

**Objetivo:** Verificar que admins com trial expirado são bloqueados

**Passos:**
1. Criar conta admin com trial (usar Teste 2)
2. Na base de dados, atualizar trial_ends_at para data passada:
   ```sql
   UPDATE subscriptions
   SET trial_ends_at = DATE_SUB(NOW(), INTERVAL 1 DAY)
   WHERE user_id = [admin_user_id] AND status = 'trial';
   ```
3. Fazer logout e login novamente
4. Tentar aceder a `/dashboard`
5. Tentar aceder a `/subscription`
6. Tentar aceder a `/profile`
7. Tentar aceder a `/condominiums/create`

**Resultado esperado:**
- `/dashboard` → redireciona para `/subscription` com mensagem de erro
- `/subscription` → acessível
- `/profile` → acessível
- `/condominiums/create` → redireciona para `/subscription`
- Mensagem: "O seu período experimental expirou..."

### Teste 6: Utilizador Normal - Sem Restrições

**Objetivo:** Verificar que utilizadores normais não têm restrições

**Passos:**
1. Fazer login como utilizador tipo "user" (condomino)
2. Aceder a várias páginas:
   - `/dashboard`
   - `/notifications`
   - `/profile`
   - Qualquer outra página

**Resultado esperado:**
- Todas as páginas acessíveis
- Sem verificação de subscrição
- Sem bloqueios

### Teste 7: Notificações Filtradas por Condomínio

**Objetivo:** Verificar que utilizadores só veem notificações dos seus condomínios

**Passos:**
1. Criar dois condomínios diferentes (C1 e C2)
2. Criar utilizador U1 associado apenas a C1
3. Criar notificações:
   - Notificação 1 para C1
   - Notificação 2 para C2
4. Fazer login como U1
5. Aceder a `/notifications`

**Resultado esperado:**
- U1 vê apenas notificação 1 (do C1)
- U1 não vê notificação 2 (do C2)

**Verificação na BD:**
```sql
-- Criar notificações de teste
INSERT INTO notifications (user_id, condominium_id, type, title, message)
VALUES
  ([user_id], [condominium_1_id], 'test', 'Notificação C1', 'Teste'),
  ([user_id], [condominium_2_id], 'test', 'Notificação C2', 'Teste');
```

### Teste 8: Utilizador Normal Não Pode Criar Condomínio

**Objetivo:** Verificar que utilizadores normais não veem opção de criar condomínio

**Passos:**
1. Fazer login como utilizador tipo "user"
2. Aceder a `/dashboard`
3. Verificar se há card/botão "Novo Condomínio"
4. Tentar aceder diretamente a `/condominiums/create`

**Resultado esperado:**
- Dashboard não mostra card/botão de criar condomínio
- Acesso a `/condominiums/create` é bloqueado (redireciona ou erro 403)

### Teste 9: Admin Pode Criar Condomínio

**Objetivo:** Verificar que admins veem card de criar condomínio

**Passos:**
1. Fazer login como admin
2. Aceder a `/dashboard`
3. Verificar se há card "Novo Condomínio" no grid
4. Clicar no card
5. Verificar acesso à página de criação

**Resultado esperado:**
- Card "Novo Condomínio" visível no grid de condomínios
- Card tem ícone de + e texto "Novo Condomínio"
- Clicar no card leva para `/condominiums/create`
- Página de criação acessível

### Teste 10: Logout Redireciona para Homepage

**Objetivo:** Verificar que logout redireciona para homepage

**Passos:**
1. Fazer login
2. Clicar no botão de logout no header (ícone de sair)
3. Verificar redirecionamento

**Resultado esperado:**
- Redirecionamento para homepage (`/`)
- Sessão terminada
- Mensagem de sucesso (opcional)

### Teste 11: Botão de Logout no Header

**Objetivo:** Verificar que botão de logout aparece no header

**Passos:**
1. Fazer login
2. Verificar header
3. Verificar se há botão de logout ao lado das notificações

**Resultado esperado:**
- Botão de logout visível no header
- Posicionado entre notificações e menu lateral
- Ícone de sair (box-arrow-right)
- Cor vermelha (outline-danger)

## Problemas Conhecidos e Soluções

### Problema: Trial não expira corretamente

**Solução:** Verificar que `trial_ends_at` está definido e é uma data válida:
```sql
SELECT id, user_id, status, trial_ends_at,
       DATEDIFF(NOW(), trial_ends_at) as days_expired
FROM subscriptions
WHERE user_id = [user_id];
```

### Problema: Utilizador vê notificações de outros condomínios

**Solução:** Verificar que o método `userHasAccessToCondominium` está a funcionar corretamente. Verificar associações:
```sql
-- Para admin
SELECT id FROM condominiums WHERE user_id = [user_id] AND id = [condominium_id];

-- Para condomino
SELECT id FROM condominium_users
WHERE user_id = [user_id]
AND condominium_id = [condominium_id]
AND (ended_at IS NULL OR ended_at > CURDATE());
```

### Problema: Admin sem subscrição tem acesso

**Solução:** Verificar que SubscriptionMiddleware está a bloquear corretamente. Um admin sem subscrição deve ser redirecionado para `/subscription`.

## Checklist Final

Após completar todos os testes, verificar:

- [ ] Todos os fluxos de registo funcionam
- [ ] Google OAuth funciona para novos e existentes utilizadores
- [ ] Trial é iniciado apenas para admins
- [ ] Trial expirado bloqueia acesso corretamente
- [ ] Utilizadores normais não têm restrições
- [ ] Notificações são filtradas por condomínio
- [ ] Utilizadores normais não podem criar condomínios
- [ ] Admins veem card de criar condomínio
- [ ] Logout funciona corretamente
- [ ] Botão de logout aparece no header

## Notas Adicionais

- Para testar trial expirado, pode modificar diretamente na BD ou criar um script que altera a data
- Para testar Google OAuth, precisa de credenciais OAuth configuradas no `.env`
- Verificar logs do servidor para erros durante os testes
