# Sistema de Subscrições Baseado em Licenças

## Visão Geral

O sistema foi refatorado para implementar um modelo de subscrições baseado em licenças (frações ativas), substituindo o modelo anterior baseado em limites fixos de condomínios e frações.

## Principais Mudanças

### Modelo Anterior vs Novo Modelo

**Modelo Anterior:**
- Limites fixos: `limit_condominios`, `limit_fracoes`
- Planos: START, PRO, BUSINESS
- Preço fixo mensal

**Novo Modelo:**
- Licenças dinâmicas baseadas em frações ativas
- Planos: Condomínio (Base), Professional, Enterprise
- Pricing por escalões (tiered pricing)
- Associação múltipla de condomínios (Pro/Enterprise)
- Licenças extras configuráveis

## Estrutura de Planos

### 1. Condomínio (Base)
- **Tipo**: `condominio`
- **Licenças Mínimas**: 10 frações
- **Múltiplos Condomínios**: Não
- **Overage**: Não permitido
- **Pricing**: Escalões de 10-14, 15-19, 20-29, 30-39, 40+ frações

### 2. Professional
- **Tipo**: `professional`
- **Licenças Mínimas**: 50 frações
- **Múltiplos Condomínios**: Sim
- **Overage**: Não permitido (padrão)
- **Pricing**: Escalões de 50-99, 100-199, 200-499, 500+ frações

### 3. Enterprise
- **Tipo**: `enterprise`
- **Licenças Mínimas**: 200 frações
- **Múltiplos Condomínios**: Sim
- **Overage**: Permitido (configurável)
- **Pricing**: Escalões customizáveis

## Campos de Base de Dados

### Tabela `plans`
```sql
plan_type ENUM('condominio', 'professional', 'enterprise')
license_min INT                    -- Mínimo de licenças
license_limit INT NULL             -- Limite máximo (NULL = ilimitado)
allow_multiple_condos BOOLEAN     -- Permite múltiplos condomínios
allow_overage BOOLEAN              -- Permite exceder limite
pricing_mode ENUM('flat', 'progressive') DEFAULT 'flat'
annual_discount_percentage DECIMAL(5,2) DEFAULT 0
```

### Tabela `subscriptions`
```sql
condominium_id INT NULL            -- Para plano Base (único condomínio)
used_licenses INT DEFAULT 0       -- Cache de frações ativas
license_limit INT NULL             -- Limite configurado
allow_overage BOOLEAN DEFAULT FALSE
proration_mode ENUM('none', 'prorated') DEFAULT 'none'
charge_minimum BOOLEAN DEFAULT TRUE  -- Cobrar mínimo mesmo se usado < mínimo
extra_licenses INT DEFAULT 0       -- Licenças extras adicionadas manualmente
```

### Tabela `plan_pricing_tiers`
```sql
plan_id INT
min_licenses INT                   -- Mínimo de licenças do escalão
max_licenses INT NULL              -- Máximo (NULL = ilimitado)
price_per_license DECIMAL(10,2)   -- Preço por licença neste escalão
is_active BOOLEAN DEFAULT TRUE
sort_order INT DEFAULT 0
```

### Tabela `subscription_condominiums`
```sql
subscription_id INT
condominium_id INT
status ENUM('active', 'detached') DEFAULT 'active'
attached_at TIMESTAMP
detached_at TIMESTAMP NULL
detached_by INT NULL
notes TEXT NULL
```

### Tabela `condominiums`
```sql
subscription_id INT NULL           -- Para plano Base (referência direta)
subscription_status ENUM('active', 'locked', 'read_only') DEFAULT 'active'
locked_at TIMESTAMP NULL
locked_reason TEXT NULL
```

### Tabela `fractions`
```sql
license_consumed BOOLEAN DEFAULT TRUE  -- Conta para licenças
archived_at TIMESTAMP NULL             -- Quando arquivada, não conta
```

## Cálculo de Licenças

### Plano Base (Condomínio)
- Conta frações ativas do único condomínio associado
- Aplica mínimo de 10 frações se necessário
- Fórmula: `max(license_min, frações_ativas_do_condomínio)`

### Planos Professional/Enterprise
- Soma frações ativas de todos os condomínios associados
- Aplica mínimo do plano (50 ou 200)
- Fórmula: `max(license_min, soma_frações_ativas_todos_condomínios)`

