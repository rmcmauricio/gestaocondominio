---
name: Melhorar Demo com Dados Distintos
overview: "Melhorar a demo criando dois condomínios distintos: o primeiro terá dívidas históricas, mensagens trocadas em 2025, reservas de espaços, orçamento 2026 e quotas com quota extra para obras. O primeiro condomínio será definido como padrão e o restore-demo será atualizado para lidar com todas as novas funcionalidades."
todos: []
---

# Plano: Melhorar Demo com Dados Distintos

## Objetivo

Criar dois condomínios demo distintos e realistas, com o primeiro tendo:

- Dívidas históricas de 1 fração
- Ano 2025 todo em dívida para algumas frações
- Frações com meses em atraso de 2025
- Mensagens trocadas entre admin e condóminos em 2025
- Reservas de espaços feitas por condóminos em 2025
- Orçamento para 2026
- Quotas geradas para 2026 com quota extra para obras (liquidar até 12/2026)
- **Definido como condomínio padrão**

## Estrutura dos Condomínios

### Condomínio 1: "Residencial Sol Nascente" (Lisboa) - **PADRÃO**

- **Estado financeiro problemático:**
  - Fração 1A: Dívidas históricas (2024) + todo 2025 em dívida
  - Frações 2A, 2B: Alguns meses de 2025 em atraso
  - Restantes frações: Algumas pagas, outras pendentes
- **Atividade em 2025:**
  - Mensagens trocadas entre admin e condóminos
  - Reservas de espaços comuns
- **2026:**
  - Orçamento aprovado
  - Quotas regulares geradas
  - Quota extra para obras (distribuída até 12/2026)

### Condomínio 2: "Edifício Mar Atlântico" (Porto)

- **Estado financeiro saudável:**
  - Maioria das quotas pagas
  - Poucas pendências
- **Atividade normal**

## Implementação

### 1. Modificar `generateFees2025()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- **Condomínio 1 (index 0):**
  - Criar dívidas históricas para fração 1A (alguns meses de 2024)
  - Criar todas as quotas de 2025
  - **Fração 1A:** Nenhum pagamento em 2025 (todo ano em dívida)
  - **Frações 2A, 2B:** Pagamentos parciais (alguns meses pagos, outros em atraso)
  - **Restantes frações:** 75% pagas (distribuídas aleatoriamente)
- **Condomínio 2 (index 1):**
  - Manter lógica atual (75% pagas)

### 2. Criar método `createMessages2025()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Apenas para Condomínio 1
- Criar mensagens entre admin e condóminos:
  - Mensagens do admin para todos (anúncios) - `to_user_id = NULL`
  - Mensagens privadas entre admin e frações específicas
  - Algumas mensagens com respostas (thread_id)
  - Datas distribuídas ao longo de 2025
  - Usar HTML básico no conteúdo das mensagens
- Importar `use App\Models\Message;`

### 3. Modificar `createReservations()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Apenas para Condomínio 1
- Criar reservas de espaços em 2025:
  - Várias reservas ao longo do ano (8-12 reservas)
  - Diferentes espaços (Salão, Piscina, Ténis)
  - Diferentes condóminos
  - Diferentes estados (approved, pending, cancelled)
  - Datas em 2025

### 4. Criar método `createBudget2026()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Apenas para Condomínio 1
- Criar orçamento para 2026:
  - Status: `approved` ou `active`
  - Itens de receita e despesa
  - Similar ao orçamento 2025 mas com valores atualizados (+5-10%)

### 5. Criar método `generateFees2026()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Apenas para Condomínio 1
- Gerar quotas regulares para 2026:
  - Usar `FeeService->generateMonthlyFees()` para todos os meses
  - Primeiros 3-4 meses pagos
- Gerar quota extra para obras:
  - Usar `FeeService->generateExtraFees()`
  - Total: €5000 distribuído por permilagem
  - Descrição: "Quota Extra - Obras de Renovação"
  - Distribuída ao longo de 2026 (meses 1-12)
  - Primeiros 2-3 meses pagos

