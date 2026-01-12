# Configuração do Cronjob para Restauração Automática de Dados Demo

Este documento explica como configurar um cronjob para restaurar automaticamente os dados demo da aplicação.

## Objetivo

O cronjob executa o script `cli/restore-demo.php` periodicamente para restaurar os dados demo ao estado original, removendo todas as alterações feitas pelos utilizadores.

## Script de Restauração

O script `cli/restore-demo.php`:
- Identifica todos os condomínios marcados como demo (`is_demo = TRUE`)
- Remove todas as alterações feitas pelos utilizadores
- Repopula os dados demo executando o `DemoSeeder`

## Configuração do Cronjob

### Linux/Mac (crontab)

Para executar diariamente às 3h00:

```bash
0 3 * * * cd /caminho/para/predio && php cli/restore-demo.php >> /var/log/demo-restore.log 2>&1
```

Para executar a cada 6 horas:

```bash
0 */6 * * * cd /caminho/para/predio && php cli/restore-demo.php >> /var/log/demo-restore.log 2>&1
```

Para executar a cada hora:

```bash
0 * * * * cd /caminho/para/predio && php cli/restore-demo.php >> /var/log/demo-restore.log 2>&1
```

### Windows (Task Scheduler)

1. Abra o **Agendador de Tarefas** (Task Scheduler)
2. Crie uma nova tarefa básica
3. Configure:
   - **Nome**: Restaurar Dados Demo
   - **Disparador**: Diariamente às 3h00 (ou conforme necessário)
   - **Ação**: Iniciar um programa
   - **Programa/script**: `C:\xampp\php\php.exe`
   - **Adicionar argumentos**: `C:\xampp\htdocs\predio\cli\restore-demo.php`
   - **Iniciar em**: `C:\xampp\htdocs\predio`

### Teste Manual

Para testar o script manualmente:

```bash
# Modo dry-run (sem fazer alterações)
php cli/restore-demo.php --dry-run

# Execução real
php cli/restore-demo.php
```

## Logs

Os logs são escritos para:
- **Linux/Mac**: `/var/log/demo-restore.log` (ou o caminho especificado no crontab)
- **Windows**: Verificar a saída do Task Scheduler

## Frequência Recomendada

- **Desenvolvimento/Teste**: A cada hora ou a cada 6 horas
- **Produção**: Diariamente às 3h00 (horário de menor tráfego)

## Notas Importantes

1. O script remove **TODAS** as alterações feitas nos dados demo
2. Certifique-se de que o utilizador demo não pode editar os seus próprios acessos
3. O script preserva apenas o utilizador demo principal
4. Todos os outros dados são removidos e repopulados

## Troubleshooting

### Erro: "Nenhum condomínio demo encontrado"
- Verifique se existe pelo menos um condomínio com `is_demo = TRUE`
- Execute o `DemoSeeder` primeiro: `php database/seeders/DemoSeeder.php`

### Erro de permissões
- Certifique-se de que o utilizador do cronjob tem permissões para executar PHP
- Certifique-se de que o utilizador tem permissões de escrita na base de dados

### Erro de caminho
- Use caminhos absolutos no crontab
- Certifique-se de que o `cd` está correto antes de executar o script PHP
