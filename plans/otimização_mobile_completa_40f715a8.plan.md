---
name: Otimização Mobile Completa
overview: Revisar e otimizar todas as páginas do site para dispositivos móveis, garantindo que todo o conteúdo esteja bem visível, acessível e otimizado para o espaço limitado de telas pequenas.
todos:
  - id: global_css_base
    content: "Melhorar CSS base: tipografia mobile (mínimo 16px), espaçamentos, word-wrap, overflow"
    status: completed
  - id: global_css_layout
    content: Ajustar containers, grid system e layout geral para mobile-first
    status: completed
    dependencies:
      - global_css_base
  - id: header_mobile
    content: "Otimizar header para mobile: botões, espaçamento, dropdowns"
    status: completed
    dependencies:
      - global_css_base
  - id: sidebar_mobile
    content: "Melhorar sidebar mobile: animações, dropdowns, overlay"
    status: in_progress
    dependencies:
      - global_css_base
  - id: table_mobile_system
    content: Criar sistema de conversão tabela→cards mobile e aplicar CSS
    status: pending
    dependencies:
      - global_css_layout
  - id: tables_optimization
    content: Aplicar conversão mobile em todas as tabelas principais (fees, occurrences, messages, receipts, etc.)
    status: pending
    dependencies:
      - table_mobile_system
  - id: forms_mobile
    content: "Otimizar todos os formulários: tamanhos de inputs (44x44px), espaçamento, labels"
    status: pending
    dependencies:
      - global_css_base
  - id: tinymce_mobile
    content: "Ajustar editor TinyMCE para mobile: toolbar, altura, responsividade"
    status: pending
    dependencies:
      - forms_mobile
  - id: dashboard_mobile
    content: "Otimizar dashboards: cards de estatísticas, grid layout"
    status: pending
    dependencies:
      - global_css_layout
  - id: detail_pages_mobile
    content: "Otimizar páginas de detalhes: layout uma coluna, cards, botões de ação"
    status: pending
    dependencies:
      - global_css_layout
  - id: modals_mobile
    content: "Otimizar modais e popups para mobile: tamanho, scroll, botões"
    status: pending
    dependencies:
      - global_css_base
  - id: touch_targets
    content: Garantir todos os elementos clicáveis com 44x44px mínimo e espaçamento adequado
    status: pending
    dependencies:
      - global_css_base
  - id: navigation_mobile
    content: "Melhorar navegação mobile: breadcrumbs, menu, botão voltar ao topo"
    status: pending
    dependencies:
      - header_mobile
      - sidebar_mobile
  - id: fees_map_mobile
    content: "Otimizar mapa de quotas mobile: scroll horizontal melhorado, indicadores"
    status: pending
    dependencies:
      - table_mobile_system
  - id: testing_refinement
    content: Testar em diferentes tamanhos de tela e refinar ajustes
    status: pending
    dependencies:
      - tables_optimization
      - forms_mobile
      - dashboard_mobile
      - detail_pages_mobile
---

# Otimização Mobile Completa

## Objetivo

Tornar todas as páginas do site totalmente responsivas e otimizadas para dispositivos móveis, garantindo excelente experiência de uso em telas pequenas.

## Estrutura de Trabalho

### 1. Melhorias Globais no CSS (`assets/css/main.css`)

#### 1.1 Base e Tipografia Mobile

- Ajustar tamanhos de fonte base para mobile (mínimo 16px para evitar zoom automático)
- Melhorar line-height e espaçamento entre elementos
- Garantir contraste adequado em todos os textos
- Adicionar word-wrap e overflow-wrap onde necessário

#### 1.2 Container e Layout

- Garantir que containers não causem overflow horizontal
- Ajustar padding/margin para mobile (reduzir espaçamentos grandes)
- Melhorar sistema de grid para mobile-first
- Adicionar max-width: 100% em imagens e elementos media

#### 1.3 Header Mobile (`app/Views/blocks/header.html.twig`)

- Otimizar botões do header para mobile (tamanhos adequados)
- Melhorar espaçamento entre elementos
- Garantir que dropdowns não saiam da tela
- Ajustar logo para mobile

#### 1.4 Sidebar Mobile (`app/Views/blocks/sidebar.html.twig`)

- Melhorar animação de abertura/fechamento
- Garantir que dropdowns funcionem corretamente
- Otimizar tamanho de fonte e espaçamento dos itens
- Melhorar overlay de fundo

### 2. Otimização de Tabelas

#### 2.1 Sistema de Conversão Tabela → Cards

- Criar classes utilitárias `.table-mobile-cards` para conversão automática
- Aplicar em todas as tabelas principais:
- `app/Views/pages/finances/fees.html.twig` (já parcialmente implementado)
- `app/Views/pages/occurrences/index.html.twig`
- `app/Views/pages/messages/index.html.twig`
- `app/Views/pages/receipts/index.html.twig`
- `app/Views/pages/financial-transactions/index.html.twig`
- `app/Views/pages/payments/index.html.twig`
- `app/Views/pages/suppliers/index.html.twig`
- `app/Views/pages/spaces/index.html.twig`
- `app/Views/pages/reservations/index.html.twig`
- `app/Views/pages/assemblies/index.html.twig`
- `app/Views/pages/bank-accounts/index.html.twig`
- `app/Views/pages/documents/index.html.twig`
- `app/Views/pages/fractions/index.html.twig`

#### 2.2 Tabelas que Precisam Scroll Horizontal

