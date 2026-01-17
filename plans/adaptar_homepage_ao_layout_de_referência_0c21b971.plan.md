---
name: Adaptar Homepage ao Layout de Referência
overview: Adaptar a homepage atual para seguir o mesmo layout e design da página de referência, mantendo o acesso à demo e todas as informações existentes, mas reorganizando o conteúdo e criando um CSS separado para melhor organização.
todos:
  - id: create-homepage-css
    content: Criar ficheiro assets/css/homepage.css com todos os estilos específicos da homepage (hero, features, how-it-works, testimonials, CTA, footer)
    status: in_progress
  - id: update-main-template
    content: Atualizar mainTemplate.html.twig para incluir homepage.css condicionalmente apenas quando viewName == pages/home.html.twig
    status: pending
    dependencies:
      - create-homepage-css
  - id: update-header
    content: Modificar header.html.twig para mostrar navegação horizontal (Funcionalidades, Como Funciona, Depoimentos, Contato) quando usuário não está logado
    status: pending
  - id: restructure-hero
    content: Reestruturar hero section em home.html.twig conforme layout de referência (fundo azul escuro, título dividido branco/laranja, 3 ícones, 2 CTAs, prova social)
    status: pending
    dependencies:
      - create-homepage-css
  - id: reorganize-features
    content: Reorganizar seção de Features para grid 3x2 conforme referência, adaptando cards existentes
    status: pending
    dependencies:
      - create-homepage-css
  - id: add-how-it-works
    content: Criar nova seção Como Funciona com 3 passos (pode integrar informações da demo)
    status: pending
    dependencies:
      - create-homepage-css
  - id: add-testimonials
    content: Criar nova seção Depoimentos com 3 cards de testemunhos
    status: pending
    dependencies:
      - create-homepage-css
  - id: update-cta-section
    content: Atualizar seção CTA final antes do footer conforme referência (fundo azul escuro, badge, título, 2 botões, nota)
    status: pending
    dependencies:
      - create-homepage-css
  - id: update-footer
    content: Expandir footer com fundo azul escuro, colunas (Logo/Contato, Produto, Empresa, Legal), redes sociais e copyright
    status: pending
  - id: clean-main-css
    content: Remover estilos específicos da homepage de main.css após mover para homepage.css
    status: pending
    dependencies:
      - create-homepage-css
      - update-main-template
---

# Adaptar Homepage ao Layout de Referência

## Objetivo

Adaptar a homepage (`app/Views/pages/home.html.twig`) para seguir o mesmo layout visual e estrutura da página de referência, mantendo todo o conteúdo existente (incluindo acesso à demo) mas reorganizando-o para um design mais moderno e limpo.

## Estrutura Detalhada da Página de Referência

### 1. Header (Fundo Branco)

- Logo "O Meu Prédio" à esquerda
- Navegação horizontal: Funcionalidades, Como Funciona,  Contato
- Botões direita: "Entrar" e "Começar Grátis" (laranja)

### 2. Hero Section (Fundo Azul Escuro)

- Badge superior: "A melhor solução para seu condomínio" com ícone cadeado
- Título: "Simplifique a gestão do seu" (branco) + "condomínio" (laranja)
- Descrição em branco
- 3 ícones com texto: Gestão financeira, Comunicação eficiente, Reservas de espaços comuns, Recibos automáticos, Assembleias com votações online, Ocorrências
- 2 botões CTA: "Começar Gratuitamente" (laranja) e "Demonstração" (azul)

### 3. Seção Funcionalidades (Fundo Branco)

- Título: "FUNCIONALIDADES" (laranja pequeno) + "Tudo que você precisa..." (azul grande)
- Grid 3x2 de 6 cards com ícones, títulos e descrições

### 4. Seção Como Funciona (Fundo Branco)

- Título: "COMO FUNCIONA" (laranja) + "Comece em 3 passos simples" (azul)
- 3 cards em linha: Passo 01, 02, 03 com ícones e descrições

### 6. Seção CTA Final (Fundo Azul Escuro)

- Badge superior: "Comece gratuitamente"
- Título: "Pronto para transformar a gestão do seu condomínio?"
- 2 botões: "Criar Conta Gratuita" e "Falar com Consultor"
- Nota: "Não precisa de cartão de crédito - Cancele quando quiser"

### 7. Footer (Fundo Azul Escuro)

- Colunas: Logo/Descrição/Contato, Produto, Empresa, Legal
- Ícones redes sociais e copyright

## Ficheiros a Modificar

1. **`assets/css/homepage.css`** (NOVO) - CSS específico da homepage
2. **`app/Views/pages/home.html.twig`** - Reestruturar HTML conforme novo layout
3. **`app/Views/blocks/header.html.twig`** - Adicionar navegação horizontal para visitantes
4. **`app/Views/blocks/footer.html.twig`** - Expandir footer com mais informações
5. **`app/Views/templates/mainTemplate.html.twig`** - Incluir `homepage.css` condicionalmente
6. **`assets/css/main.css`** - Remover estilos específicos da homepage

## Paleta de Cores

- Azul Escuro: #1a1f3a (hero, CTA final, footer)
- Laranja: #ff6b35 (CTAs principais e destaques)
- Branco: #ffffff (texto sobre fundos escuros)
- Cinza Claro: #f5f5f5 (fundo dos cards)