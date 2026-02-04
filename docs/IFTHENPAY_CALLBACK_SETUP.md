# Configuração e Uso do Callback IFTHEN - Guia Completo

Este documento explica como configurar e utilizar o sistema de callbacks da IfthenPay para confirmação automática de pagamentos.

## Índice

1. [Visão Geral](#visão-geral)
2. [Como Funciona](#como-funciona)
3. [Configuração no Painel IfthenPay](#configuração-no-painel-ifthenpay)
4. [Configuração no Sistema](#configuração-no-sistema)
5. [Tipos de Callbacks](#tipos-de-callbacks)
6. [Testes](#testes)
7. [Troubleshooting](#troubleshooting)
8. [Logs e Monitorização](#logs-e-monitorização)

---

## Visão Geral

O sistema de callbacks permite que a IfthenPay notifique automaticamente o sistema quando um pagamento é confirmado. Isto elimina a necessidade de verificação manual e garante que as subscrições e extras são ativados imediatamente após o pagamento.

### Fluxo de Pagamento com Callback

```
1. Utilizador inicia pagamento → Sistema cria registo "pending"
2. Utilizador efetua pagamento → IfthenPay processa
3. IfthenPay confirma pagamento → Envia callback para sistema
4. Sistema valida callback → Ativa subscrição/extras automaticamente
5. Utilizador recebe confirmação → Pagamento completo
```

---

## Como Funciona

### Endpoint do Callback

O sistema expõe o seguinte endpoint para receber callbacks da IfthenPay:

```
GET /webhooks/ifthenpay
```

### Segurança

Todos os callbacks são validados usando a **Anti-Phishing Key** configurada:

1. IfthenPay envia callback com parâmetro `key` contendo a Anti-Phishing Key
2. Sistema compara com a chave configurada no `.env`
3. Se corresponder, callback é processado
4. Se não corresponder, callback é rejeitado com erro 401

### Processamento Automático

Quando um callback válido é recebido:

1. **Validação**: Verifica Anti-Phishing Key e campos obrigatórios
2. **Identificação**: Localiza pagamento pelo `requestId` ou `orderId`
3. **Verificação**: Confirma que pagamento ainda não foi processado
4. **Confirmação**: Marca pagamento como `completed`
5. **Ativação**: Ativa subscrição e/ou extras conforme necessário
6. **Logging**: Regista tudo nos logs de auditoria

---

## Configuração no Painel IfthenPay

### 1. Aceder às Configurações

1. Faça login no painel IfthenPay: https://www.ifthenpay.com
2. Navegue para **Configurações** → **Callbacks/Webhooks**
3. Selecione o método de pagamento (Multibanco, MBWay ou Débito Direto)

### 2. Configurar URL de Callback

Para cada método de pagamento, configure a seguinte URL:

```
https://seu-dominio.com/webhooks/ifthenpay
```

**Importante:**
- Use **HTTPS** em produção (obrigatório)
- A URL deve ser acessível publicamente
- Não inclua parâmetros na URL (serão adicionados pela IfthenPay)

### 3. Configurar Anti-Phishing Key

1. No painel IfthenPay, gere ou copie a **Anti-Phishing Key**
2. Esta chave será usada para validar todos os callbacks
3. Guarde-a em local seguro (será necessária para configuração no sistema)

### 4. Ativar Callbacks

1. Marque a opção **"Ativar Callbacks"** para cada método
2. Confirme que o status está **"Ativo"**
3. Guarde as alterações

---

## Configuração no Sistema

### 1. Variáveis de Ambiente

Adicione/verifique as seguintes variáveis no ficheiro `.env`:

```env
# Escolher IfthenPay como PSP
PSP_PROVIDER=ifthenpay

# Ambiente (sandbox para testes, production para produção)
PSP_ENVIRONMENT=sandbox

# Anti-Phishing Key (OBRIGATÓRIA para callbacks)
IFTHENPAY_ANTI_PHISHING_KEY=sua_anti_phishing_key_aqui

# Outras credenciais IfthenPay
IFTHENPAY_API_KEY=sua_api_key
MULTIBANCO_ENTITY=sua_entidade
MULTIBANCO_SUB_ENTITY=sua_sub_entidade
MBWAY_KEY=sua_mbway_key
IFTHENPAY_DD_KEY=sua_dd_key
```

### 2. Verificar Rotas

Confirme que a rota está registada em `routes.php`:

```php
$router->get('/webhooks/ifthenpay', 'App\Controllers\WebhookController@ifthenpayCallback');
```

### 3. Testar Conectividade

Use o seguinte comando para verificar se o endpoint está acessível:

```bash
curl -X GET "https://seu-dominio.com/webhooks/ifthenpay?key=TEST&orderId=TEST-123"
```

Deve retornar um erro de validação (esperado), mas confirma que o endpoint está funcionando.

---

## Tipos de Callbacks

### Callback Multibanco

**Quando é enviado:** Após utilizador pagar referência Multibanco em ATM ou homebanking

**Parâmetros recebidos:**
```
GET /webhooks/ifthenpay?
  key=[ANTI_PHISHING_KEY]
  &orderId=[ORDER_ID]
  &amount=[AMOUNT]
  &requestId=[REQUEST_ID]
  &payment_datetime=[DATETIME]
  &entity=[ENTITY]
  &reference=[REFERENCE]
```

**Campos obrigatórios:**
- `key`: Anti-Phishing Key
- `orderId`: ID do pedido
- `amount`: Valor pago
- `requestId`: ID único do pedido IfthenPay
- `entity`: Entidade Multibanco
- `reference`: Referência Multibanco

### Callback MBWay

**Quando é enviado:** Após utilizador confirmar pagamento na app MBWay

**Parâmetros recebidos:**
```
GET /webhooks/ifthenpay?
  key=[ANTI_PHISHING_KEY]
  &orderId=[ORDER_ID]
  &amount=[AMOUNT]
  &requestId=[REQUEST_ID]
  &payment_datetime=[DATETIME]
  &mbway_phone=[PHONE]
```

**Campos obrigatórios:**
- `key`: Anti-Phishing Key
- `orderId`: ID do pedido
- `amount`: Valor pago
- `requestId`: ID único do pedido IfthenPay
- `mbway_phone`: Número de telemóvel MBWay

### Callback Débito Direto

**Quando é enviado:** Após débito direto ser processado (2-3 dias úteis)

**Parâmetros recebidos:**
```
GET /webhooks/ifthenpay?
  key=[ANTI_PHISHING_KEY]
  &orderId=[ORDER_ID]
  &amount=[AMOUNT]
  &requestId=[REQUEST_ID]
  &payment_datetime=[DATETIME]
  &dd_mandate_reference=[MANDATE_REF]
  &dd_status=[STATUS]
```

**Campos obrigatórios:**
- `key`: Anti-Phishing Key
- `orderId`: ID do pedido
- `amount`: Valor debitado
- `requestId`: ID único do pedido IfthenPay
- `dd_mandate_reference`: Referência do mandato
- `dd_status`: Status do débito (`paid`, `rejected`, etc.)

---

## Testes

### Ambiente Sandbox

1. Configure `PSP_ENVIRONMENT=sandbox` no `.env`
2. Use credenciais de teste fornecidas pela IfthenPay
3. Configure URL de callback no painel sandbox da IfthenPay
4. Efetue pagamento de teste
5. Verifique logs para confirmar receção do callback

### Teste Manual com cURL

**Multibanco:**
```bash
curl -X GET "https://seu-dominio.com/webhooks/ifthenpay?key=SUA_ANTI_PHISHING_KEY&orderId=TEST-123&amount=29.90&requestId=MB-TEST-1234567890&payment_datetime=2024-01-01%2012:00:00&entity=12345&reference=123456789"
```

**MBWay:**
```bash
curl -X GET "https://seu-dominio.com/webhooks/ifthenpay?key=SUA_ANTI_PHISHING_KEY&orderId=TEST-123&amount=29.90&requestId=MBW-TEST-1234567890&payment_datetime=2024-01-01%2012:00:00&mbway_phone=912345678"
```

**Débito Direto:**
```bash
curl -X GET "https://seu-dominio.com/webhooks/ifthenpay?key=SUA_ANTI_PHISHING_KEY&orderId=TEST-123&amount=29.90&requestId=DD-TEST-1234567890&payment_datetime=2024-01-01%2012:00:00&dd_mandate_reference=DD-REF-123&dd_status=paid"
```

**Nota:** Substitua `TEST-123` pelo `orderId` real de um pagamento pendente no sistema.

### Teste Local com ngrok

Para testar callbacks localmente:

1. Instale ngrok: https://ngrok.com
2. Inicie servidor local: `php -S localhost:8000`
3. Exponha publicamente: `ngrok http 8000`
4. Use URL do ngrok no painel IfthenPay: `https://xxxxx.ngrok.io/webhooks/ifthenpay`
5. Efetue pagamento de teste
6. Verifique logs para confirmar callback

---

## Troubleshooting

### Problema: Callbacks não são recebidos

**Sintomas:**
- Pagamentos ficam pendentes indefinidamente
- Logs não mostram callbacks recebidos

**Soluções:**

1. **Verificar URL de Callback:**
   - Confirme que URL está correta no painel IfthenPay
   - Use HTTPS em produção (obrigatório)
   - Verifique que não há espaços ou caracteres especiais

2. **Verificar Conectividade:**
   ```bash
   curl -X GET "https://seu-dominio.com/webhooks/ifthenpay?key=TEST"
   ```
   Deve retornar erro de validação, não erro 404.

3. **Verificar Firewall:**
   - Certifique-se de que servidor aceita conexões HTTPS
   - Verifique regras de firewall que possam bloquear IfthenPay

4. **Verificar Logs:**
   - Consulte `storage/logs/payments.log`
   - Procure por erros de conexão ou timeout

### Problema: Callbacks são rejeitados (Erro 401)

**Sintomas:**
- Logs mostram "Invalid anti-phishing key"
- Callbacks retornam erro 401

**Soluções:**

1. **Verificar Anti-Phishing Key:**
   - Confirme que `IFTHENPAY_ANTI_PHISHING_KEY` no `.env` corresponde à chave no painel IfthenPay
   - Certifique-se de que não há espaços extras ou caracteres invisíveis
   - Em ambiente sandbox, use chave de teste

2. **Verificar Ambiente:**
   - Em desenvolvimento, callbacks podem ser aceites mesmo sem chave válida
   - Em produção, chave é obrigatória

### Problema: Pagamento não é encontrado (Erro 404)

**Sintomas:**
- Logs mostram "Payment not found for requestId: XXX"
- Callback retorna erro 404

**Soluções:**

1. **Verificar requestId/orderId:**
   - Confirme que `requestId` no callback corresponde ao `external_payment_id` do pagamento
   - Verifique se pagamento foi criado corretamente antes do callback

2. **Verificar Base de Dados:**
   ```sql
   SELECT id, external_payment_id, status, created_at 
   FROM payments 
   WHERE external_payment_id LIKE '%REQUEST_ID%'
   ORDER BY created_at DESC;
   ```

### Problema: Pagamento já foi processado

**Sintomas:**
- Callback retorna "Already processed"
- Pagamento já está com status `completed`

**Solução:**
- Isto é normal se callback for recebido múltiplas vezes
- Sistema ignora callbacks duplicados para evitar processamento duplo

### Problema: Callback não ativa subscrição

**Sintomas:**
- Pagamento é marcado como `completed`
- Mas subscrição permanece `pending`

**Soluções:**

1. **Verificar Logs de Auditoria:**
   - Consulte tabela `audit_logs` para ver se ativação foi registada
   - Procure por erros durante processamento

2. **Verificar PaymentService:**
   - Confirme que `confirmPayment()` está a ser chamado corretamente
   - Verifique logs para erros durante ativação

3. **Verificar Invoice Metadata:**
   - Confirme que invoice tem metadata correto (`is_license_addition`, etc.)
   - Verifique se subscription_id está correto no pagamento

---

## Logs e Monitorização

### Localização dos Logs

Todos os eventos relacionados com callbacks são registados em:

- **Logs de Aplicação**: `storage/logs/payments.log`
- **Logs de Auditoria**: Tabela `audit_logs` na base de dados
- **Logs de Erro PHP**: Configurado no `php.ini` ou `.env`

### Tipos de Logs

**Callbacks Recebidos:**
```
[2024-01-01 12:00:00] Callback - Type: multibanco, Data: {"key":"...","orderId":"MB-123",...}
```

**Validações:**
```
[2024-01-01 12:00:01] Callback validated successfully
[2024-01-01 12:00:01] Payment found: ID 123, Status: pending
```

**Processamento:**
```
[2024-01-01 12:00:02] Payment confirmed: ID 123
[2024-01-01 12:00:02] Subscription activated: ID 456
```

**Erros:**
```
[2024-01-01 12:00:00] ERROR - Invalid anti-phishing key
[2024-01-01 12:00:00] ERROR - Payment not found for requestId: XXX
```

### Consultar Logs

**Via Base de Dados:**
```sql
-- Ver callbacks recentes
SELECT * FROM audit_logs 
WHERE action LIKE '%callback%' OR action LIKE '%ifthenpay%'
ORDER BY created_at DESC 
LIMIT 50;

-- Ver pagamentos processados via callback
SELECT p.*, al.description 
FROM payments p
LEFT JOIN audit_logs al ON al.payment_id = p.id
WHERE p.status = 'completed'
  AND p.external_payment_id LIKE 'MB-%' OR p.external_payment_id LIKE 'MBW-%'
ORDER BY p.processed_at DESC
LIMIT 20;
```

**Via Ficheiro:**
```bash
# Ver últimas 50 linhas
tail -n 50 storage/logs/payments.log

# Procurar por callbacks
grep "Callback" storage/logs/payments.log

# Procurar por erros
grep "ERROR" storage/logs/payments.log
```

---

## Boas Práticas

1. **Sempre use HTTPS em produção** - Callbacks contêm dados sensíveis
2. **Mantenha Anti-Phishing Key segura** - Não commite no código
3. **Monitore logs regularmente** - Para detetar problemas rapidamente
4. **Teste em sandbox primeiro** - Antes de ir para produção
5. **Configure alertas** - Para callbacks falhados ou rejeitados
6. **Mantenha backups** - Dos logs e registos de pagamentos
7. **Documente alterações** - Em configurações de callback

---

## Suporte

Para questões sobre callbacks IfthenPay:

- **Documentação IfthenPay**: https://www.ifthenpay.com/documentacao
- **Suporte IfthenPay**: suporte@ifthenpay.com
- **Logs do Sistema**: `storage/logs/payments.log`
- **Logs de Auditoria**: Tabela `audit_logs` na base de dados

---

## Exemplo Completo de Fluxo

### 1. Utilizador inicia pagamento Multibanco

```
POST /payments/{id}/create
→ Sistema cria pagamento com status "pending"
→ Sistema gera referência Multibanco via IfthenPay API
→ Pagamento recebe external_payment_id = "MB-123-1234567890"
```

### 2. Utilizador paga referência

```
Utilizador vai a ATM/homebanking
→ Paga referência Multibanco
→ IfthenPay processa pagamento
```

### 3. IfthenPay envia callback

```
GET /webhooks/ifthenpay?
  key=abc123...
  &orderId=ORDER-123
  &amount=29.90
  &requestId=MB-123-1234567890
  &payment_datetime=2024-01-01 12:00:00
  &entity=12345
  &reference=123456789
```

### 4. Sistema processa callback

```
WebhookController recebe callback
→ Valida Anti-Phishing Key ✓
→ Valida campos obrigatórios ✓
→ Encontra pagamento pelo requestId ✓
→ Verifica que ainda não foi processado ✓
→ Chama PaymentService::confirmPayment()
→ Marca pagamento como "completed"
→ Ativa subscrição/extras
→ Regista em audit_logs
→ Retorna sucesso (200 OK)
```

### 5. Utilizador vê confirmação

```
Utilizador atualiza página
→ Vê que pagamento está "completed"
→ Subscrição está "active"
→ Extras estão disponíveis
```

---

**Última atualização:** Janeiro 2024
