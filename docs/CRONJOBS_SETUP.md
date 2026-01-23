# Sistema de Crons - Guia de Configuração

Este documento descreve todos os scripts CLI (cron jobs) disponíveis no sistema, como configurá-los e utilizá-los.

## Índice

1. [Visão Geral](#visão-geral)
2. [Scripts Disponíveis](#scripts-disponíveis)
3. [Configuração](#configuração)
4. [Troubleshooting](#troubleshooting)
5. [Monitorização](#monitorização)

## Visão Geral

O sistema utiliza scripts CLI PHP para executar tarefas agendadas automaticamente. Estes scripts devem ser configurados através de cron jobs (Linux/Mac) ou Task Scheduler (Windows).

### Requisitos

- PHP CLI instalado e configurado
- Acesso à base de dados configurado
- Permissões de escrita para logs (opcional)

## Scripts Disponíveis

### 1. Aviso de Renovação de Subscrição

**Ficheiro:** `cli/subscription-renewal-reminder.php`

**Descrição:** Envia emails de aviso X dias antes da renovação da subscrição.

**Uso:**
```bash
php cli/subscription-renewal-reminder.php [--days=X] [--dry-run]
```

**Parâmetros:**
- `--days=X`: Dias antes da renovação (padrão: 7)
- `--dry-run`: Modo teste sem enviar emails

**Frequência recomendada:** Diariamente às 9h00

**Exemplo:**
```bash
# Enviar avisos 7 dias antes (padrão)
php cli/subscription-renewal-reminder.php

# Enviar avisos 14 dias antes
php cli/subscription-renewal-reminder.php --days=14

# Testar sem enviar emails
php cli/subscription-renewal-reminder.php --dry-run
```

**Funcionalidades:**
- Consulta subscrições ativas que expiram em X dias
- Envia email de aviso com data de expiração e valor a pagar
- Cria notificação na base de dados
- Respeita preferências de email do utilizador
- Evita envios duplicados (verifica última notificação enviada)

---

### 2. Expiração e Bloqueio de Subscrições

**Ficheiro:** `cli/subscription-expiration-handler.php`

**Descrição:** Expira subscrições e bloqueia condomínios quando não pagas.

**Uso:**
```bash
php cli/subscription-expiration-handler.php [--dry-run]
```

**Parâmetros:**
- `--dry-run`: Modo teste sem fazer alterações

**Frequência recomendada:** Diariamente às 2h00

**Exemplo:**
```bash
# Processar expirações
php cli/subscription-expiration-handler.php

# Testar sem fazer alterações
php cli/subscription-expiration-handler.php --dry-run
```

**Funcionalidades:**
- Consulta subscrições ativas com `current_period_end` < hoje
- Atualiza status para `'expired'`
- Bloqueia todos os condomínios associados
- Cria notificação para o utilizador
- Envia email de aviso (se preferências permitirem)
- Regista ação em logs de auditoria

---

### 3. Processamento de Débitos Diretos Recorrentes

**Ficheiro:** `cli/process-recurring-direct-debits.php`

**Descrição:** Cria pagamentos de débito direto para subscrições ativas com método de pagamento 'direct_debit'.

**Uso:**
```bash
php cli/process-recurring-direct-debits.php [--days-before=X] [--dry-run]
```

**Parâmetros:**
- `--days-before=X`: Dias antes da expiração para criar pagamento (padrão: 3)
- `--dry-run`: Modo teste sem criar pagamentos

**Frequência recomendada:** Diariamente às 8h00

**Exemplo:**
```bash
# Processar débitos diretos
php cli/process-recurring-direct-debits.php

# Criar pagamentos 5 dias antes da expiração
php cli/process-recurring-direct-debits.php --days-before=5

# Testar sem criar pagamentos
php cli/process-recurring-direct-debits.php --dry-run
```

**Funcionalidades:**
- Consulta subscrições ativas com `payment_method = 'direct_debit'` ou `'sepa'`
- Verifica se expiram em X dias
- Verifica último pagamento bem-sucedido (evita duplicados)
- Calcula valor mensal (incluindo extras)
- Cria novo pagamento via `PaymentService::generateDirectDebitPayment()`
- Cria invoice se necessário
- Envia notificação ao utilizador

**Nota:** IfthenPay processa débitos automaticamente após criação do mandato, mas precisamos criar novos pagamentos mensais.

---

### 4. Notificação de Quotas no Dia Limite

**Ficheiro:** `cli/fee-due-date-notification.php`

**Descrição:** Cria notificações quando quotas passam do dia limite de pagamento.

**Uso:**
```bash
php cli/fee-due-date-notification.php [--days-after=X] [--dry-run]
```

**Parâmetros:**
- `--days-after=X`: Notificar X dias após data limite (padrão: 0, apenas no dia)
- `--dry-run`: Modo teste sem criar notificações

**Frequência recomendada:** Diariamente às 10h00

**Exemplo:**
```bash
# Notificar no dia limite
php cli/fee-due-date-notification.php

# Notificar 3 dias após data limite
php cli/fee-due-date-notification.php --days-after=3

# Testar sem criar notificações
php cli/fee-due-date-notification.php --dry-run
```

**Funcionalidades:**
- Consulta quotas com `status = 'pending'` e `due_date <= hoje`
- Identifica utilizador responsável (via `condominium_users` ligado à fração)
- Cria notificação na base de dados
- Envia email se preferências permitirem
- Evita notificações duplicadas (verifica se já existe notificação para esta quota hoje)

---

### 5. Notificação de Quotas em Atraso

**Ficheiro:** `cli/notify-overdue-fees.php`

**Descrição:** Envia notificações para quotas em atraso (melhorado).

**Uso:**
```bash
php cli/notify-overdue-fees.php [--days-after=X] [--dry-run]
```

**Parâmetros:**
- `--days-after=X`: Notificar apenas X dias após data limite (padrão: 0, todas as quotas em atraso)
- `--dry-run`: Modo teste sem enviar notificações

**Frequência recomendada:** Diariamente às 11h00

**Exemplo:**
```bash
# Notificar todas as quotas em atraso
php cli/notify-overdue-fees.php

# Notificar apenas quotas com mais de 7 dias de atraso
php cli/notify-overdue-fees.php --days-after=7

# Testar sem enviar notificações
php cli/notify-overdue-fees.php --dry-run
```

**Funcionalidades:**
- Consulta quotas em atraso (`due_date < hoje`)
- Agrupa quotas por utilizador e condomínio
- Cria notificações na base de dados
- Envia email se preferências permitirem
- Estatísticas detalhadas (total pendente, média de dias em atraso)
- Evita notificações duplicadas (verifica se já foi enviada hoje)

---

### 6. Arquivo de Dados Antigos

**Ficheiro:** `cli/archive-old-data.php`

**Descrição:** Move dados antigos para tabelas de backup para manter base de dados otimizada sem perder histórico.

**Uso:**
```bash
php cli/archive-old-data.php [--days-notifications=X] [--years-audit=X] [--dry-run]
```

**Parâmetros:**
- `--days-notifications=X`: Dias para arquivar notificações (padrão: 90)
- `--years-audit=X`: Anos para arquivar logs de auditoria (padrão: 1)
- `--dry-run`: Modo teste sem arquivar

**Frequência recomendada:** Semanalmente, domingo às 3h00

**Exemplo:**
```bash
# Arquivar dados antigos (padrão: 90 dias notificações, 1 ano logs)
php cli/archive-old-data.php

# Arquivar notificações com mais de 60 dias
php cli/archive-old-data.php --days-notifications=60

# Arquivar logs com mais de 2 anos
php cli/archive-old-data.php --years-audit=2

# Testar sem arquivar
php cli/archive-old-data.php --dry-run
```

**Funcionalidades:**
- Move notificações lidas > X dias para `notifications_archive`
- Move logs de auditoria > X anos para `audit_logs_archive`, `audit_payments_archive`, `audit_subscriptions_archive`, `audit_financial_archive`, `audit_documents_archive`
- Limpa tokens de password reset expirados (apagados definitivamente)
- Limpa convites expirados (apagados definitivamente)
- Usa transações DB para garantir atomicidade
- Estatísticas de arquivo

**Tabelas de Backup:**
- `notifications_archive`
- `audit_logs_archive`
- `audit_payments_archive`
- `audit_subscriptions_archive`
- `audit_financial_archive`
- `audit_documents_archive`

---

### 7. Verificação de Integridade de Dados

**Ficheiro:** `cli/verify-data-integrity.php`

**Descrição:** Verifica e opcionalmente corrige inconsistências de dados.

**Uso:**
```bash
php cli/verify-data-integrity.php [--fix] [--dry-run]
```

**Parâmetros:**
- `--fix`: Corrigir automaticamente (cuidado!)
- `--dry-run`: Apenas reportar (padrão)

**Frequência recomendada:** Semanalmente, segunda-feira às 4h00

**Exemplo:**
```bash
# Verificar apenas (sem corrigir)
php cli/verify-data-integrity.php

# Verificar e corrigir automaticamente
php cli/verify-data-integrity.php --fix

# Testar correções sem aplicar
php cli/verify-data-integrity.php --fix --dry-run
```

**Funcionalidades:**
- Verifica `used_licenses` vs frações ativas reais
- Verifica `license_limit` vs `license_min + extra_licenses`
- Verifica condomínios bloqueados sem motivo
- Verifica subscrições expiradas não bloqueadas
- Relatório de inconsistências encontradas
- Correção automática (se `--fix` for usado)

---

## Configuração

### Linux/Mac (crontab)

Edite o crontab:
```bash
crontab -e
```

Adicione as seguintes linhas (ajuste os caminhos conforme necessário):

```bash
# Aviso de renovação (diariamente às 9h00)
0 9 * * * cd /caminho/para/predio && php cli/subscription-renewal-reminder.php >> /var/log/subscription-reminder.log 2>&1

# Expiração e bloqueio (diariamente às 2h00)
0 2 * * * cd /caminho/para/predio && php cli/subscription-expiration-handler.php >> /var/log/subscription-expiration.log 2>&1

# Débitos diretos mensais (diariamente às 8h00)
0 8 * * * cd /caminho/para/predio && php cli/process-recurring-direct-debits.php >> /var/log/direct-debit.log 2>&1

# Notificação de quotas no dia limite (diariamente às 10h00)
0 10 * * * cd /caminho/para/predio && php cli/fee-due-date-notification.php >> /var/log/fee-notification.log 2>&1

# Quotas em atraso (diariamente às 11h00)
0 11 * * * cd /caminho/para/predio && php cli/notify-overdue-fees.php >> /var/log/overdue-fees.log 2>&1

# Arquivo de dados antigos (semanalmente, domingo às 3h00)
0 3 * * 0 cd /caminho/para/predio && php cli/archive-old-data.php >> /var/log/archive.log 2>&1

# Verificação de integridade (semanalmente, segunda-feira às 4h00)
0 4 * * 1 cd /caminho/para/predio && php cli/verify-data-integrity.php >> /var/log/integrity.log 2>&1
```

### Windows (Task Scheduler)

1. Abra o Task Scheduler
2. Crie uma nova tarefa agendada para cada script
3. Configure:
   - **Program/script:** `C:\xampp\php\php.exe` (ou caminho do PHP)
   - **Add arguments:** `cli/subscription-renewal-reminder.php`
   - **Start in:** `C:\xampp\htdocs\predio` (ou caminho do projeto)
   - **Trigger:** Configure conforme frequência recomendada

### Verificar se os crons estão a funcionar

Execute manualmente cada script para verificar se funciona:

```bash
php cli/subscription-renewal-reminder.php --dry-run
```

## Troubleshooting

### Erro: "Database connection not available"

**Causa:** Base de dados não configurada ou não acessível.

**Solução:**
1. Verifique `config.php` e `database.php`
2. Verifique credenciais da base de dados
3. Verifique se a base de dados está acessível

### Erro: "Permission denied"

**Causa:** Sem permissões para executar o script ou escrever logs.

**Solução:**
```bash
chmod +x cli/*.php
chmod 755 cli/
```

### Emails não são enviados

**Causa:** Configuração SMTP incorreta ou preferências de email desativadas.

**Solução:**
1. Verifique configuração SMTP em `.env`
2. Verifique `user_email_preferences` na base de dados
3. Execute com `--dry-run` para ver o que seria enviado

### Logs não são criados

**Causa:** Diretório de logs não existe ou sem permissões.

**Solução:**
```bash
mkdir -p /var/log
chmod 755 /var/log
```

### Scripts executam mas não fazem nada

**Causa:** Pode não haver dados para processar ou filtros muito restritivos.

**Solução:**
1. Execute com `--dry-run` para ver o que seria processado
2. Verifique logs para mais detalhes
3. Verifique dados na base de dados diretamente

## Monitorização

### Logs

Todos os scripts geram output detalhado. Redirecione para ficheiros de log:

```bash
php cli/subscription-renewal-reminder.php >> /var/log/subscription-reminder.log 2>&1
```

### Verificar execução

Verifique os logs regularmente:

```bash
tail -f /var/log/subscription-reminder.log
```

### Alertas

Configure alertas para:
- Falhas na execução dos scripts
- Erros críticos nos logs
- Subscrições expiradas não processadas
- Quotas em atraso não notificadas

### Estatísticas

Execute scripts com `--dry-run` periodicamente para ver estatísticas:

```bash
php cli/verify-data-integrity.php
php cli/archive-old-data.php --dry-run
```

## Considerações Importantes

1. **Débitos Diretos**: Verifique com IfthenPay se há API para débitos automáticos recorrentes ou se precisamos criar manualmente cada mês
2. **Meses em Atraso**: A lógica está no `PaymentController` quando utilizador tenta pagar
3. **Preferências de Email**: Todos os crons respeitam `UserEmailPreference`
4. **Demo Users**: Nunca enviar emails para utilizadores demo
5. **Logs**: Todos os crons têm logging detalhado
6. **Transações**: Operações críticas usam transações DB
7. **Idempotência**: Crons são idempotentes (podem executar múltiplas vezes sem problemas)
8. **Arquivo vs Apagar**: Notificações e logs são **movidos** para tabelas de backup, não apagados
9. **Tabelas de Backup**: Criadas via migration `099_create_archive_tables.php`
10. **Recuperação**: Dados arquivados podem ser consultados mas não aparecem nas queries normais

## Suporte

Para questões ou problemas, consulte:
- Logs dos scripts
- Logs da aplicação
- Base de dados (`audit_logs` para ações importantes)
