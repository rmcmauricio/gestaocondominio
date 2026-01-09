# LyricsJam - Sistema de Letras Interativas

Sistema colaborativo para exibição de letras de música em tempo real, permitindo que um artista controle a reprodução e sincronize com o público durante shows e eventos musicais.

## 🎵 Funcionalidades

- **Sincronização em Tempo Real**: Artista controla a reprodução e todo o público acompanha
- **Busca Inteligente**: Sistema de busca FULLTEXT otimizado com warm-up automático
- **Interface Responsiva**: Funciona perfeitamente em desktop e mobile
- **Wake Lock**: Mantém o ecrã ativo durante a reprodução (mobile)
- **Estado Persistente**: Admin não perde o estado ao fazer refresh
- **Debug Avançado**: Sistema de logging configurável para desenvolvimento

## 🚀 Início Rápido

### Pré-requisitos
- PHP 8.0+
- MySQL 8.0+
- WebSocket server
- Navegador moderno

### Instalação
1. Clone o repositório
2. Configure a base de dados
3. Instale dependências: `composer install`
4. Configure variáveis de ambiente
5. Configure warm-up: Ver [docs/warmup-fulltext.md](docs/warmup-fulltext.md)

### Configuração
1. **Base de Dados**: Configure MySQL com índice FULLTEXT
2. **WebSocket**: Configure servidor WebSocket
3. **Warm-up**: Configure cron job para otimização
4. **Debug**: Configure sistema de logging se necessário

## 📚 Documentação

Toda a documentação está organizada na pasta `docs/`:

- **[Arquitetura](docs/architecture.md)** - Visão geral da arquitetura
- **[API Endpoints](docs/api-endpoints.md)** - Documentação das APIs
- **[Performance](docs/performance.md)** - Guia de otimizações
- **[Debug Logging](docs/debug-logging.md)** - Sistema de debug
- **[Troubleshooting](docs/troubleshooting.md)** - Solução de problemas
- **[Warm-up FULLTEXT](docs/warmup-fulltext.md)** - Otimização de busca

## 🏗️ Estrutura do Projeto

```
cifras/
├── app/                    # Aplicação PHP (MVC)
├── assets/                 # Recursos estáticos
├── docs/                   # Documentação
├── logs/                   # Logs do sistema
├── sessions/               # Sessões de jam
├── websocket/              # Endpoints WebSocket
└── vendor/                 # Dependências
```

## 🎯 Uso

### Para Artistas
1. Acesse a interface de controlo
2. Busque e selecione uma música
3. Controle a reprodução (play/pause/seek)
4. Todo o público sincroniza automaticamente

### Para o Público
1. Acesse o link do show
2. Acompanhe as letras em tempo real
3. Interface otimizada para mobile

## ⚡ Performance

O sistema inclui várias otimizações:

- **FULLTEXT Warm-up**: Mantém o índice de busca "quente"
- **Cache Inteligente**: Reduz consultas à base de dados
- **Compensação de Latência**: Sincronização precisa
- **Otimizações Mobile**: Performance otimizada para dispositivos móveis

## 🔧 Debug e Desenvolvimento

Sistema de debug configurável:

```javascript
// Ativar debug completo
enableAllDebug();

// Debug específico
toggleDebug('websocket');
toggleDebug('sync');
```

## 📱 Suporte Mobile

- Wake lock automático
- Compensação de latência otimizada
- Interface touch-friendly
- Performance otimizada

## 🤝 Contribuição

1. Fork o projeto
2. Crie uma branch para sua feature
3. Commit suas mudanças
4. Push para a branch
5. Abra um Pull Request

## 📄 Licença

Este projeto está sob a licença MIT. Veja o arquivo LICENSE para mais detalhes.

## 📞 Suporte

Para suporte e dúvidas:

1. Consulte a [documentação](docs/)
2. Verifique o [troubleshooting](docs/troubleshooting.md)
3. Abra uma issue no repositório

---

**Desenvolvido com ❤️ para conectar artistas e público**
# gestaocondominio
# gestaocondominio
