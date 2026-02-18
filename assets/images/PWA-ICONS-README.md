# Ícones PWA (versão mobile)

Para a PWA mobile ser totalmente instalável, adicione os ficheiros:

- **icon-192.png** – 192×192 px  
- **icon-512.png** – 512×512 px  

Recomendações:

- Use o logo da aplicação (ex. a partir de `logo.svg`).
- Para ícones “maskable” (ecrã de início em Android), deixe margem de segurança (~20%) em redor do conteúdo.
- Pode gerar os tamanhos a partir de um PNG maior (ex. 512×512) com uma ferramenta de redimensionamento ou com [realfavicongenerator.net](https://realfavicongenerator.net/) / [pwa-asset-generator](https://www.npmjs.com/package/pwa-asset-generator).

Enquanto estes ficheiros não existirem, o manifest continua a referir `apple-touch-icon.png` (180×180) como ícone adicional; alguns browsers podem aceitar a instalação com esse ícone.
