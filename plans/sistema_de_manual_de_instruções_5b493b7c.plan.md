---
name: Sistema de Manual de Instruções
overview: Criar um sistema completo de manual de instruções com página principal de índice, páginas individuais por funcionalidade, acesso via modal e botões de ajuda em todas as páginas relevantes. Incluir suporte para imagens ilustrativas (com placeholders quando necessário).
todos:
  - id: help-controller
    content: Criar HelpController com métodos index(), show() e modal()
    status: pending
  - id: help-routes
    content: Adicionar rotas para /help, /help/{section} e /help/{section}/modal
    status: pending
  - id: help-template-updates
    content: Atualizar mainTemplate.html.twig, header.html.twig e footer.html.twig para aplicar estilos da homepage na página principal de ajuda
    status: pending
    dependencies:
      - help-controller
  - id: help-index-page
    content: Criar página principal de índice (help/index.html.twig) usando layout da homepage com grid de todas as funcionalidades
    status: pending
    dependencies:
      - help-controller
      - help-template-updates
  - id: help-modal-component
    content: Criar componente de modal reutilizável (help-modal.html.twig) e JavaScript (help.js)
    status: pending
  - id: help-button-component
    content: Criar componente de botão de ajuda reutilizável (help-button.html.twig)
    status: pending
    dependencies:
      - help-modal-component
  - id: help-finances-pages
    content: Criar páginas de ajuda para Finanças (orçamentos, quotas, despesas, receitas, dívidas históricas, relatórios, mapa)
    status: pending
    dependencies:
      - help-index-page
  - id: help-core-pages
    content: Criar páginas de ajuda para Dashboard, Frações, Documentos e Ocorrências
    status: pending
    dependencies:
      - help-index-page
  - id: help-secondary-pages
    content: Criar páginas de ajuda para Assembleias, Mensagens, Reservas, Fornecedores, Contas Bancárias, Recibos, Notificações, Perfil, Subscrições e Convites
    status: pending
    dependencies:
      - help-index-page
  - id: help-integration
    content: Adicionar botões de ajuda em todas as páginas relevantes da aplicação
    status: pending
    dependencies:
      - help-button-component
      - help-finances-pages
      - help-core-pages
      - help-secondary-pages
  - id: help-seo-metafiles
    content: Criar metafiles SEO para todas as páginas de ajuda
    status: pending
    dependencies:
      - help-finances-pages
      - help-core-pages
      - help-secondary-pages
  - id: help-css
    content: Criar CSS específico para páginas de ajuda (help.css) com estilos para layout, imagens e navegação
    status: completed
    dependencies:
      - help-index-page
---

# Sistema de Manual de Instruções

## Estrutura Geral

O sistema será implementado como uma estrutura híbrida:

- **Página principal** (`/help` ou `/manual`) com índice navegável de todas as funcionalidades
- **Páginas individuais** para cada funcionalidade (`/help/finances`, `/help/fractions`, etc.)
- **Modais** para acesso rápido a partir de qualquer página (botão "Ajuda" em cada funcionalidade)
- **Metafiles** para SEO de cada página de ajuda

## Componentes a Criar

### 1. HelpController

**Arquivo:** `app/Controllers/HelpController.php`

Métodos:

- `index()` - Página principal com índice (usa template da homepage)
- `show(string $section)` - Página individual de cada seção (usa template padrão)
- `modal(string $section)` - Retorna conteúdo para modal (AJAX)

**Nota:** O método `index()` deve definir `viewName` como `'pages/help/index.html.twig'` para que o `mainTemplate.html.twig` aplique os estilos da homepage (condição: `'pages/help/index.html.twig' in viewName` ou similar).

### 2. Rotas

**Arquivo:** `routes.php`

```php
// Help routes
$router->get('/help', 'App\Controllers\HelpController@index');
$router->get('/help/{section}', 'App\Controllers\HelpController@show');
$router->get('/help/{section}/modal', 'App\Controllers\HelpController@modal');
```

### 3. Views

#### Página Principal

**Arquivo:** `app/Views/pages/help/index.html.twig`

- **Usa o template da homepage** (mesmo layout visual, header e footer da homepage)
- Grid de cards com todas as funcionalidades
- Índice navegável
- Busca rápida (opcional)
- Links diretos para cada seção
- Estilos da homepage aplicados (`homepage.css` e `main-content-full`)

#### Páginas Individuais

**Arquivo:** `app/Views/pages/help/{section}.html.twig` (ex: `finances.html.twig`, `fractions.html.twig`)

Estrutura de cada página:

- Título da funcionalidade
- Descrição geral
- Passo a passo com imagens
- Dicas e truques
- Links relacionados
- Navegação anterior/próxima

#### Modal Template

**Arquivo:** `app/Views/blocks/help-modal.html.twig`