- Manter scroll apenas onde necessário (ex: mapa de quotas)
- Adicionar indicadores visuais de scroll
- Melhorar touch-scrolling

### 3. Otimização de Formulários

#### 3.1 Formulários Principais

- Aumentar tamanho de inputs e botões (mínimo 44x44px para touch)
- Melhorar espaçamento entre campos
- Otimizar labels e placeholders
- Adicionar validação visual mobile-friendly
- Revisar:
- `app/Views/pages/occurrences/create.html.twig`
- `app/Views/pages/messages/create.html.twig`
- `app/Views/pages/finances/create-expense.html.twig`
- `app/Views/pages/finances/create-revenue.html.twig`
- `app/Views/pages/payments/create.html.twig`
- `app/Views/pages/assemblies/create.html.twig`
- `app/Views/pages/spaces/create.html.twig`
- `app/Views/pages/reservations/create.html.twig`
- `app/Views/pages/suppliers/create.html.twig`
- `app/Views/pages/bank-accounts/create.html.twig`
- `app/Views/pages/documents/create.html.twig`
- `app/Views/pages/fractions/create.html.twig`

#### 3.2 Editor TinyMCE Mobile

- Ajustar altura e toolbar do editor para mobile
- Garantir que imagens inseridas sejam responsivas
- Melhorar experiência de edição em telas pequenas

### 4. Páginas Específicas

#### 4.1 Dashboard (`app/Views/pages/dashboard/`)

- Converter cards de estatísticas para layout mobile-friendly
- Otimizar gráficos (se houver) para mobile
- Melhorar grid de cards

#### 4.2 Páginas de Detalhes

- Otimizar layout de duas colunas para uma coluna em mobile
- Melhorar exibição de informações em cards
- Ajustar botões de ação
- Revisar:
- `app/Views/pages/occurrences/show.html.twig`
- `app/Views/pages/messages/show.html.twig`
- `app/Views/pages/receipts/show.html.twig`
- `app/Views/pages/assemblies/show.html.twig`
- `app/Views/pages/finances/show-budget.html.twig`

#### 4.3 Páginas de Listagem

- Melhorar filtros para mobile (dropdowns, botões)
- Otimizar paginação
- Melhorar busca/search
- Adicionar ações rápidas mobile-friendly

#### 4.4 Modais e Popups

- Garantir que modais ocupem espaço adequado em mobile
- Melhorar scroll dentro de modais
- Otimizar botões de fechar
- Ajustar tabelas dentro de modais

### 5. Componentes Específicos

#### 5.1 Notificações (`app/Views/pages/notifications/index.html.twig`)

- Já parcialmente otimizado, revisar e melhorar
- Garantir que cards sejam totalmente clicáveis
- Otimizar botões de ação

#### 5.2 Mapa de Quotas (`app/Views/blocks/fees-map.html.twig`)

- Manter scroll horizontal mas melhorar UX
- Adicionar indicadores de scroll
- Otimizar sticky columns para mobile

#### 5.3 PDFs e Documentos

- Garantir que visualizadores de PDF sejam responsivos
- Melhorar controles de zoom em mobile

### 6. Melhorias de UX Mobile

#### 6.1 Touch Targets

- Garantir que todos os elementos clicáveis tenham pelo menos 44x44px
- Aumentar espaçamento entre botões
- Melhorar área de toque em links

#### 6.2 Navegação

- Melhorar breadcrumbs para mobile
- Otimizar menu de navegação
- Adicionar botão "voltar ao topo" quando necessário

#### 6.3 Feedback Visual

- Melhorar estados hover/active para touch
- Adicionar feedback tátil onde apropriado
- Melhorar mensagens de sucesso/erro para mobile

### 7. Testes e Ajustes

#### 7.1 Breakpoints

- Revisar e padronizar breakpoints:
- Mobile: até 575px
- Tablet: 576px - 991px
- Desktop: 992px+
- Garantir transições suaves entre breakpoints

#### 7.2 Performance Mobile

- Otimizar imagens para mobile
- Reduzir animações pesadas em mobile
- Garantir carregamento rápido

## Arquivos Principais a Modificar

### CSS

- `assets/css/main.css` - Adicionar/ajustar regras mobile

### Views (Templates)

- `app/Views/templates/mainTemplate.html.twig` - Ajustes gerais
- `app/Views/blocks/header.html.twig` - Header mobile
- `app/Views/blocks/sidebar.html.twig` - Sidebar mobile
- `app/Views/blocks/fees-map.html.twig` - Mapa de quotas mobile

### Views (Páginas) - Aplicar classes mobile e ajustar layouts

- Todas as páginas em `app/Views/pages/` (77 arquivos)

## Estratégia de Implementação

1. **Fase 1**: Melhorias globais no CSS (base, tipografia, layout)
2. **Fase 2**: Otimização de componentes globais (header, sidebar, footer)
3. **Fase 3**: Conversão de tabelas para cards mobile
4. **Fase 4**: Otimização de formulários
5. **Fase 5**: Ajustes em páginas específicas
6. **Fase 6**: Testes e refinamentos

## Critérios de Sucesso

- ✅ Sem scroll horizontal indesejado
- ✅ Todos os textos legíveis (mínimo 16px)
- ✅ Todos os botões/elementos clicáveis com tamanho adequado (44x44px)
- ✅ Formulários fáceis de usar em mobile
- ✅ Tabelas convertidas para cards ou com scroll adequado
- ✅ Layout adaptado para diferentes tamanhos de tela
- ✅ Navegação intuitiva em mobile
- ✅ Performance adequada em dispositivos móveis