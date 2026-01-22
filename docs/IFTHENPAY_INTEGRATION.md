# Integração IfthenPay - Guia Completo

Este documento explica como configurar e utilizar a integração com IfthenPay para processamento de pagamentos Multibanco, MBWay e Débitos Diretos (DD).

## Índice

1. [Visão Geral](#visão-geral)
2. [Obter Credenciais](#obter-credenciais)
3. [Configuração](#configuração)
4. [Métodos de Pagamento Suportados](#métodos-de-pagamento-suportados)
5. [Configuração de Callbacks](#configuração-de-callbacks)
6. [Gestão de Métodos de Pagamento](#gestão-de-métodos-de-pagamento)
7. [Testes](#testes)
8. [Troubleshooting](#troubleshooting)

---

## Visão Geral

A integração com IfthenPay permite processar pagamentos através de três métodos:

- **Multibanco**: Geração de referências para pagamento em ATM ou homebanking
- **MBWay**: Pagamento via aplicação móvel MBWay
- **Débitos Diretos (DD)**: Débito automático mensal via IfthenPay

O sistema suporta ambiente sandbox (testes) e produção, com callbacks automáticos para confirmação de pagamentos.

---

## Obter Credenciais

1. Aceda ao site da IfthenPay: https://www.ifthenpay.com
2. Registe-se ou faça login na sua conta
3. No painel de controlo, obtenha as seguintes credenciais:
   - **API Key**: Chave principal de API
   - **Anti-Phishing Key**: Chave de segurança para validação de callbacks
   - **Multibanco Entity**: Código de entidade Multibanco
   - **Multibanco Sub-Entity**: Sub-entidade Multibanco
   - **MBWay Key**: Chave específica para MBWay
   - **DD Key**: Chave específica para Débitos Diretos

---

## Configuração

### 1. Variáveis de Ambiente

Adicione as seguintes variáveis ao seu ficheiro `.env`:

```env
# Escolher IfthenPay como PSP
PSP_PROVIDER=ifthenpay

# Ambiente (sandbox para testes, production para produção)
PSP_ENVIRONMENT=sandbox

# Credenciais principais
IFTHENPAY_API_KEY=your_api_key
IFTHENPAY_ANTI_PHISHING_KEY=your_anti_phishing_key

# URLs da API
IFTHENPAY_SANDBOX_URL=https://ifthenpay.com/api/sandbox
IFTHENPAY_PRODUCTION_URL=https://ifthenpay.com/api

# Multibanco
MULTIBANCO_ENTITY=your_entity_code
MULTIBANCO_SUB_ENTITY=your_sub_entity_code

# MBWay
MBWAY_KEY=your_mbway_key

# Débitos Diretos
IFTHENPAY_DD_KEY=your_direct_debit_key
```

### 2. Executar Migration e Seeder

Execute a migration para criar a tabela de métodos de pagamento:

```bash
php migrate.php
```

Execute o seeder para criar os métodos padrão:

```bash
php seed.php PaymentMethodsSeeder
```

---

## Métodos de Pagamento Suportados

### Multibanco

- Geração automática de referências Multibanco
- Validade de 3 dias
- Callback automático quando pagamento é confirmado

### MBWay

- Requer número de telemóvel português (9 dígitos, começa com 9)
- Notificação enviada para o telemóvel
- Validade de 30 minutos
- Callback automático quando pagamento é confirmado

### Débitos Diretos (DD)

- Requer IBAN e dados do titular
- Mandato criado automaticamente
- Processamento em 2-3 dias úteis
- Débitos automáticos mensais
- Callback automático quando débito é processado

---

## Configuração de Callbacks

### URL de Callback

Configure no painel IfthenPay a seguinte URL de callback:

```
https://seu-dominio.com/webhooks/ifthenpay
```

### Formato dos Callbacks

**Multibanco:**
```
GET /webhooks/ifthenpay?key=[ANTI_PHISHING_KEY]&orderId=[ORDER_ID]&amount=[AMOUNT]&requestId=[REQUEST_ID]&payment_datetime=[DATETIME]&entity=[ENTITY]&reference=[REFERENCE]
```

**MBWay:**
```
GET /webhooks/ifthenpay?key=[ANTI_PHISHING_KEY]&orderId=[ORDER_ID]&amount=[AMOUNT]&requestId=[REQUEST_ID]&payment_datetime=[DATETIME]&mbway_phone=[PHONE]
```

**Débito Direto:**
```
GET /webhooks/ifthenpay?key=[ANTI_PHISHING_KEY]&orderId=[ORDER_ID]&amount=[AMOUNT]&requestId=[REQUEST_ID]&payment_datetime=[DATETIME]&dd_mandate_reference=[MANDATE_REF]&dd_status=[STATUS]
```

### Segurança

- Todos os callbacks são validados usando a Anti-Phishing Key
- Callbacks inválidos são rejeitados automaticamente
- Logs completos são mantidos em `storage/logs/payments.log`

---

## Gestão de Métodos de Pagamento

O super administrador pode ativar ou desativar métodos de pagamento através do painel:

1. Aceda a `/admin/payment-methods`
2. Visualize todos os métodos disponíveis
3. Clique em "Ativar" ou "Desativar" para alterar o status
4. Alterações têm efeito imediato

Métodos desativados não aparecem na página de seleção de pagamento.

---

## Testes

### Ambiente Sandbox

1. Configure `PSP_ENVIRONMENT=sandbox` no `.env`
2. Use credenciais de teste fornecidas pela IfthenPay
3. Teste cada método de pagamento
4. Verifique logs em `storage/logs/payments.log`

### Simulação de Callbacks

Para testar callbacks localmente, pode usar ferramentas como:

- **ngrok**: Para expor localhost publicamente
- **Postman**: Para simular requisições GET
- **cURL**: Para fazer requisições de teste

Exemplo de callback de teste (Multibanco):

```bash
curl "http://localhost/webhooks/ifthenpay?key=YOUR_ANTI_PHISHING_KEY&orderId=TEST-123&amount=29.90&requestId=MB-123-1234567890&payment_datetime=2024-01-01%2012:00:00&entity=12345&reference=123456789"
```

---

## Troubleshooting

### Problema: Callbacks não são recebidos

**Soluções:**
1. Verifique se a URL de callback está corretamente configurada no painel IfthenPay
2. Certifique-se de que o servidor está acessível publicamente (use HTTPS em produção)
3. Verifique os logs em `storage/logs/payments.log`
4. Confirme que a Anti-Phishing Key está correta

### Problema: Método de pagamento não aparece

**Soluções:**
1. Verifique se o método está ativo em `/admin/payment-methods`
2. Certifique-se de que o seeder foi executado
3. Verifique os logs do sistema

### Problema: Erro ao gerar pagamento

**Soluções:**
1. Verifique se todas as credenciais estão corretas no `.env`
2. Confirme que `PSP_PROVIDER=ifthenpay` está configurado
3. Verifique os logs em `storage/logs/payments.log`
4. Em ambiente sandbox, certifique-se de usar credenciais de teste

### Problema: Pagamento não é confirmado automaticamente

**Soluções:**
1. Verifique se o callback foi recebido (logs)
2. Confirme que a Anti-Phishing Key está correta
3. Verifique se o `requestId` corresponde ao `external_payment_id` do pagamento
4. Verifique se o pagamento já não foi processado anteriormente

---

## Tratamento de Erros e Retry Logic

O sistema implementa retry automático para erros temporários:

- **Timeout de conexão**: Retry automático com backoff exponencial (0.5s, 1s, 1.5s)
- **Erros de servidor (5xx)**: Retry automático até 3 tentativas
- **Erros de cliente (4xx)**: Não são retentados (erro de configuração)
- **Erros de rede**: Retry automático para problemas temporários de conectividade

O sistema tenta até 3 vezes antes de falhar definitivamente, com espera progressiva entre tentativas.

## Logs

Todos os eventos são registados em `storage/logs/payments.log`:

- Chamadas à API IfthenPay
- Callbacks recebidos
- Erros e exceções
- Validações de segurança
- Tentativas de retry

Exemplo de entrada de log:

```
[2024-01-01 12:00:00] API Call - Type: multibanco, Action: generate, Data: {"amount":29.90,"orderId":"MB-123-1234567890"}
[2024-01-01 12:00:01] ERROR - Type: http, Action: request, Message: Attempt 1 failed: timeout. Retrying...
[2024-01-01 12:00:02] API Call - Type: multibanco, Action: response, Data: {"entidade":"12345","referencia":"123456789"}
[2024-01-01 12:05:00] Callback - Type: multibanco, Data: {"key":"...","orderId":"MB-123-1234567890",...}
```

---

## Suporte

Para questões sobre a integração IfthenPay:

- Documentação IfthenPay: https://www.ifthenpay.com/documentacao
- Suporte IfthenPay: suporte@ifthenpay.com
- Logs do sistema: `storage/logs/payments.log`