### Frações Ativas
Uma fração conta como licença se:
- `is_active = TRUE`
- `archived_at IS NULL`
- `license_consumed = TRUE` (ou NULL)

## Pricing por Escalões

### Modo Flat (Padrão)
Todos as licenças são cobradas ao preço do escalão em que o total se enquadra.

**Exemplo (Plano Condomínio):**
- 25 frações → Escalão 20-29 → €0.80/fração
- Preço total: 25 × €0.80 = €20.00/mês

### Modo Progressive
As licenças são cobradas progressivamente por escalão.

**Exemplo (Plano Professional):**
- 150 frações:
  - Primeiras 50-99: 50 frações × €0.60 = €30.00
  - Próximas 100-199: 51 frações × €0.50 = €25.50
  - Total: €55.50/mês

## Gestão de Condomínios

### Associar Condomínio
- Disponível apenas para planos Professional/Enterprise
- Valida limites de licenças antes de associar
- Recalcula licenças automaticamente após associação

### Desassociar Condomínio
- Não permitido para plano Base (único condomínio)
- Não permitido se for o último condomínio
- Condomínio fica bloqueado (`subscription_status = 'locked'`) após desassociação
- Recalcula licenças automaticamente

## Licenças Extras

### Adicionar Licenças Extras
- Disponível para subscrições ativas (Professional/Enterprise)
- Cria invoice para pagamento das licenças adicionais
- Atualiza `extra_licenses` e `license_limit`

### Atualizar Licenças em Subscrição Pendente
- Permite ajustar número de frações extras antes do pagamento
- Recalcula preço total baseado em pricing tiers

## Validações e Limites

### Validação de Limites
- Verifica se excede `license_limit` (se configurado)
- Verifica se permite `overage` (apenas Enterprise)
- Aplica `license_min` se necessário

### Bloqueio de Condomínios
- Condomínios desassociados ficam bloqueados
- Não permitem operações até serem re-associados ou desbloqueados manualmente

## Migração de Dados

A migração `098_migrate_existing_subscriptions.php`:
1. Mapeia planos antigos para novos
2. Calcula licenças iniciais baseadas em frações ativas
3. Associa condomínios existentes
4. Cria pricing tiers padrão se não existirem

## APIs e Endpoints

### Subscrições
- `GET /subscription` - Ver subscrição atual
- `POST /subscription/attach-condominium` - Associar condomínio
- `POST /subscription/detach-condominium` - Desassociar condomínio
- `POST /subscription/add-active-licenses` - Adicionar licenças extras
- `POST /subscription/update-pending-licenses` - Atualizar licenças pendentes
- `GET /subscription/pricing-preview` - Preview de preços (AJAX)
- `POST /subscription/recalculate-licenses` - Recalcular licenças manualmente

### Administração
- `GET /admin/subscriptions-manage` - Lista de subscrições
- `GET /admin/subscriptions-manage/view/{id}` - Detalhes da subscrição
- `POST /admin/subscriptions-manage/attach-condominium` - Associar condomínio (admin)
- `POST /admin/subscriptions-manage/detach-condominium` - Desassociar condomínio (admin)
- `POST /admin/subscriptions-manage/recalculate-licenses` - Recalcular licenças (admin)
- `POST /admin/subscriptions-manage/toggle-condominium-lock` - Bloquear/desbloquear condomínio

## Serviços

### LicenseService
- `countActiveLicenses(int $subscriptionId): int`
- `countActiveLicensesByCondominium(int $condominiumId): int`
- `validateLicenseAvailability(int $subscriptionId, int $additionalLicenses): array`
- `applyMinimumCharge(int $subscriptionId, int $usedLicenses, int $minimum): int`
- `recalculateAndUpdate(int $subscriptionId): int`

### PricingService
- `calculateTieredPrice(int $planId, int $licenseCount, string $mode = 'flat'): float`
- `getPriceBreakdown(int $planId, int $licenseCount, string $mode = 'flat'): array`
- `calculateAnnualPrice(float $monthlyPrice, float $discountPercentage): float`
- `applyPromotion(float $basePrice, array $promotion): float`

