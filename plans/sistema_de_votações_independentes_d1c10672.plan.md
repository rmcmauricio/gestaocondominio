---
name: Sistema de Votações Independentes
overview: Implementar sistema de votações independentes de assembleias, com opções de resposta configuráveis por condomínio, exibição no dashboard para votação inline e resultados das últimas 3 votações.
todos: []
---

# Sistema de Votações Independentes

## Objetivos
1. Criar votações independentes de assembleias (por condomínio)
2. Sistema de opções de resposta configurável por condomínio (padrão: "A favor", "Contra", "Abstenção")
3. Exibir votações em aberto no dashboard para votação inline
4. Mostrar resultados das últimas 3 votações no dashboard

## Estrutura de Dados

### 1. Nova Tabela: `standalone_votes`
- `id` (PK)
- `condominium_id` (FK para condominiums)
- `title` (VARCHAR 255)
- `description` (TEXT)
- `status` (ENUM: 'draft', 'open', 'closed')
- `voting_started_at` (TIMESTAMP NULL)
- `voting_ended_at` (TIMESTAMP NULL)
- `created_by` (FK para users)
- `created_at`, `updated_at`

### 2. Nova Tabela: `vote_options` (opções por condomínio)
- `id` (PK)
- `condominium_id` (FK para condominiums)
- `option_label` (VARCHAR 255) - ex: "A favor", "Contra", "Abstenção"
- `order_index` (INT) - ordem de exibição
- `is_default` (BOOLEAN) - se é uma das opções padrão
- `is_active` (BOOLEAN)
- `created_at`, `updated_at`

### 3. Atualizar `assembly_votes` para suportar votações independentes
- Adicionar `standalone_vote_id` (INT NULL, FK para standalone_votes)
- Manter `assembly_id` e `topic_id` para compatibilidade

### 4. Nova Tabela: `standalone_vote_responses`
- `id` (PK)
- `standalone_vote_id` (FK para standalone_votes)
- `fraction_id` (FK para fractions)
- `user_id` (FK para users, NULL)
- `vote_option_id` (FK para vote_options)
- `weighted_value` (DECIMAL 12,4) - baseado na permilagem
- `notes` (TEXT NULL)
- `created_at`, `updated_at`
- UNIQUE KEY: `unique_vote_fraction` (standalone_vote_id, fraction_id)

## Implementação

### Migrações
1. `038_create_vote_options_table.php` - Criar tabela de opções de resposta
2. `039_create_standalone_votes_table.php` - Criar tabela de votações independentes
3. `040_create_standalone_vote_responses_table.php` - Criar tabela de respostas
4. `041_update_assembly_votes_for_standalone.php` - Adicionar suporte a standalone_vote_id
5. `042_seed_default_vote_options.php` - Popular opções padrão para condomínios existentes

### Models
1. `app/Models/VoteOption.php` - Gerir opções de resposta por condomínio
   - `getByCondominium(int $condominiumId)`
   - `getDefaults(int $condominiumId)`
   - `create(array $data)`
   - `update(int $id, array $data)`
   - `delete(int $id)`

2. `app/Models/StandaloneVote.php` - Gerir votações independentes
   - `getByCondominium(int $condominiumId, array $filters = [])`
   - `getOpenByCondominium(int $condominiumId)` - para dashboard
   - `getRecentResults(int $condominiumId, int $limit = 3)` - últimas 3
   - `create(array $data)`
   - `update(int $id, array $data)`
   - `startVoting(int $id)`
   - `closeVoting(int $id)`
   - `getResults(int $id)` - calcular resultados

3. `app/Models/StandaloneVoteResponse.php` - Gerir respostas
   - `getByVote(int $voteId)`
   - `getByFraction(int $voteId, int $fractionId)`
   - `createOrUpdate(array $data)` - upsert
   - `getResults(int $voteId)` - resultados agregados

### Controllers
1. `app/Controllers/StandaloneVoteController.php`
   - `index(int $condominiumId)` - listar votações
   - `create(int $condominiumId)` - formulário de criação
   - `store(int $condominiumId)` - criar votação
   - `show(int $condominiumId, int $voteId)` - ver votação e resultados
   - `edit(int $condominiumId, int $voteId)` - editar (apenas draft)
   - `update(int $condominiumId, int $voteId)` - atualizar
   - `start(int $condominiumId, int $voteId)` - abrir votação
   - `close(int $condominiumId, int $voteId)` - encerrar votação
   - `delete(int $condominiumId, int $voteId)` - eliminar (apenas draft)

2. `app/Controllers/VoteOptionController.php`
   - `index(int $condominiumId)` - listar opções
   - `store(int $condominiumId)` - criar opção
   - `update(int $condominiumId, int $optionId)` - atualizar opção
   - `delete(int $condominiumId, int $optionId)` - eliminar (se não for default)

