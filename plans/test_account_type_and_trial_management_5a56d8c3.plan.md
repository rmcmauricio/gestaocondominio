---
name: Test Account Type and Trial Management
overview: Criar um plano de testes para validar o fluxo completo de seleção de tipo de conta, criação de trial para admins, e bloqueio de acesso quando o trial expira.
todos: []
---

# Test Plan: Account Type Selection and Trial Management

## Overview

Plano de testes para validar todas as funcionalidades implementadas relacionadas com seleção de tipo de conta, subscrições e gestão de trial.

## Testes a Realizar

### 1. Registo Normal - Conta User (Condomino)

**Cenário:** Utilizador cria conta tipo "user" via registo normal

**Passos:**

1. Aceder a `/register`
2. Selecionar "Utilizador (Condomino)"
3. Preencher formulário e submeter
4. Verificar que conta é criada sem subscrição
5. Verificar que utilizador é redirecionado para dashboard
6. Verificar que utilizador tem acesso completo (sem restrições)

**Resultado esperado:** Conta criada, login automático, acesso completo

### 2. Registo Normal - Conta Admin

**Cenário:** Utilizador cria conta tipo "admin" via registo normal

**Passos:**

1. Aceder a `/register`
2. Selecionar "Administrador"
3. Preencher formulário e submeter
4. Verificar redirecionamento para `/auth/select-plan`
5. Selecionar um plano (ex: START)
6. Verificar que conta é criada com trial de 14 dias
7. Verificar redirecionamento para `/subscription`
8. Verificar que trial está ativo

**Resultado esperado:** Conta admin criada, trial iniciado, acesso completo durante trial

### 3. Login Google - Novo Utilizador (User)

**Cenário:** Utilizador novo faz login com Google e escolhe tipo "user"

**Passos:**

1. Aceder a `/register`
2. Clicar em "Continuar com Google"
3. Autenticar com Google
4. Verificar redirecionamento para `/auth/select-account-type`
5. Selecionar "Utilizador (Condomino)"
6. Verificar que conta é criada sem subscrição
7. Verificar login automático

**Resultado esperado:** Conta user criada via Google, sem subscrição

### 4. Login Google - Novo Utilizador (Admin)

**Cenário:** Utilizador novo faz login com Google e escolhe tipo "admin"

**Passos:**

1. Aceder a `/register`
2. Clicar em "Continuar com Google"
3. Autenticar com Google
4. Selecionar "Administrador"
5. Verificar redirecionamento para `/auth/select-plan`
6. Selecionar plano
7. Verificar criação de conta com trial

**Resultado esperado:** Conta admin criada via Google, trial iniciado

### 5. Login Google - Utilizador Existente

**Cenário:** Utilizador existente faz login com Google

**Passos:**

1. Criar conta local primeiro
2. Fazer logout
3. Tentar login com Google (mesmo email)
4. Verificar que conta é vinculada e login é feito
5. Verificar que não é pedido tipo de conta

**Resultado esperado:** Login bem-sucedido, conta vinculada

### 6. Bloqueio de Acesso - Trial Expirado

**Cenário:** Admin com trial expirado tenta aceder a páginas

**Passos:**

1. Criar conta admin com trial
2. Modificar `trial_ends_at` na base de dados para data passada
3. Fazer login
4. Tentar aceder a `/dashboard`
5. Verificar redirecionamento para `/subscription`
6. Verificar que `/subscription` é acessível
7. Verificar que `/payments/*` é acessível
8. Verificar que `/profile` é acessível
9. Verificar que outras rotas são bloqueadas

**Resultado esperado:** Acesso bloqueado exceto subscrição, pagamento e perfil

### 7. Utilizador Normal - Sem Restrições

**Cenário:** Utilizador tipo "user" não deve ter restrições

**Passos:**

1. Criar conta tipo "user"
2. Fazer login
3. Verificar acesso a todas as páginas
4. Verificar que não há verificação de subscrição

**Resultado esperado:** Acesso completo sem restrições

### 8. Verificação de Notificações por Condomínio

**Cenário:** Utilizador só vê notificações dos seus condomínios

**Passos:**

1. Criar dois condomínios diferentes
2. Criar notificações para cada condomínio
3. Criar utilizador associado apenas ao condomínio 1
4. Fazer login com esse utilizador
5. Verificar que só vê notificações do condomínio 1
6. Verificar que não vê notificações do condomínio 2

**Resultado esperado:** Notificações filtradas por acesso ao condomínio

### 9. Utilizador Normal Não Pode Criar Condomínio

**Cenário:** Utilizador tipo "user" não deve ver botões de criar condomínio

**Passos:**

1. Fazer login como utilizador tipo "user"
2. Verificar dashboard
3. Verificar que não há botão/card "Novo Condomínio"
4. Tentar aceder diretamente a `/condominiums/create`
5. Verificar que acesso é bloqueado

**Resultado esperado:** Sem opção de criar condomínio, acesso bloqueado

### 10. Admin Pode Criar Condomínio

**Cenário:** Admin deve ver card de criar condomínio no dashboard

**Passos:**

1. Fazer login como admin
2. Verificar dashboard
3. Verificar que há card "Novo Condomínio" no grid
4. Clicar no card
5. Verificar acesso à página de criação

**Resultado esperado:** Card visível e funcional

## Checklist de Validação

- [ ] Registo user funciona sem subscrição
- [ ] Registo admin redireciona para seleção de plano
- [ ] Google OAuth pede tipo de conta para novos utilizadores
- [ ] Trial é iniciado apenas para admins
- [ ] Trial expirado bloqueia acesso (exceto subscrição/pagamento/perfil)
- [ ] Utilizadores normais não têm restrições
- [ ] Notificações são filtradas por condomínio
- [ ] Utilizadores normais não podem criar condomínios
- [ ] Admins veem card de criar condomínio
- [ ] Logout redireciona para homepage
- [ ] Botão de logout aparece no header

## Pontos de Atenção

1. **Base de Dados:** Verificar que campos `google_id` e `auth_provider` existem na tabela `users`
2. **Sessões:** Verificar que dados temporários (`pending_registration`, `google_oauth_pending`) são limpos após uso
3. **Middleware:** Verificar que SubscriptionMiddleware está a ser aplicado corretamente
4. **Rotas:** Verificar que todas as rotas estão registadas corretamente