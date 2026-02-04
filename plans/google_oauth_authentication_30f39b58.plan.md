---
name: Google OAuth Authentication
overview: Implementar autenticação OAuth com Google para permitir login e registro usando contas Gmail, integrando com o sistema de autenticação existente.
todos:
  - id: install-google-oauth
    content: Instalar biblioteca google/apiclient via composer
    status: completed
  - id: create-migration
    content: Criar migration para adicionar google_id e auth_provider na tabela users
    status: completed
  - id: update-env
    content: Adicionar variáveis GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET e GOOGLE_REDIRECT_URI no env.example
    status: completed
  - id: create-oauth-service
    content: Criar GoogleOAuthService para gerir fluxo OAuth
    status: completed
  - id: update-auth-controller
    content: Adicionar métodos googleAuth() e googleCallback() no AuthController
    status: completed
  - id: update-user-model
    content: Atualizar User model para suportar google_id e auth_provider
    status: completed
  - id: add-routes
    content: Adicionar rotas /auth/google e /auth/google/callback
    status: completed
  - id: update-login-view
    content: Adicionar botão 'Continuar com Google' na view de login
    status: completed
  - id: update-register-view
    content: Adicionar botão 'Continuar com Google' na view de registro
    status: completed
  - id: test-oauth-flow
    content: Testar fluxo completo de login e registro via Google
    status: completed
---

# Implementação de Autenticação Google OAuth

## Visão Geral

Adicionar suporte para login e registro via Google OAuth, permitindo que utilizadores se autentiquem com contas Gmail sem necessidade de criar senha.

## Estrutura Atual

- Sistema de autenticação em `app/Controllers/AuthController.php`
- Modelo de utilizador em `app/Models/User.php`
- Tabela `users` com campos: email, password, name, role, etc.
- Views: `app/Views/pages/login.html.twig` e `app/Views/pages/register.html.twig`
- Configurações em `.env` (via `config.php`)

## Implementação

### 1. Dependências

- Adicionar `google/apiclient` ao `composer.json`
- Executar `composer install` para instalar a biblioteca

### 2. Base de Dados

**Arquivo:** `database/migrations/XXX_add_google_oauth_to_users.php`

- Adicionar coluna `google_id VARCHAR(255) NULL` para armazenar ID do Google
- Adicionar coluna `auth_provider ENUM('local', 'google') DEFAULT 'local'` para identificar método de autenticação
- Adicionar índice em `google_id` para buscas rápidas
- Permitir `password` ser NULL para utilizadores que só usam Google OAuth

### 3. Configuração

**Arquivo:** `env.example` e documentação

- Adicionar variáveis:
- `GOOGLE_CLIENT_ID`
- `GOOGLE_CLIENT_SECRET`
- `GOOGLE_REDIRECT_URI` (ex: `http://localhost/predio/auth/google/callback`)

### 4. Serviço OAuth

**Arquivo:** `app/Services/GoogleOAuthService.php` (novo)

- Classe para gerir fluxo OAuth do Google
- Métodos:
- `getAuthUrl()`: Gera URL de autorização do Google
- `handleCallback($code)`: Processa callback e obtém dados do utilizador
- `getUserInfo($accessToken)`: Obtém informações do perfil Google

### 5. Controller - Rotas OAuth

**Arquivo:** `app/Controllers/AuthController.php`

- Adicionar método `googleAuth()`: Redireciona para Google OAuth
- Adicionar método `googleCallback()`: Processa resposta do Google
- Se email existe: fazer login
- Se email não existe: criar conta automaticamente
- Definir `auth_provider = 'google'` e `google_id`
- Marcar `email_verified_at` como verificado (Google já verifica)

### 6. Modelo User

**Arquivo:** `app/Models/User.php`

- Atualizar `create()` para aceitar `google_id` e `auth_provider`
- Adicionar método `findByGoogleId($googleId)`: Buscar por Google ID
- Atualizar validação para permitir password NULL quando `auth_provider = 'google'`
- Adicionar método `linkGoogleAccount($userId, $googleId)`: Vincular conta Google a conta existente

### 7. Rotas

**Arquivo:** `routes.php`

- `GET /auth/google`: Iniciar autenticação Google
- `GET /auth/google/callback`: Callback do Google OAuth

### 8. Views

**Arquivos:**

- `app/Views/pages/login.html.twig`
- `app/Views/pages/register.html.twig`

- Adicionar botão "Continuar com Google" antes do formulário
- Estilizar botão com cores do Google (branco com borda, ícone Google)
- Adicionar separador visual ("ou" entre Google e formulário tradicional)

### 9. Segurança

- Validar estado CSRF no callback OAuth
- Verificar token de acesso do Google
- Validar domínio do email (opcional: apenas @gmail.com ou permitir qualquer domínio Google Workspace)
- Log de auditoria para logins via Google

### 10. Fluxo de Utilizador

#### Login Existente

1. Utilizador clica "Continuar com Google"
2. Redireciona para Google
3. Google retorna com código
4. Sistema busca utilizador por `google_id` ou `email`
5. Se encontrado: login automático
6. Se não encontrado mas email existe: vincular conta (opcional) ou erro

#### Registro Novo

1. Utilizador clica "Continuar com Google" na página de registro
2. Redireciona para Google
3. Google retorna com código
4. Sistema verifica se email já existe
5. Se não existe: cria conta com dados do Google (name, email, google_id)
6. Se existe: oferece login ou vincular contas

## Considerações

- Utilizadores OAuth não precisam de senha (password = NULL)
- Email já verificado pelo Google (`email_verified_at` preenchido)
- Permitir vincular conta Google a conta local existente (futuro)
- Manter compatibilidade com autenticação tradicional

## Testes

- Testar login com Google (conta existente)
- Testar registro com Google (conta nova)
- Testar tentativa de login com email já registado localmente
- Verificar que password não é obrigatório para utilizadores Google