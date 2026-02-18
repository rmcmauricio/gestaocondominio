# O Meu Prédio – App mobile (Capacitor)

Projeto Capacitor que empacota a **versão mobile** do site (rotas `/m/*`) como aplicação nativa para iOS e Android. A app abre o site em modo WebView; o conteúdo é servido pelo backend PHP em produção.

## Pré-requisitos

- Node.js 18+
- npm ou yarn
- **iOS:** Xcode (macOS), conta Apple Developer
- **Android:** Android Studio, JDK 17, conta Google Play Developer

## Configuração

1. **URL do servidor**

   Edite `capacitor.config.js` e defina `server.url` com a URL real da versão mobile:

   ```ts
   server: {
     url: 'https://seudominio.com/m/dashboard',  // ou https://seudominio.com/predio/m/dashboard
     cleartext: true,  // use false em produção com HTTPS
   },
   ```

   Atualize também o redirect em `www/index.html` se usar essa página como fallback.

2. **Instalar dependências**

   ```bash
   cd mobile-app
   npm install
   ```

3. **Adicionar plataformas** (se ainda não existirem)

   ```bash
   npx cap add ios
   npx cap add android
   ```

4. **Sincronizar** (copia `www` e aplica a config nas pastas nativas)

   ```bash
   npx cap sync
   ```

## Ícones e splash

- **Ícones:** Coloque os ícones da app em `resources/` e use [@capacitor/assets](https://www.npmjs.com/package/@capacitor/assets) ou [cordova-res](https://www.npmjs.com/package/cordova-res) para gerar os tamanhos por plataforma.
- **Splash:** Configure no mesmo fluxo ou nos recursos nativos em `ios/App/App/Assets.xcassets` e `android/app/src/main/res`.

## Abrir e executar

- **iOS**

  ```bash
  npx cap open ios
  ```

  No Xcode: selecione dispositivo ou simulador e execute (Run). Para publicar, use Archive e submeta à App Store (requer conta Apple Developer).

- **Android**

  ```bash
  npx cap open android
  ```

  No Android Studio: Run para emulador ou dispositivo. Para publicar, gere um bundle (Build > Generate Signed Bundle / APK) e submeta à Google Play Console.

## Comportamento

- A app abre diretamente a URL configurada em `server.url` (versão mobile).
- O link “Versão completa” no site abre no browser do sistema (`target="_blank"`), mantendo a app na versão mobile.
- Cookies e sessão funcionam como no browser; o domínio deve estar em HTTPS em produção.

## Estrutura

- `www/` – Conteúdo estático mínimo (fallback/redirect); o conteúdo real vem do `server.url`.
- `ios/` – Projeto Xcode (gerado por `cap add ios`).
- `android/` – Projeto Android (gerado por `cap add android`).
- `capacitor.config.js` – Configuração da app (appId, appName, server.url, etc.).

## Notas

- Em desenvolvimento local, pode usar `server.url` com o seu IP (ex.: `http://192.168.1.x/predio/m/dashboard`) e `cleartext: true` para testar em dispositivo na mesma rede.
- Para produção, use sempre HTTPS e defina `cleartext: false` (ou omita).