3. Atualizar `app/Controllers/DashboardController.php`
   - Adicionar `getOpenVotes(int $condominiumId)` - votações em aberto
   - Adicionar `getRecentVoteResults(int $condominiumId, int $limit = 3)` - últimas 3
   - Passar dados para views do dashboard

4. Novo `app/Controllers/DashboardVoteController.php`
   - `vote(int $condominiumId, int $voteId)` - processar voto inline do dashboard
   - Retornar JSON para atualização AJAX

### Views
1. `app/Views/pages/votes/index.html.twig` - Lista de votações independentes
2. `app/Views/pages/votes/create.html.twig` - Criar votação
3. `app/Views/pages/votes/show.html.twig` - Ver votação e resultados
4. `app/Views/pages/votes/edit.html.twig` - Editar votação
5. `app/Views/pages/vote-options/index.html.twig` - Gerir opções de resposta
6. Atualizar `app/Views/pages/dashboard/condomino.html.twig`:
   - Adicionar seção "Votações em Aberto" com cards para votar inline
   - Adicionar seção "Resultados Recentes" com últimas 3 votações
7. Atualizar `app/Views/pages/dashboard/admin.html.twig`:
   - Adicionar seção de votações em aberto (se aplicável)
   - Adicionar resultados recentes

### Integração com Assembleias
1. Atualizar `app/Models/VoteTopic.php`:
   - Modificar para usar opções de `vote_options` do condomínio
   - `getOptionsForCondominium(int $condominiumId)` - buscar opções do condomínio
2. Atualizar `app/Controllers/VoteController.php`:
   - Usar opções do condomínio ao criar tópicos de assembleia
   - Validar contra opções do condomínio

### Rotas
```php
// Votações independentes
$router->get('/condominiums/{id}/votes', 'StandaloneVoteController@index');
$router->get('/condominiums/{id}/votes/create', 'StandaloneVoteController@create');
$router->post('/condominiums/{id}/votes', 'StandaloneVoteController@store');
$router->get('/condominiums/{id}/votes/{voteId}', 'StandaloneVoteController@show');
$router->get('/condominiums/{id}/votes/{voteId}/edit', 'StandaloneVoteController@edit');
$router->post('/condominiums/{id}/votes/{voteId}', 'StandaloneVoteController@update');
$router->post('/condominiums/{id}/votes/{voteId}/start', 'StandaloneVoteController@start');
$router->post('/condominiums/{id}/votes/{voteId}/close', 'StandaloneVoteController@close');
$router->delete('/condominiums/{id}/votes/{voteId}', 'StandaloneVoteController@delete');

// Opções de resposta
$router->get('/condominiums/{id}/vote-options', 'VoteOptionController@index');
$router->post('/condominiums/{id}/vote-options', 'VoteOptionController@store');
$router->post('/condominiums/{id}/vote-options/{optionId}', 'VoteOptionController@update');
$router->delete('/condominiums/{id}/vote-options/{optionId}', 'VoteOptionController@delete');

// Votação do dashboard (AJAX)
$router->post('/condominiums/{id}/votes/{voteId}/vote', 'DashboardVoteController@vote');
```

### JavaScript
1. `assets/js/dashboard-votes.js` - Lógica para votação inline no dashboard
   - Função para submeter voto via AJAX
   - Atualizar UI após voto
   - Mostrar confirmação

### Sidebar
- Adicionar link "Votações" no menu lateral (após Assembleias)

## Fluxo de Votação no Dashboard

1. Dashboard carrega votações com `status = 'open'` do condomínio do usuário
2. Para cada votação em aberto, mostra card com:
   - Título da votação
   - Descrição (truncada)
   - Botões de opções de resposta
   - Contador de votos já registados
3. Usuário clica em uma opção
4. AJAX envia voto para `DashboardVoteController@vote`
5. Sistema valida e registra voto
6. Retorna JSON com confirmação e resultados atualizados
7. JavaScript atualiza UI mostrando voto registado

## Resultados no Dashboard

- Mostrar últimas 3 votações encerradas (`status = 'closed'`)
- Para cada votação:
  - Título
  - Data de encerramento
  - Gráfico/lista com distribuição de votos
  - Total de votos e percentagens
  - Link para ver detalhes completos

## Validações

- Apenas admins podem criar/editar votações
- Apenas usuários com frações no condomínio podem votar
- Um usuário só pode votar uma vez por votação (por fração)
- Votações em draft não aparecem no dashboard
- Votações fechadas não podem receber novos votos
- Opções padrão não podem ser eliminadas (apenas desativadas)