/** @type {import('@capacitor/cli').CapacitorConfig} */
module.exports = {
  appId: 'com.omeupredio.app',
  appName: 'O Meu Prédio',
  webDir: 'www',
  server: {
    // URL da versão mobile em produção. Substitua pelo seu domínio (ex.: https://omeupredio.com/m/dashboard).
    // Se a aplicação PHP estiver numa subpasta use: https://seudominio.com/predio/m/dashboard
    url: 'https://omeupredio.com/m/dashboard',
    cleartext: true,
  },
  android: {
    allowMixedContent: true,
  },
  ios: {
    contentInset: 'automatic',
  },
};