- Modal Bootstrap reutilizável
- Carrega conteúdo via AJAX
- Botão para abrir página completa

### 4. Seções do Manual

#### Funcionalidades Principais:

1. **Dashboard** (`dashboard`)

   - Visão geral do dashboard
   - Navegação entre condomínios
   - Estatísticas e indicadores

2. **Finanças** (`finances`)

   - Sub-seções:
     - Orçamentos (`finances-budgets`)
     - Quotas (`finances-fees`)
     - Despesas (`finances-expenses`)
     - Receitas (`finances-revenues`)
     - Dívidas Históricas (`finances-historical-debts`)
     - Relatórios (`finances-reports`)
     - Mapa de Quotas (`finances-fees-map`)

3. **Frações** (`fractions`)

   - Criar e editar frações
   - Atribuir frações a condóminos
   - Permilagem

4. **Documentos** (`documents`)

   - Upload e organização
   - Pastas e versões
   - Partilha e visibilidade

5. **Ocorrências** (`occurrences`)

   - Criar ocorrências
   - Workflow de estados
   - Atribuição a fornecedores
   - Comentários e anexos

6. **Assembleias** (`assemblies`)

   - Criar assembleias
   - Enviar convocatórias
   - Registo de presenças
   - Geração de atas
   - Votações online

7. **Mensagens** (`messages`)

   - Criar mensagens
   - Responder a mensagens
   - Anexos e formatação

8. **Reservas** (`reservations`)

   - Criar espaços comuns
   - Fazer reservas
   - Aprovar/rejeitar reservas

9. **Fornecedores** (`suppliers`)

   - Gerir fornecedores
   - Criar contratos
   - Associar a ocorrências

10. **Contas Bancárias** (`bank-accounts`)

    - Adicionar contas
    - Movimentos financeiros
    - Saldos

11. **Recibos** (`receipts`)

    - Visualizar recibos
    - Descarregar PDFs
    - Recibos automáticos

12. **Notificações** (`notifications`)

    - Ver notificações
    - Marcar como lidas
    - Configurações

13. **Perfil** (`profile`)

    - Editar perfil
    - Alterar password
    - Preferências

14. **Subscrições** (`subscriptions`)

    - Ver plano atual
    - Alterar plano
    - Histórico de pagamentos

15. **Convites** (`invitations`)

    - Enviar convites
    - Aceitar convites
    - Gerir membros

### 5. Metafiles SEO

**Arquivo:** `app/Metafiles/pt/help-{section}.json`

Exemplo:

```json
{
  "titulo": "Ajuda - Finanças | O Meu Prédio",
  "description": "Aprenda a gerir orçamentos, quotas, despesas e receitas no O Meu Prédio. Guia completo passo a passo.",
  "keywords": "ajuda finanças, quotas condomínio, orçamentos, gestão financeira, tutorial"
}
```

### 6. Botões de Ajuda

#### Componente Reutilizável

**Arquivo:** `app/Views/blocks/help-button.html.twig`

Parâmetros:

- `section` - ID da seção de ajuda
- `title` - Título do botão (opcional)
- `size` - Tamanho do botão (sm, md, lg)

Uso:

```twig
{% include 'blocks/help-button.html.twig' with {'section': 'finances-fees', 'title': 'Ajuda com Quotas'} %}
```

#### Adicionar em Páginas Principais:

- `app/Views/pages/finances/fees.html.twig`
- `app/Views/pages/finances/index.html.twig`
- `app/Views/pages/fractions/index.html.twig`
- `app/Views/pages/documents/index.html.twig`
- `app/Views/pages/occurrences/index.html.twig`
- `app/Views/pages/assemblies/index.html.twig`
- `app/Views/pages/messages/index.html.twig`
- `app/Views/pages/reservations/index.html.twig`
- `app/Views/pages/suppliers/index.html.twig`
- `app/Views/pages/bank-accounts/index.html.twig`
- `app/Views/pages/receipts/index.html.twig`
- `app/Views/pages/notifications/index.html.twig`
- `app/Views/pages/dashboard/admin.html.twig`
- `app/Views/pages/dashboard/condomino.html.twig`

### 7. JavaScript para Modais

**Arquivo:** `assets/js/help.js`

Funções:

- `openHelpModal(section)` - Abre modal com conteúdo
- `openHelpPage(section)` - Abre página completa
- `loadHelpContent(section, target)` - Carrega conteúdo via AJAX

### 8. CSS para Páginas de Ajuda

**Arquivo:** `assets/css/help.css`

Estilos para:

- Layout das páginas de ajuda individuais (não a página principal)
- Cards de índice (na página principal, usar estilos da homepage)
- Imagens ilustrativas
- Navegação entre seções
- Modais de ajuda

**Nota:** A página principal (`/help`) usa `homepage.css` e o layout da homepage. As páginas individuais (`/help/{section}`) usam `help.css` e o template padrão da aplicação.

