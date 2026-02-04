# Cleanup: Migração para Modelo de Licenças

## Status da Migração

O sistema foi migrado para um modelo baseado em licenças (frações ativas) com pricing por escalões. Este documento lista o código obsoleto que ainda precisa ser removido ou atualizado.

## Campos Obsoletos (Mantidos para Compatibilidade Temporária)

### `extra_condominiums`
- **Status**: Campo ainda existe na tabela `subscriptions` para compatibilidade
- **Uso Atual**: Ainda usado em métodos legados (`startTrial`, `changePlan`)
- **Ação**: Substituído pelo sistema de associação múltipla via `subscription_condominiums`
- **Arquivos Afetados**:
  - `app/Services/SubscriptionService.php` (métodos `startTrial`, `changePlan`)
  - `app/Models/Subscription.php` (método `create`)
  - Vários controllers e views

### `limit_condominios` e `limit_fracoes`
- **Status**: Campos ainda existem na tabela `plans` para compatibilidade
- **Uso Atual**: Ainda usados em métodos legados (`canCreateCondominium`, `canCreateFraction`)
- **Ação**: Substituídos por `license_min`, `license_limit`, `allow_multiple_condos`
- **Arquivos Afetados**:
  - `app/Models/Subscription.php` (métodos `canCreateCondominium`, `canCreateFraction`)
  - `app/Models/Plan.php` (queries que selecionam esses campos)
  - Várias views

## Métodos que Precisam ser Atualizados

### `Subscription::canCreateCondominium()`
- **Problema**: Usa `limit_condominios` do plano antigo
- **Solução**: Deve usar o novo modelo de licenças e verificar `allow_multiple_condos`
- **Status**: ⚠️ Precisa atualização

### `Subscription::canCreateFraction()`
- **Problema**: Usa `limit_fracoes` do plano antigo
- **Solução**: Deve usar `LicenseService::validateLicenseAvailability()`
- **Status**: ⚠️ Precisa atualização

### `SubscriptionService::startTrial()`
- **Problema**: Aceita `extraCondominiums` que não é mais usado
- **Solução**: Remover parâmetro ou manter para compatibilidade durante transição
- **Status**: ⚠️ Decisão necessária

### `SubscriptionService::changePlan()`
- **Problema**: Usa `extraCondominiums` para plano Business antigo
- **Solução**: Remover lógica de extra condominiums, usar novo modelo
- **Status**: ⚠️ Precisa atualização

## Arquivos que Podem ser Removidos (Após Migração Completa)

### Migrações Antigas (Manter para Histórico)
- `database/migrations/087_create_plan_extra_condominiums_pricing_table.php`
- `database/migrations/088_add_extra_condominiums_to_subscriptions.php`
- **Nota**: Manter migrações para histórico, mas código de negócio não deve mais usar

### Models Obsoletos
- `app/Models/PlanExtraCondominiumsPricing.php`
- **Nota**: Pode ser removido após confirmar que não é mais usado

## Plano de Ação Recomendado

### Fase 1: Atualizar Métodos Core (Prioridade Alta)
1. ✅ Atualizar `Subscription::canCreateCondominium()` para usar novo modelo
2. ✅ Atualizar `Subscription::canCreateFraction()` para usar `LicenseService`
3. ⚠️ Decidir sobre `startTrial()` e `changePlan()` - manter compatibilidade ou remover?

### Fase 2: Atualizar Controllers e Views (Prioridade Média)
1. Remover referências a `extra_condominiums` em views
2. Atualizar controllers para não passar `extraCondominiums`
3. Atualizar formulários de escolha de plano

### Fase 3: Remover Código Obsoleto (Após Migração Completa)
1. Remover `PlanExtraCondominiumsPricing` model
2. Remover campos `extra_condominiums` da tabela (migração)
3. Marcar `limit_condominios` e `limit_fracoes` como deprecated

## Notas Importantes

- **Compatibilidade**: Durante a transição, alguns campos antigos são mantidos para não quebrar subscrições existentes
- **Migração de Dados**: Script de migração (`098_migrate_existing_subscriptions.php`) deve mapear dados antigos para novo modelo
- **Testes**: Todos os testes devem usar o novo modelo de licenças
- **Documentação**: Atualizar README e documentação de API após cleanup completo

## Data de Criação
2026-01-23

## Última Atualização
2026-01-23
