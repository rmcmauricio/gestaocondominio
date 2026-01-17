---
name: Demo Management System
overview: ""
todos: []
---

# Sistema de Gestão de Dados Demo

## Objetivo

Criar um sistema robusto para gerir dados demo que evita regeneração desnecessária de recibos e permite limpeza eficiente de dados de teste.

## Arquitetura

### 1. Script `cli/delete-condominium.php`

**Função**: Remove completamente todos os dados de um condomínio (genérico, pode ser usado para demo ou qualquer condomínio)

**Uso**:

- `php cli/delete-condominium.php` - Remove todos os condomínios demo
- `php cli/delete-condominium.php --condominium-id=123` - Remove condomínio específico por ID
- `php cli/delete-condominium.php --dry-run` - Modo de teste

**Funcionalidades**:

- Remove dados em ordem correta respeitando foreign keys
- Remove arquivos associados (PDFs, documentos, anexos)
- Verifica se condomínio existe antes de remover
- Para condomínios demo, verifica `is_demo = TRUE` como segurança adicional
- Remove dados de todas as tabelas relacionadas (ver lista abaixo)

**Tabelas a limpar** (em ordem de dependência):

- `minutes_signatures` → `assembly_votes` → `assembly_vote_topics` → `assembly_attendees` → `assemblies`
- `standalone_vote_responses` → `standalone_votes`
- `vote_options` (apenas se não usado por outros condomínios)
- `fee_payment_history` → `fee_payments` → `fees`
- `receipts` (com remoção de PDFs)
- `financial_transactions` → `bank_accounts`
- `reservations` → `spaces`
- `occurrence_comments`, `occurrence_history` → `occurrences`
- `expenses`
- `budget_items` → `budgets`
- `contracts`
- `suppliers`
- `condominium_users`
- `fractions`
- `message_attachments` → `messages`
- `occurrence_attachments`
- `documents` (com remoção de arquivos)
- `notifications`
- `revenues`
- `condominiums` (por último)

### 2. Script `cli/install-demo.php`

**Função**: Instala dados demo pela primeira vez e guarda snapshot dos IDs criados

**Uso**: `php cli/install-demo.php`

**Funcionalidades**:

- Verifica se demo já existe (se sim, pede confirmação para reinstalar)
- Executa `DemoSeeder->run()` para criar dados
- Após criação, captura todos os IDs criados por tabela
- Guarda snapshot em `storage/demo/original_ids.json`
- Estrutura do JSON:
```json
{
  "created_at": "2024-01-01 12:00:00",
  "demo_user_id": 1,
  "condominiums": [78, 79],
  "fractions": [166, 167, 168, ...],
  "fees": [1234, 1235, ...],
  "fee_payments": [567, 568, ...],
  "receipts": [89, 90, ...],
  "bank_accounts": [12, 13],
  "suppliers": [5, 6],
  "spaces": [3, 4],
  "budgets": [10, 11],
  "budget_items": [45, 46, ...],
  "assemblies": [2, 3],
  "standalone_votes": [1, 2, 3],
  "vote_options": [1, 2, 3],
  "condominium_users": [234, 235, ...],
  "financial_transactions": [100, 101, ...],
  "expenses": [50, 51, ...],
  "occurrences": [20, 21, ...],
  "messages": [15, 16, ...],
  "documents": [8, 9, ...],
  "notifications": [200, 201, ...],
  "revenues": [30, 31, ...]
}
```


**Modificações no `DemoSeeder`**:

- Adicionar método `getCreatedIds()` que retorna array com todos os IDs criados
- Manter tracking de IDs durante criação (já existe parcialmente com `$demoCondominiumIds`, `$fractionIds`, etc.)
- Adicionar tracking para todas as tabelas criadas

### 3. Script `cli/restore-demo.php` (modificado)

**Função**: Restaura dados demo removendo apenas alterações de utilizadores, preservando dados originais

**Uso**: `php cli/restore-demo.php [--dry-run]`

**Funcionalidades**:

- Lê `storage/demo/original_ids.json`
- Se não existir, executa `install-demo.php` primeiro
- Para cada tabela relacionada a condomínios demo:
  - Identifica registos que pertencem a condomínios demo (`condominium_id IN (demo_condominium_ids)`)
  - Remove apenas registos que NÃO estão na lista de IDs originais
  - Preserva registos originais (incluindo recibos)
- Para recibos: verifica se PDF existe, se não existir regenera apenas esse
- Remove arquivos de registos deletados (PDFs, documentos, anexos)
- Não recria dados se já existem (verifica antes de criar)

**Lógica de limpeza**:

```php
// Para cada tabela com condominium_id
$originalIds = $snapshot['table_name'] ?? [];
$stmt = $db->prepare("
    SELECT id FROM table_name 
    WHERE condominium_id IN (:demo_condominium_ids)
    AND id NOT IN (" . implode(',', $originalIds) . ")
");
// Remove apenas esses IDs
```

**Tabelas especiais**:

- `receipts`: Verifica se PDF existe, regenera apenas se faltar
- `users`: Remove apenas se não associados a condomínios não-demo
- `vote_options`: Pode ser partilhado, verificar antes de remover

## Estrutura de Ficheiros

```

cli/

├── delete-condominium.php (novo)

├── install-demo.php (novo)

└── restore-demo.php (modificado)

storage/

└── demo/

└── original_ids.json (criado p