### 9. Estrutura de Dados

Cada seção terá um array de configuração em `HelpController`:

```php
private $helpSections = [
    'finances' => [
        'title' => 'Finanças',
        'icon' => 'bi-cash-stack',
        'subsections' => ['budgets', 'fees', 'expenses', 'revenues', 'historical-debts', 'reports'],
        'description' => 'Gerir orçamentos, quotas, despesas e receitas'
    ],
    // ...
];
```

### 10. Placeholders para Imagens

Cada página terá placeholders no formato:

```html
<div class="help-image-placeholder">
    <div class="placeholder-content">
        <i class="bi bi-image"></i>
        <p>Imagem ilustrativa: [Descrição da imagem]</p>
        <small class="text-muted">Esta imagem será adicionada posteriormente</small>
    </div>
</div>
```

## Implementação por Fases

### Fase 1: Estrutura Base

1. Criar `HelpController` com métodos básicos
2. Criar rotas
3. **Atualizar `mainTemplate.html.twig`**:

   - Modificar condição do CSS: `{% if viewName == 'pages/home.html.twig' or 'pages/legal/' in viewName or viewName == 'pages/help/index.html.twig' %}`
   - Modificar condição do `main-content-full`: `{% if viewName == 'pages/home.html.twig' or 'pages/legal/' in viewName or viewName == 'pages/help/index.html.twig' %}`

4. **Atualizar `header.html.twig`**:

   - Modificar condição: `{% if viewName == 'pages/home.html.twig' or 'pages/legal/' in viewName or viewName == 'pages/help/index.html.twig' %}`

5. **Atualizar `footer.html.twig`**:

   - Modificar condição: `{% if viewName == 'pages/home.html.twig' or 'pages/legal/' in viewName or viewName == 'pages/help/index.html.twig' %}`

6. Criar página principal de índice (usando layout da homepage com seções similares)
7. Criar template de modal
8. Criar JavaScript básico para modais

### Fase 2: Conteúdo Principal

1. Criar páginas de ajuda para funcionalidades principais:

   - Dashboard
   - Finanças (com sub-seções)
   - Frações
   - Documentos
   - Ocorrências

### Fase 3: Conteúdo Secundário

1. Criar páginas de ajuda para:

   - Assembleias
   - Mensagens
   - Reservas
   - Fornecedores
   - Contas Bancárias
   - Recibos
   - Notificações
   - Perfil
   - Subscrições
   - Convites

### Fase 4: Integração

1. Adicionar botões de ajuda em todas as páginas relevantes
2. Criar metafiles SEO para cada página
3. Testar modais e navegação
4. Adicionar CSS para melhorar visual

### Fase 5: Melhorias

1. Adicionar busca (opcional)
2. Adicionar navegação anterior/próxima
3. Adicionar breadcrumbs
4. Melhorar responsividade

## Estrutura de Ficheiros

```
app/
├── Controllers/
│   └── HelpController.php
├── Views/
│   ├── pages/
│   │   └── help/
│   │       ├── index.html.twig
│   │       ├── dashboard.html.twig
│   │       ├── finances.html.twig
│   │       ├── finances-budgets.html.twig
│   │       ├── finances-fees.html.twig
│   │       ├── finances-expenses.html.twig
│   │       ├── finances-revenues.html.twig
│   │       ├── finances-historical-debts.html.twig
│   │       ├── finances-reports.html.twig
│   │       ├── finances-fees-map.html.twig
│   │       ├── fractions.html.twig
│   │       ├── documents.html.twig
│   │       ├── occurrences.html.twig
│   │       ├── assemblies.html.twig
│   │       ├── messages.html.twig
│   │       ├── reservations.html.twig
│   │       ├── suppliers.html.twig
│   │       ├── bank-accounts.html.twig
│   │       ├── receipts.html.twig
│   │       ├── notifications.html.twig
│   │       ├── profile.html.twig
│   │       ├── subscriptions.html.twig
│   │       └── invitations.html.twig
│   └── blocks/
│       ├── help-button.html.twig
│       └── help-modal.html.twig
├── Metafiles/
│   └── pt/
│       ├── help.json
│       ├── help-dashboard.json
│       ├── help-finances.json
│       └── ... (um por seção)
assets/
├── css/
│   └── help.css
└── js/
    └── help.js
```

## Notas Importantes

1. **Imagens**: Todas as páginas terão placeholders para imagens com descrições claras do que deve ser mostrado
2. **Navegação**: Cada página terá links para seções relacionadas
3. **Responsividade**: Todas as páginas devem ser totalmente responsivas
4. **Acessibilidade**: Usar ARIA labels e estrutura semântica adequada
5. **SEO**: Cada página terá metafile próprio para SEO
6. **Consistência**: Usar o mesmo layout e estilo em todas as páginas de ajuda