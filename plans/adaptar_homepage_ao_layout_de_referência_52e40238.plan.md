---
name: Adaptar Homepage ao Layout de Referência
overview: ""
todos: []
---

# Adaptar Homepage ao Layout de Referência

## Objetivo

Adaptar a homepage (`app/Views/pages/home.html.twig`) para seguir o mesmo layout visual e estrutura da página de referência, mantendo o conteúdo existente e garantindo que o acesso à demo continue funcional.

## Análise da Página de Referência

A página de referência possui:

1. **Header**: Navegação horizontal com links (Funcionalidade, Como Funciona, Depoimento, Contato) e botões (Entrar, Começar Grátis)
2. **Hero Section**: Título principal, descrição, CTAs principais e estatística de confiança
3. **Seções intermediárias**: (não totalmente visíveis no snapshot, mas provavelmente features/testimonials)
4. **CTA Final**: Seção de call-to-action antes do footer
5. **Footer**: Organizado em colunas com informações da empresa, links e redes sociais

## Alterações Necessárias

### 1. Header (`app/Views/blocks/header.html.twig`)

- **Quando não logado**: Adaptar para ter navegação horizontal similar à referência
- Logo à esquerda
- Links de navegação no centro (Funcionalidade, Como Fun