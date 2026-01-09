# Configuração de Pagamentos - MBWay e Multibanco

Este documento explica como configurar os pagamentos MBWay e Multibanco no sistema.

## 📋 Índice

1. [Visão Geral](#visão-geral)
2. [PSPs Disponíveis em Portugal](#psps-disponíveis-em-portugal)
3. [Configuração de Variáveis de Ambiente](#configuração-de-variáveis-de-ambiente)
4. [Integração com PSP](#integração-com-psp)
5. [Webhooks](#webhooks)
6. [Testes](#testes)

---

## Visão Geral

O sistema suporta três métodos de pagamento:
- **Multibanco**: Referências geradas para pagamento em ATM ou homebanking
- **MBWay**: Pagamento via aplicação móvel MBWay
- **SEPA Direct Debit**: Débito direto automático mensal

Atualmente, o sistema está configurado com valores **mock** (simulados) para desenvolvimento. Para produção, é necessário integrar com um PSP (Payment Service Provider) português.

---

## PSPs Disponíveis em Portugal

### 1. **Easypay** (Recomendado)
- Website: https://www.easypay.pt
- Suporta: Multibanco, MBWay, Cartões
- API REST bem documentada
- Ambiente de testes disponível

### 2. **Ifthenpay**
- Website: https://www.ifthenpay.com
- Suporta: Multibanco, MBWay, Cartões
- Integração simples
- Ambiente de testes disponível

### 3. **UniPay**
- Website: https://www.unipay.pt
- Suporta: Multibanco, MBWay, Cartões
- Solução completa para e-commerce

### 4. **Stripe** (com suporte para Portugal)
- Website: https://stripe.com/pt
- Suporta: Cartões, MBWay (via Stripe)
- Internacional, mas com suporte local

---

## Configuração de Variáveis de Ambiente

Adicione as seguintes variáveis ao seu ficheiro `.env`:

```env
# ============================================
# Payment Service Provider Configuration
# ============================================

# Escolha o PSP: easypay, ifthenpay, unipay, stripe
PSP_PROVIDER=easypay

# Ambiente: sandbox (testes) ou production (produção)
PSP_ENVIRONMENT=sandbox

# ============================================
# Multibanco Configuration
# ============================================
# Código de entidade fornecido pelo PSP
MULTIBANCO_ENTITY=12345

# Chave de API para Multibanco (fornecida pelo PSP)
MULTIBANCO_API_KEY=your_multibanco_api_key

# ============================================
# MBWay Configuration
# ============================================
# Chave de API para MBWay (fornecida pelo PSP)
MBWAY_API_KEY=your_mbway_api_key

# ID da conta MBWay (fornecida pelo PSP)
MBWAY_ACCOUNT_ID=your_mbway_account_id

# ============================================
# PSP API Credentials (Easypay)
# ============================================
EASYPAY_API_KEY=your_easypay_api_key
EASYPAY_USERNAME=your_easypay_username
EASYPAY_PASSWORD=your_easypay_password
EASYPAY_SANDBOX_URL=https://api.test.easypay.pt
EASYPAY_PRODUCTION_URL=https://api.easypay.pt

# ============================================
# PSP API Credentials (Ifthenpay)
# ============================================
IFTHENPAY_API_KEY=your_ifthenpay_api_key
IFTHENPAY_ANTI_PHISHING_KEY=your_anti_phishing_key
IFTHENPAY_SANDBOX_URL=https://www.ifthenpay.com/api
IFTHENPAY_PRODUCTION_URL=https://www.ifthenpay.com/api

# ============================================
# Webhook Configuration
# ============================================
# URL base do seu sistema (para webhooks)
WEBHOOK_BASE_URL=https://seu-dominio.com

# Secret key para validar webhooks do PSP
PSP_WEBHOOK_SECRET=your_webhook_secret_key

# ============================================
# Payment Settings
# ============================================
# Tempo de expiração de referências Multibanco (em dias)
MULTIBANCO_EXPIRY_DAYS=3

# Tempo de expiração de pagamentos MBWay (em minutos)
MBWAY_EXPIRY_MINUTES=30
```

---

## Integração com PSP

### Passo 1: Criar Conta no PSP

1. Aceda ao website do PSP escolhido (ex: Easypay)
2. Registe-se como comerciante
3. Complete o processo de verificação
4. Obtenha as credenciais de API (API Key, Username, Password)

### Passo 2: Configurar Ambiente de Testes

1. Aceda ao painel do PSP
2. Ative o ambiente de testes/sandbox
3. Copie as credenciais de teste
4. Adicione-as ao ficheiro `.env`

### Passo 3: Implementar Integração

O código atual em `app/Services/PaymentService.php` está preparado para integração. Você precisa:

1. **Instalar SDK do PSP** (se disponível):
   ```bash
   composer require easypay/easypay-php-sdk
   ```

2. **Atualizar métodos no PaymentService**:
   - `generateMultibancoReference()` - Chamar API do PSP
   - `generateMBWayPayment()` - Chamar API do PSP
   - Adicionar tratamento de erros
   - Adicionar logging

### Exemplo de Integração com Easypay

```php
// Em app/Services/PaymentService.php

public function generateMultibancoReference(float $amount, int $subscriptionId, ?int $invoiceId = null): array
{
    $pspProvider = getenv('PSP_PROVIDER') ?: 'easypay';
    $environment = getenv('PSP_ENVIRONMENT') ?: 'sandbox';
    
    if ($pspProvider === 'easypay') {
        return $this->generateEasypayMultibanco($amount, $subscriptionId, $invoiceId, $environment);
    }
    
    // Fallback para mock (desenvolvimento)
    return $this->generateMockMultibanco($amount, $subscriptionId, $invoiceId);
}

protected function generateEasypayMultibanco(float $amount, int $subscriptionId, ?int $invoiceId, string $environment): array
{
    $apiKey = getenv('EASYPAY_API_KEY');
    $baseUrl = $environment === 'production' 
        ? getenv('EASYPAY_PRODUCTION_URL') 
        : getenv('EASYPAY_SANDBOX_URL');
    
    // Preparar dados do pagamento
    $paymentData = [
        'value' => $amount,
        'key' => $apiKey,
        'type' => 'mb',
        'expiration_time' => date('Y-m-d', strtotime('+3 days')),
        'customer' => [
            'name' => 'Cliente',
            'email' => 'cliente@example.com'
        ]
    ];
    
    // Chamar API Easypay
    $ch = curl_init($baseUrl . '/api/2.0/single');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($paymentData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'AccountId: ' . getenv('EASYPAY_ACCOUNT_ID')
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new \Exception('Erro ao gerar referência Multibanco: ' . $response);
    }
    
    $result = json_decode($response, true);
    
    // Criar registo de pagamento
    $paymentId = $this->paymentModel->create([
        'subscription_id' => $subscriptionId,
        'invoice_id' => $invoiceId,
        'user_id' => $this->getUserIdFromSubscription($subscriptionId),
        'amount' => $amount,
        'payment_method' => 'multibanco',
        'status' => 'pending',
        'external_payment_id' => $result['id'] ?? null,
        'reference' => $result['method']['entity'] . ' ' . 
                      $result['method']['reference'],
        'metadata' => [
            'entity' => $result['method']['entity'],
            'reference' => $result['method']['reference'],
            'expires_at' => $result['method']['expiration_time'] ?? null
        ]
    ]);
    
    return [
        'payment_id' => $paymentId,
        'entity' => $result['method']['entity'],
        'reference' => $result['method']['entity'] . ' ' . 
                      chunk_split($result['method']['reference'], 3, ' '),
        'amount' => number_format($amount, 2, ',', ''),
        'expires_at' => $result['method']['expiration_time'] ?? date('d/m/Y H:i', strtotime('+3 days'))
    ];
}
```

---

## Webhooks

Os webhooks são essenciais para receber confirmações de pagamento do PSP.

### Configuração de Webhook

1. **No painel do PSP**, configure a URL do webhook:
   ```
   https://seu-dominio.com/webhook/payment
   ```

2. **No sistema**, o webhook está implementado em:
   - Rota: `/webhook/payment` (definida em `routes.php`)
   - Controller: `app/Controllers/WebhookController.php`
   - Método: `handlePaymentWebhook()`

### Validação de Webhook

O sistema valida a assinatura do webhook para garantir segurança:

```php
// Em app/Controllers/WebhookController.php

protected function verifyWebhookSignature(string $payload, string $signature): bool
{
    $secret = getenv('PSP_WEBHOOK_SECRET');
    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expectedSignature, $signature);
}
```

### Formato Esperado do Webhook

O webhook deve receber dados no formato:

```json
{
    "id": "payment_external_id",
    "status": "completed",
    "amount": 29.99,
    "method": "multibanco",
    "metadata": {
        "subscription_id": 123,
        "invoice_id": 456
    }
}
```

---

## Testes

### Ambiente de Testes

1. Use sempre o ambiente `sandbox` durante desenvolvimento
2. Configure `PSP_ENVIRONMENT=sandbox` no `.env`
3. Use credenciais de teste fornecidas pelo PSP

### Testar Multibanco

1. Gere uma referência através do sistema
2. Use os dados de teste do PSP para simular pagamento
3. Verifique se o webhook é chamado corretamente
4. Confirme que a subscrição é ativada

### Testar MBWay

1. Gere um pagamento MBWay através do sistema
2. Use o número de teste fornecido pelo PSP
3. Confirme o pagamento na app MBWay (ambiente de testes)
4. Verifique se o webhook confirma o pagamento

### Dados de Teste Comuns

**Easypay Sandbox:**
- Número MBWay de teste: `912345678`
- Valores de teste: Qualquer valor até 100€

**Ifthenpay Sandbox:**
- Entidade de teste: `12345`
- Referência de teste: Gerada automaticamente

---

## Checklist de Produção

Antes de colocar em produção:

- [ ] Conta criada no PSP em modo produção
- [ ] Credenciais de produção configuradas no `.env`
- [ ] `PSP_ENVIRONMENT=production` configurado
- [ ] Webhook configurado no painel do PSP
- [ ] `PSP_WEBHOOK_SECRET` configurado e seguro
- [ ] SSL/HTTPS ativado (obrigatório para webhooks)
- [ ] Testes realizados em ambiente de produção
- [ ] Logging configurado para monitorizar pagamentos
- [ ] Processo de reconciliação implementado
- [ ] Suporte ao cliente preparado para questões de pagamento

---

## Suporte

Para questões sobre integração de pagamentos:
- Consulte a documentação do PSP escolhido
- Verifique os logs em `storage/logs/`
- Contacte o suporte do PSP para questões técnicas

---

## Notas Importantes

1. **Segurança**: Nunca commite o ficheiro `.env` com credenciais reais
2. **HTTPS**: Webhooks requerem HTTPS em produção
3. **Logging**: Mantenha logs de todos os pagamentos para auditoria
4. **Reconciliação**: Implemente processo de reconciliação periódica com o PSP
5. **Backup**: Mantenha backup das transações de pagamento





