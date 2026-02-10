# Guia de Troubleshooting - Sistema de Subscrições

Este documento fornece soluções para problemas comuns relacionados ao sistema de subscrições baseado em licenças.

## Problemas Comuns

### 1. Licenças não atualizam automaticamente

**Sintomas:**
- `used_licenses` não reflete o número real de frações ativas
- Preços não correspondem ao número de frações

**Soluções:**
1. Recalcular manualmente via interface: `POST /subscription/recalculate-licenses`
2. Verificar se frações estão marcadas corretamente:
   ```sql
   SELECT * FROM fractions 
   WHERE condominium_id = ? 
   AND is_active = TRUE 
   AND archived_at IS NULL
   AND (license_consumed = TRUE OR license_consumed IS NULL)
   ```
3. Verificar se há frações arquivadas que ainda contam:
   ```sql
   SELECT * FROM fractions 
   WHERE archived_at IS NOT NULL 
   AND license_consumed = TRUE
   ```

### 2. Condomínio bloqueado após desassociação

**Sintomas:**
- Condomínio não permite operações
- Mensagem: "Este condomínio está bloqueado"

**Soluções:**
1. **Desbloquear via Admin:**
   ```php
   POST /admin/subscriptions-manage/toggle-condominium-lock
   Body: { "condominium_id": 5, "action": "unlock" }
   ```

2. **Re-associar a uma subscrição:**
   ```php
   POST /subscription/attach-condominium
   Body: { "condominium_id": 5 }
   ```

3. **Verificar status:**
   ```sql
   SELECT subscription_status, locked_at, locked_reason 
   FROM condominiums 
   WHERE id = ?
   ```

### 3. Erro: "Plano Base permite apenas um condomínio"

**Causa:**
- Tentativa de associar segundo condomínio a plano Condomínio (Base)

**Soluções:**
1. Fazer upgrade para plano Profissional ou Enterprise
2. Desassociar condomínio atual antes de associar novo (não recomendado)

### 4. Erro: "Excederia o limite de licenças"

**Causa:**
- Tentativa de adicionar frações ou condomínios que excedem `license_limit`
- Plano não permite `overage`

**Soluções:**
1. **Adicionar licenças extras:**
   ```php
   POST /subscription/add-active-licenses
   Body: { "extra_licenses": 20 }
   ```

2. **Fazer upgrade para Enterprise** (permite overage)

3. **Arquivar frações não utilizadas:**
   ```sql
   UPDATE fractions 
   SET archived_at = NOW(), license_consumed = FALSE 
   WHERE id = ?
   ```

### 5. Preços não correspondem aos escalões

**Sintomas:**
- Preço calculado não corresponde ao esperado pelo escalão

**Verificações:**
1. Verificar se pricing tiers estão ativos:
   ```sql
   SELECT * FROM plan_pricing_tiers 
   WHERE plan_id = ? 
   AND is_active = TRUE 
   ORDER BY sort_order
   ```

2. Verificar `pricing_mode` do plano:
   ```sql
   SELECT pricing_mode FROM plans WHERE id = ?
   ```
   - `flat`: Todas as licenças ao preço do escalão
   - `progressive`: Preços progressivos por escalão

3. Verificar se mínimo está sendo aplicado:
   ```sql
   SELECT used_licenses, license_min, charge_minimum 
   FROM subscriptions 
   WHERE id = ?
   ```

### 6. Condomínio não aparece para associar

**Verificações:**
1. Verificar se já está associado:
   ```sql
   SELECT * FROM subscription_condominiums 
   WHERE condominium_id = ? 
   AND status = 'active'
   ```

2. Verificar se está bloqueado:
   ```sql
   SELECT subscription_status FROM condominiums WHERE id = ?
   ```

3. Verificar se subscrição permite múltiplos condomínios:
   ```sql
   SELECT p.allow_multiple_condos 
   FROM subscriptions s
   INNER JOIN plans p ON s.plan_id = p.id
   WHERE s.id = ?
   ```