### SubscriptionService
- `createSubscription(int $userId, int $planId, ?int $condominiumId = null, ?int $promotionId = null): int`
- `attachCondominium(int $subscriptionId, int $condominiumId, ?int $userId = null): bool`
- `detachCondominium(int $subscriptionId, int $condominiumId, ?int $userId = null, ?string $reason = null): bool`
- `recalculateUsedLicenses(int $subscriptionId): int`
- `canActivateFraction(int $condominiumId, int $fractionId): array`
- `getSubscriptionPricingPreview(int $subscriptionId, ?int $projectedUnits = null): array`
- `getCurrentCharge(int $subscriptionId): array`
- `validateSubscriptionLimits(int $subscriptionId): array`

## Middleware

### SubscriptionAccessMiddleware
- `hasActiveSubscription(int $condominiumId): bool`
- `isCondominiumLocked(int $condominiumId): bool`
- `validateLicenseLimits(int $subscriptionId, int $additionalLicenses = 0): array`
- `handle(int $condominiumId): bool`

## Testes

### Critérios de Aceitação
- Base: associar condomínio com 6 frações → used_licenses=10 (mínimo)
- Base: tentar associar segundo condomínio → erro
- Pro: associar 2 condomínios (30+40 frações) → used_licenses=70
- Pro: desassociar condomínio de 40 → used_licenses=30
- Pro com license_limit=60: tentar exceder → bloqueia (se allow_overage=false)
- Desassociar: condomínio fica locked e não permite operações

## Notas Importantes

1. **Compatibilidade**: Sistema antigo mantido para compatibilidade, mas não utilizado
2. **Transações**: Operações críticas (attach/detach) usam transações DB
3. **Auditoria**: Todas as operações são registadas em audit logs
4. **Performance**: Cache de `used_licenses` para evitar cálculos constantes
5. **Lock de Condomínios**: Por padrão, desassociar bloqueia acesso completo

## Exemplos de Uso

### Criar Subscrição Base
```php
$subscriptionService = new SubscriptionService();
$subscriptionId = $subscriptionService->createSubscription(
    $userId = 1,
    $planId = 1, // Plano Condomínio
    $condominiumId = 5
);
// Calcula automaticamente licenças baseadas em frações ativas
// Aplica mínimo de 10 se necessário
```

### Associar Condomínio (Pro/Enterprise)
```php
$subscriptionService->attachCondominium(
    $subscriptionId = 10,
    $condominiumId = 8,
    $userId = 1
);
// Valida limites antes de associar
// Recalcula licenças automaticamente
```

### Adicionar Licenças Extras
```php
// Via controller
POST /subscription/add-active-licenses
{
    "extra_licenses": 20,
    "csrf_token": "..."
}
// Cria invoice para pagamento
// Atualiza extra_licenses e license_limit após pagamento
```

## Troubleshooting

### Licenças não atualizam automaticamente
- Executar `POST /subscription/recalculate-licenses` manualmente
- Verificar se frações estão marcadas como `is_active = TRUE`
- Verificar se `archived_at IS NULL`
- Verificar se `license_consumed = TRUE` (ou NULL)

### Condomínio bloqueado após desassociação
- Normal: condomínios desassociados ficam bloqueados por padrão
- Desbloquear via admin: `POST /admin/subscriptions-manage/toggle-condominium-lock`
- Ou re-associar a uma subscrição

### Preços não correspondem aos escalões
- Verificar se pricing tiers estão ativos (`is_active = TRUE`)
- Verificar `pricing_mode` do plano (flat vs progressive)
- Verificar se `license_min` está sendo aplicado corretamente

### Erro: "Plano Base permite apenas um condomínio"
- O plano Condomínio (Base) só permite associar um condomínio
- Para múltiplos condomínios, fazer upgrade para Professional ou Enterprise

### Erro: "Excederia o limite de licenças"
- Verificar `license_limit` da subscrição
- Verificar se plano permite `allow_overage = true` (apenas Enterprise)
- Considerar adicionar licenças extras ou fazer upgrade

### Condomínio não aparece na lista para associar
- Verificar se condomínio já está associado a outra subscrição
- Verificar se condomínio está bloqueado (`subscription_status = 'locked'`)
- Verificar se subscrição permite múltiplos condomínios

### Licenças extras não são aplicadas
- Verificar se invoice foi pago (licenças extras só são aplicadas após pagamento)
- Verificar se subscrição está ativa
- Executar recálculo manual de licenças

## FAQ

### Como funciona o mínimo de licenças?
O mínimo de licenças (`license_min`) é aplicado automaticamente. Se um condomínio tem menos frações ativas que o mínimo, o sistema cobra pelo mínimo mesmo assim.