### 6. Atualizar método `createDemoCondominiums()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Garantir que o primeiro condomínio (index 0) seja definido como padrão:
  ```php
  // Set first condominium as default for demo user
  if (!empty($this->demoCondominiumIds)) {
      $userModel = new User();
      $userModel->setDefaultCondominium($this->demoUserId, $this->demoCondominiumIds[0]);
      echo "   Condomínio padrão definido: {$condominiumsData[0]['name']} (ID: {$this->demoCondominiumIds[0]})\n";
  }
  ```


### 7. Atualizar método `run()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Adicionar chamadas condicionais:
  ```php
  if ($index === 0) { // Condomínio 1 - Mais detalhado
      $this->createMessages2025($index);
      // Reservas já são criadas mas serão modificadas para focar em 2025
  }
  
  // Após createOccurrences
  if ($index === 0) {
      $this->createBudget2026($index);
      $this->generateFees2026($index);
  }
  ```


### 8. Atualizar `deleteCondominiumData()` em [`database/seeders/DemoSeeder.php`](database/seeders/DemoSeeder.php)

- Adicionar limpeza de mensagens:
  ```php
  // Delete messages
  $this->db->exec("DELETE FROM messages WHERE condominium_id = {$condominiumId}");
  ```

- Adicionar limpeza de quotas de 2026:
  ```php
  // Delete fees for 2026
  $this->db->exec("DELETE FROM fees WHERE condominium_id = {$condominiumId} AND period_year = 2026");
  ```

- Adicionar limpeza de orçamento 2026:
  ```php
  // Delete budget 2026
  $stmt = $this->db->prepare("DELETE FROM budget_items WHERE budget_id IN (SELECT id FROM budgets WHERE condominium_id = :condominium_id AND year = 2026)");
  $stmt->execute([':condominium_id' => $condominiumId]);
  $this->db->exec("DELETE FROM budgets WHERE condominium_id = {$condominiumId} AND year = 2026");
  ```


### 9. Atualizar [`cli/restore-demo.php`](cli/restore-demo.php)

- Garantir que o script limpa corretamente:
  - Mensagens (já deve estar coberto por `deleteCondominiumData()`)
  - Quotas de 2026
  - Orçamento de 2026
  - Receipts relacionados com quotas de 2026
- Verificar se o condomínio padrão é restaurado corretamente após o restore

## Detalhes de Implementação

### Dívidas Históricas (2024)

- Criar quotas para fração 1A em alguns meses de 2024 (ex: 6 meses)
- Marcar como `is_historical = 1`
- Status: `pending` ou `overdue`
- Não criar pagamentos
- Usar `FeeService->generateMonthlyFees()` com ano 2024

### Mensagens 2025

- 5-8 mensagens do admin para todos (`to_user_id = NULL`)
- 3-5 mensagens privadas admin → fração específica
- 2-3 respostas (thread_id preenchido)
- Conteúdo HTML básico: `<p>`, `<strong>`, `<ul>`, `<li>`
- Datas: distribuídas ao longo de 2025
- Algumas marcadas como lidas, outras não

### Reservas 2025

- 8-12 reservas ao longo do ano
- Diferentes espaços e condóminos
- Estados variados: approved (maioria), pending, cancelled
- Datas em 2025

### Orçamento 2026

- Baseado no orçamento 2025 mas com valores atualizados (+5-10%)
- Status: `approved`
- Itens de receita e despesa similares ao 2025

### Quotas 2026

- **Regulares:** todas geradas (12 meses), primeiros 3-4 meses pagos
- **Extra obras:** €5000 total, distribuído por permilagem, primeiros 2-3 meses pagos
- Criar pagamentos e transações financeiras para as quotas pagas

## Ordem de Execução

1. Modificar `generateFees2025()` para criar dívidas distintas
2. Criar `createMessages2025()`
3. Modificar `createReservations()` para focar em 2025
4. Criar `createBudget2026()`
5. Criar `generateFees2026()`
6. Atualizar `deleteCondominiumData()` para limpar novos dados
7. Atualizar `run()` com chamadas condicionais
8. Verificar `restore-demo.php` e atualizar se necessário
9. Testar execução do seeder e restore-demo