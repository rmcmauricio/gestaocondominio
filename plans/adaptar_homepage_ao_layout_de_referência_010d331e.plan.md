---
name: Adaptar Homepage ao Layout de Referência
overview: Adaptar a homepage atual para seguir o mesmo layout e design da página de referência (https://testcond.lovable.app/), mantendo o acesso à demo e todas as informações existentes, mas reorganizando o conteúdo e criando um CSS separado para melhor organização.
todos: []
---

# Adaptar Homepage ao Layout de Referência

## Objetivo

Adaptar a homepage (`app/Views/pages/home.html.twig`) para seguir o mesmo layout visual e estrutura da página de referência, mantendo todo o conteúdo existente (incluindo acesso à demo) mas reorganizando-o para um design mais moderno e limpo.

## Estrutura Detalhada da Página de Referência

### 1. Header (Fundo Branco)

- **Logo**: "O Meu Prédio" à esquerda com ícone de prédio
- **Navegação Horizontal**: Funcionalidades, Como Funciona, Depoimentos, Contato (texto azul escuro)
- **Botões Direita**: "Entrar" (texto azul escuro) e "Começar Grátis" (botão laranja com texto branco)

### 2. Hero Section (Fundo Azul Escuro)

- **Badge Superior**: "A melhor solução para seu condomínio" com ícone de cadeado à esquerda (texto branco pequeno)
- **Título Principal**: 
  - "Simplifique a gestão do seu" (texto branco grande)
  - "condomínio" (texto laranja grande)
- **Descrição**: Texto branco explicando funcionalidades
- **3 Ícones com Texto** (em linha):
  - Gráfico de pizza: "Gestão financeira completa"
  - Balão de fala: "Comunicação eficiente"
  - Calendário: "Reservas online"
- **2 Botões CTA**:
  - "Começar Gratuitamente" (botão laranja grande com seta)
  - "Agendar Demonstração" (botão azul escuro com texto branco)
- **Prova Social**: "Mais de 800 condomínios já confiam no Meu Prédio" (texto branco)

### 3. Seção Funcionalidades (Fundo Branco)

- **Título Seção**: "FUNCIONALIDADES" (texto laranja pequeno, uppercase)
- **Subtítulo**: "Tudo que você precisa para uma gestão eficiente" (texto azul escuro grande)
- **Descrição**: "Ferramentas completas para síndicos, administradores e moradores"
- **Grid 3x2 de Cards** (6 cards):
  - Cada card: fundo cinza claro, ícone no topo esquerdo, título azul escuro, descrição azul escuro
  - Card 1: Gestão Financeira (ícone documento)
  - Card 2: Comunicação Integrada (ícone balão)
  - Card 3: Reserva de Espaços (ícone calendário)
  - Card 4: Documentos Digitais (ícone documento)
  - Card 5: Portal do Morador (ícone pessoas)
  - Card 6: Segurança e Controle (ícone escudo)

### 4. Seção Como Funciona (Fundo Branco)

- **Título Seção**: "COMO FUNCIONA" (texto laranja pequeno, uppercase)
- **Subtítulo**: "Comece em 3 passos simples" (texto azul escuro grande)
- **Descrição**: "Configuração rápida e sem complicação"
- **3 Cards em Linha**:
  - Cada card: ícone azul escuro no topo, "Passo 01/02/03", título azul escuro, descrição azul escuro
  - Passo 1: Crie sua conta (ícone pessoa com +)
  - Passo 2: Configure seu prédio (ícone engrenagem)
  - Passo 3: Comece a usar (ícone foguete)

### 5. Seção Depoimentos (Fundo Branco)

- **Título Seção**: "DEPOIMENTOS" (texto laranja pequeno, uppercase)
- **Subtítulo**: "O que dizem nossos clientes" (texto azul escuro grande)
- **Descrição**: "Milhares de síndicos e moradores satisfeitos em todo o Brasil"
- **3 Cards em Linha**:
  - Cada card: fundo cinza claro, ícone estrela laranja, 5 estrelas laranjas, citação azul escuro, nome e cargo
  - Testemunho 1: Carlos Silva, Síndico
  - Testemunho 2: Ana Paula Santos, Administradora
  - Testemunho 3: Roberto Mendes, Morador

### 6. Seção CTA Final (Fundo Azul Escuro)

- **Badge Superior Direita**: "Comece gratuitamente" com ícone de cadeado
- **Título**: "Pronto para transformar a gestão do seu condomínio?" (texto branco grande)
- **Descrição**: "Junte-se a centenas de síndicos que já simplificaram suas rotinas. Teste grátis por 30 dias, sem compromisso."
- **2 Botões CTA**:
  - "Criar Conta Gratuita" (botão laranja grande com seta)
  - "Falar com Consultor" (botão azul escuro com texto branco)
- **Nota**: "Não precisa de cartão de crédito - Cancele quando quiser" (texto branco pequeno)

### 7. Footer (Fundo Azul Escuro)

- **Coluna Esquerda**:
  - Logo "O Meu Prédio"
  - Descrição da empresa
  - Contato: Email, Telefone, Endereço (cada um com ícone)
- **Colunas Meio**:
  - **Produto**: Funcionalidades, Preços, Integrações, FAQ
  - **Empresa**: Sobre nós, Blog, Carreiras, Contato
  - **Legal**: Termos de uso, Privacidade, Cookies
- **Rodapé Inferior**:
  - Copyright à esquerda: "© 2025 Meu Prédio. Todos os direitos reservados."
  - Ícones redes sociais à direita: Facebook, Instagram, LinkedIn

## Alterações Necessárias

### 1. Criar CSS Separado para Homepage

- Criar `assets/css/homepage.css` com todos os estilos específicos da homepage
- Mover estilos relacionados da homepage de `assets/css/main.css` para o novo ficheiro
- Incluir o novo CSS apenas na homepage via `mainTemplate.html.twig` (condicionalmente quando `viewName == 'pages/home.html.twig'`)

### 2. Atualizar Header para Homepage

- Modificar `app/Views/blocks/header.html.twig` para mostrar navegação horizontal quando não há usuário logado
- Adicionar links de navegação: Funcionalidades, Como Funciona, Depoimentos, Contato
- Manter botões "Entrar" e "Começar Grátis" no header
- Links devem fazer scroll suave para as seções correspondentes na página (usando âncoras)

### 3. Reestruturar Hero Section

- Remover preview visual do dashboard atual
- Implementar hero conforme referência:
  - Badge superior com ícone de cadeado
  - Título dividido: "Simplifique a gestão do seu" (branco) + "condomínio" (laranja)
  - Descrição em branco
  - 3 ícones com texto em linha horizontal
  - 2 botões CTA (laranja e azul escuro)
  - Prova social abaixo dos botões
- Fundo azul escuro (#1a1f3a ou similar)

### 4. Reorganizar Seções de Conteúdo

- **Seção Funcionalidades**: Adaptar cards existentes para grid 3x2 conforme referência
- **Seção Como Funciona**: Criar nova seção com 3 passos (pode integrar informações da demo)
- **Seção Depoimentos**: Criar nova seção com 3 testemunhos (pode usar dados fictícios ou reais se disponíveis)
- **Seção Demo**: Manter mas adaptar ao novo estilo, ou integrar na seção "Como Funciona"
- **Seção Pricing**: Manter mas ajustar visual conforme referência

### 5. Atualizar CTA Section Final

- Criar seção CTA final antes do footer conforme referência
- Fundo azul escuro
- Badge superior direito com ícone de cadeado
- Título, descrição e botões conforme especificado
- Nota sobre cartão de crédito

### 6. Atualizar Footer

- Expandir footer com fundo azul escuro
- Organizar em colunas: Logo/Descrição/Contato, Produto, Empresa, Legal
- Adicionar ícones de redes sociais
- Copyright e links no rodapé inferior

### 7. Manter Funcionalidades Existentes

- Preservar acesso à demo (`{{ BASE_URL }}demo/access`)
- Manter todas as informações sobre funcionalidades
- Preservar links de registo e login
- Manter informações sobre planos

## Paleta de Cores Baseada na Referência

- **Azul Escuro**: #1a1f3a (ou similar) - usado em hero, CTA final, footer
- **Laranja**: #ff6b35 (ou similar) - usado em CTAs principais e destaques
- **Branco**: #ffffff - texto sobre fundos escuros
- **Cinza Claro**: #f5f5f5 (ou similar) - fundo dos cards
- **Azul Escuro Texto**: #1a1f3a - texto em fundos claros

## Ficheiros a Modificar

1. **`assets/css/homepage.css`** (NOVO) - CSS específico da homepage com todos os estilos
2. **`app/Views/pages/home.html.twig`** - Reestruturar HTML conforme novo layout detalhado
3. **`app/Views/blocks/header.html.twig`** - Adicionar navegação horizontal para visitantes
4. **`app/Views/blocks/footer.html.twig`** - Expandir footer com mais informações em colunas
5. **`app/Views/templates/mainTemplate.html.twig`** - Incluir `homepage.css` condicionalmente apenas na homepage
6. **`assets/css/main.css`** - Remover estilos específicos da homepage (serão movidos para `homepage.css`)

## Considerações de Design

- Layout mais limpo e moderno conforme referência
- Espaçamento generoso entre seções (padding vertical de 80-100px)
- Tipografia clara e hierárquica (títulos grandes, descrições menores)
- Cores consistentes com a identidade visual da referência
- Responsividade mantida para mobile (grids se tornam coluna única)
- Animações sutis para melhor UX (hover effects nos cards e botões)
- Scroll suave para navegação entre seções

## Estrutura HTML Proposta

```
<header> (com navegação horizontal)
<section class="hero-section"> (fundo azul escuro)
<section class="features-section"> (fundo branco)
<section class="how-it-works-section"> (fundo branco)
<section class="testimonials-section"> (fundo branco)
<section class="pricing-section"> (fundo branco) - opcional, pode manter
<section class="cta-section"> (fundo azul escuro)
<footer> (fundo azul escuro)
```