**Exemplo:** Plano Base com mínimo de 10 licenças, mas condomínio tem apenas 6 frações ativas → cobra por 10 licenças.

### Posso exceder o limite de licenças?
Apenas planos Enterprise permitem exceder o limite (`allow_overage = true`). Planos Condomínio e Professional bloqueiam operações quando o limite é atingido.

### Como adicionar mais licenças?
1. Via interface: `POST /subscription/add-active-licenses` com `extra_licenses`
2. Um invoice é criado para pagamento
3. Após pagamento, `license_limit` é atualizado automaticamente

### O que acontece quando desassocio um condomínio?
- O condomínio fica bloqueado (`subscription_status = 'locked'`)
- As licenças são recalculadas automaticamente
- O condomínio não permite operações até ser re-associado ou desbloqueado

### Como migrar de um plano para outro?
1. Escolher novo plano: `POST /subscription/change-plan`
2. Uma subscrição pendente é criada
3. Pagar invoice gerado
4. Subscrição antiga expira e nova é ativada

### Como funciona o pricing por escalões?
O preço depende do número total de licenças:
- **Modo Flat**: Todas as licenças são cobradas ao preço do escalão em que o total se enquadra
- **Modo Progressive**: Licenças são cobradas progressivamente por escalão

## Exemplos de Uso da API

### Obter informações da subscrição atual
```php
GET /subscription
Response: {
    "subscription": {
        "id": 1,
        "plan_name": "Professional",
        "used_licenses": 70,
        "license_limit": 100,
        "remaining_licenses": 30
    }
}
```

### Preview de preços antes de associar condomínio
```php
GET /subscription/pricing-preview?projected_units=80
Response: {
    "monthly_price": 64.00,
    "breakdown": [
        {
            "tier": "50-99",
            "licenses": 30,
            "price_per_license": 0.85,
            "subtotal": 25.50
        },
        {
            "tier": "100-199",
            "licenses": 50,
            "price_per_license": 0.80,
            "subtotal": 40.00
        }
    ]
}
```

### Associar condomínio (Professional/Enterprise)
```php
POST /subscription/attach-condominium
Body: {
    "condominium_id": 5,
    "csrf_token": "..."
}
Response: {
    "success": true,
    "message": "Condomínio associado com sucesso",
    "new_license_count": 85
}
```

### Adicionar licenças extras
```php
POST /subscription/add-active-licenses
Body: {
    "extra_licenses": 20,
    "csrf_token": "..."
}
Response: {
    "success": true,
    "invoice_id": 123,
    "amount": 16.00,
    "message": "Invoice criado. Após pagamento, as licenças serão adicionadas."
}
```

## Monitorização

### Script de Monitorização
Execute o script CLI para monitorizar uso de licenças:

```bash
# Verificar subscrições próximas do limite (80%)
php cli/monitor-licenses.php --alerts

# Gerar relatório semanal
php cli/monitor-licenses.php --report

# Verificar condomínios bloqueados há 30+ dias
php cli/monitor-licenses.php --long-locked --days=30

# Executar todas as verificações
php cli/monitor-licenses.php --alerts --report --long-locked
```

### LicenseMonitoringService
O serviço fornece métodos para:
- `checkSubscriptionsNearLimit(float $threshold = 0.8)` - Verificar subscrições próximas do limite
- `sendLimitAlerts(float $threshold = 0.8)` - Enviar alertas por email
- `generateWeeklyReport()` - Gerar relatório semanal
- `checkLongLockedCondominiums(int $days = 30)` - Verificar condomínios bloqueados há muito tempo

### Configurar Cron Job
Adicione ao crontab para monitorização automática:

```bash
# Verificar limites diariamente às 9h
0 9 * * * php /path/to/cli/monitor-licenses.php --alerts

# Gerar relatório semanal (segundas-feiras às 8h)
0 8 * * 1 php /path/to/cli/monitor-licenses.php --report
```

## Validação da Migração

Após executar a migração 098, valide os dados:

```bash
php cli/validate-migration-098.php
```

O script verifica:
- Se a migração foi executada
- Se todas as colunas necessárias existem
- Se as subscrições foram migradas corretamente
- Se as contagens de licenças estão corretas
- Se as associações de condomínios estão corretas
- Se há subscrições órfãs
- Se há referências quebradas
- Se há frações órfãs
- Se os preços dos tiers estão corretos