### 7. Licenças extras não são aplicadas

**Verificações:**
1. Verificar se invoice foi pago:
   ```sql
   SELECT status FROM invoices 
   WHERE subscription_id = ? 
   AND type = 'extra_licenses'
   ORDER BY created_at DESC
   ```

2. Verificar se subscrição está ativa:
   ```sql
   SELECT status FROM subscriptions WHERE id = ?
   ```

3. Executar recálculo manual:
   ```php
   POST /subscription/recalculate-licenses
   ```

### 8. Subscrição não aparece após criação

**Verificações:**
1. Verificar status da subscrição:
   ```sql
   SELECT status, trial_ends_at, current_period_end 
   FROM subscriptions 
   WHERE user_id = ?
   ```

2. Verificar se trial expirou:
   ```sql
   SELECT * FROM subscriptions 
   WHERE user_id = ? 
   AND status = 'trial' 
   AND trial_ends_at < NOW()
   ```

### 9. Erro ao associar condomínio: "Condomínio já está associado"

**Soluções:**
1. Verificar associações existentes:
   ```sql
   SELECT * FROM subscription_condominiums 
   WHERE condominium_id = ?
   ```

2. Desassociar primeiro se necessário:
   ```php
   POST /subscription/detach-condominium
   Body: { "condominium_id": 5 }
   ```

### 10. Contagem de licenças incorreta após migração

**Soluções:**
1. Executar script de validação:
   ```bash
   php cli/validate-migration-098.php
   ```

2. Recalcular todas as subscrições:
   ```sql
   -- Para cada subscrição ativa
   UPDATE subscriptions 
   SET used_licenses = (
       SELECT COUNT(*) 
       FROM fractions f
       INNER JOIN subscription_condominiums sc ON f.condominium_id = sc.condominium_id
       WHERE sc.subscription_id = subscriptions.id
       AND sc.status = 'active'
       AND f.is_active = TRUE
       AND f.archived_at IS NULL
   )
   WHERE status IN ('trial', 'active')
   ```

## Comandos Úteis SQL

### Verificar subscrição de um utilizador
```sql
SELECT s.*, p.name as plan_name, p.plan_type
FROM subscriptions s
INNER JOIN plans p ON s.plan_id = p.id
WHERE s.user_id = ?
AND s.status IN ('trial', 'active')
```

### Verificar frações ativas de um condomínio
```sql
SELECT COUNT(*) as active_fractions
FROM fractions
WHERE condominium_id = ?
AND is_active = TRUE
AND archived_at IS NULL
AND (license_consumed = TRUE OR license_consumed IS NULL)
```

### Verificar condomínios associados a uma subscrição
```sql
SELECT c.*, sc.status as association_status
FROM subscription_condominiums sc
INNER JOIN condominiums c ON sc.condominium_id = c.id
WHERE sc.subscription_id = ?
AND sc.status = 'active'
```

### Verificar pricing tiers de um plano
```sql
SELECT * FROM plan_pricing_tiers
WHERE plan_id = ?
AND is_active = TRUE
ORDER BY sort_order, min_licenses
```

## Logs e Debugging

### Verificar logs de subscrições
```sql
SELECT * FROM audit_subscriptions
WHERE subscription_id = ?
ORDER BY created_at DESC
LIMIT 50
```

### Verificar erros recentes
Verificar arquivo de logs: `storage/logs/app.log`

### Ativar modo debug
No `config.php`, definir:
```php
define('DEBUG', true);
```

## Suporte

Para mais ajuda:
1. Consultar `docs/LICENSE_BASED_SUBSCRIPTIONS.md` para documentação completa
2. Executar `php cli/validate-migration-098.php` para validar dados
3. Executar `php cli/monitor-licenses.php --report` para relatório de uso
4. Verificar logs em `storage/logs/`
