---
name: Adaptar Homepage Visual ao Template de Referência
overview: Adaptar visualmente a homepage atual para ficar o mais semelhante possível ao template de referência fornecido, mantendo todos os conteúdos existentes. O foco será em ajustar cores, espaçamentos, sombras, gradientes e efeitos visuais para corresponder ao design moderno do template.
todos: []
---

# Adaptar Homepage Visual ao Template de Referência

## Objetivo

Adaptar visualmente a homepage (`app/Views/pages/home.html.twig`) e seus estilos (`assets/css/homepage.css`) para corresponder ao design moderno do template de referência (`storage/layout/applications.html`), mantendo todos os conteúdos existentes.

## Análise do Template de Referência

O template usa:

- **Sistema de cores**: HSL com variáveis CSS (`--primary`, `--secondary`, `--accent`, etc.)
- **Gradientes**: `bg-gradient-hero` e `bg-gradient-primary` para hero e botões
- **Efeitos visuais**: Blur effects (`blur-3xl`), sombras suaves (`shadow-card`, `shadow-button`)
- **Animações**: `animate-fade-up` com delays escalonados
- **Espaçamentos**: Padding consistente (`py-20`, `md:py-28`)
- **Tipografia**: Plus Jakarta Sans, weights 400-800
- **Cards**: Bordas sutis, hover effects com elevação
- **Header**: Fixo com backdrop blur (`backdrop-blur-md`)

## Alterações Necessárias

### 1. Atualizar Variáveis CSS e Cores Base

**Arquivo**: `assets/css/homepage.css`

- Ajustar variáveis de cor para corresponder ao sistema HSL do template
- Definir `--gradient-hero` e `--gradient-primary` conforme o template
- Atualizar cores de texto e backgrounds para usar HSL

### 2. Hero Section

**Arquivos**: `assets/css/homepage.css`, `app/Views/pages/home.html.twig`

- Adicionar gradiente de fundo (`bg-gradient-hero`) em vez de cor sólida
- Adicionar elementos decorativos (círculos com blur) no background
- Ajustar badge do topo para usar `bg-primary-foreground/10` e `text-primary-foreground/90`
- Ajustar espaçamento (`pt-32 pb-20 md:pt-40 md:pb-32`)
- Adicionar animações fade-up com delays escalonados
- Adicionar SVG wave no final da seção hero
- Ajustar cores dos ícones inline para `text-primary-foreground/90`

### 3. Features Section

**Arquivos**: `assets/css/homepage.css`, `app/Views/pages/home.html.twig`

- Ajustar espaçamento (`py-20 md:py-28`)
- Atualizar cards para usar `bg-card`, `border-border`, `shadow-card`
- Adicionar hover effects (`hover:shadow-card-hover`, `hover:-translate-y-1`)
- Ajustar ícones para usar `bg-accent` e `text-primary`
- Adicionar animações fade-up com delays

### 4. Demo Section

**Arquivos**: `assets/css/homepage.css`, `app/Views/pages/home.html.twig`

- Manter estrutura atual mas ajustar cores e espaçamentos
- Usar sistema de cores do template (card backgrounds, borders)
- Ajustar botão CTA para usar gradiente primário

### 5. How It Works Section

**Arquivos**: `assets/css/homepage.css`, `app/Views/pages/home.html.twig`

- Ajustar background para `bg-muted`
- Atualizar cards de passos para usar `bg-secondary` nos ícones
- Adicionar linha conectora entre passos (visível apenas em desktop)
- Ajustar espaçamentos e cores conforme template

### 6. Pricing Section

**Arquivos**: `assets/css/homepage.css`, `app/Views/pages/home.html.twig`

- Manter estrutura mas ajustar cores e sombras
- Usar `bg-card`, `border-border`, `shadow-card` nos cards
- Ajustar badge "Mais Popular" para usar cores do template
- Atualizar botões para usar gradiente primário

### 7. CTA Section Final

**Arquivos**: `assets/css/homepage.css`, `app/Views/pages/home.html.twig`

- Adicionar gradiente de fundo (`bg-gradient-hero`)
- Adicionar elementos decorativos (círculos com blur)
- Ajustar badge, título e botão conforme template
- Usar `text-primary-foreground` para textos

### 8. Header

**Arquivos**: `assets/css/homepage.css`, `app/Views/blocks/header.html.twig`

- Adicionar backdrop blur (`backdrop-blur-md`)
- Usar `bg-background/80` para transparência
- Ajustar altura do header (`h-20`)
- Atualizar cores dos links de navegação para `text-muted-foreground hover:text-foreground`
- Ajustar botões conforme template

### 9. Footer

**Arquivos**: `assets/css/homepage.css`, `app/Views/blocks/footer.html.twig`

- Usar `bg-secondary` e `text-secondary-foreground`
- Ajustar grid para `md:grid-cols-2 lg:grid-cols-5`
- Atualizar cores de links e ícones sociais
- Adicionar border-top com `border-secondary-foreground/10`

### 10. Animações e Transições

**Arquivo**: `assets/css/homepage.css`

- Adicionar keyframes `fade-up` se não existir
- Aplicar `animate-fade-up` com delays escalonados em elementos
- Ajustar transições de hover para `duration-200` ou `duration-300`

### 11. Responsividade

**Arquivo**: `assets/css/homepage.css`

- Garantir breakpoints consistentes (`sm:`, `md:`, `lg:`)
- Ajustar espaçamentos e tamanhos de fonte conforme breakpoints
- Garantir que cards e grids sejam responsivos

## Estrutura de Implementação

1. **Fase 1**: Atualizar variáveis CSS e sistema de cores base
2. **Fase 2**: Adaptar Hero Section (mais impacto visual)
3. **Fase 3**: Adaptar Features, Demo e How It Works
4. **Fase 4**: Adaptar Pricing e CTA Final
5. **Fase 5**: Ajustar Header e Footer
6. **Fase 6**: Adicionar animações e polimentos finais

## Notas Importantes

- **Manter conteúdos**: Todos os textos, links e funcionalidades existentes devem ser preservados
- **Compatibilidade**: Garantir que funcione em todos os breakpoints
- **Performance**: Animações devem ser leves e não impactar performance
- **Acessibilidade**: Manter contraste adequado e navegação por teclado

## Arquivos a Modificar

- `assets/css/homepage.css` - Estilos principais
- `app/Views/pages/home.html.twig` - Estrutura HTML (ajustes mínimos)
- `app/Views/blocks/header.html.twig` - Header (ajustes de classes)
- `app/Views/blocks/footer.html.twig` - Footer (ajustes de classes)