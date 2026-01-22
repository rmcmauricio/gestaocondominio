# Template Preview Images

Esta pasta contém as imagens de preview dos templates que são exibidas na página de customização.

## Estrutura de Ficheiros

- `default.png` - Preview do template padrão (null)
- `template_1.png` - Preview do Template 1 (Clássico)
- `template_2.png` - Preview do Template 2 (Moderno)
- `template_3.png` - Preview do Template 3 (Elegante Dark Mode)
- ... e assim por diante até `template_17.png`

## Especificações das Imagens

- **Formato**: PNG (recomendado) ou JPG
- **Dimensões**: 400x280px (proporção 10:7)
- **Tamanho**: Máximo 200KB por imagem
- **Conteúdo**: Deve mostrar uma representação visual do template, incluindo:
  - Cabeçalho com cores do template
  - Corpo com elementos representativos
  - Rodapé ou área de destaque

## Fallback

Se uma imagem não existir, o sistema usará automaticamente um preview CSS baseado nas cores do template.

## Como Adicionar Imagens

1. Crie ou obtenha imagens de preview para cada template
2. Nomeie os ficheiros conforme a estrutura acima
3. Coloque os ficheiros nesta pasta (`assets/images/templates/`)
4. As imagens serão automaticamente carregadas na página de customização
