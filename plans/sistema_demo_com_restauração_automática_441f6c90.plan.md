---
name: Sistema Demo com Restauração Automática
overview: Implementar conta demo com dados fictícios completos (10 frações, 2 contas bancárias, quotas, despesas, fornecedores, assembleias, reservas, ocorrências) e sistema de restauração automática via cronjob que repõe os dados removendo todas as alterações dos utilizadores.
todos:
  - id: demo-1
    content: Criar migration 046 para adicionar flag is_demo a users e criar utilizador demo
    status: completed
  - id: demo-2
    content: Criar migration 047 para adicionar flag is_demo a condominiums
    status: completed
  - id: demo-3
    content: Criar DemoSeeder completo com todos os dados fictícios (condomínio, frações, utilizadores, contas bancárias)
    status: completed
    dependencies:
      - demo-1
      - demo-2
  - id: demo-4
    content: Popular movimentos financeiros, quotas 2025, orçamento e despesas no DemoSeeder
    status: completed
    dependencies:
      - demo-3
  - id: demo-5
    content: Popular fornecedores, assembleias, reservas e ocorrências no DemoSeeder
    status: completed
    dependencies:
      - demo-3
  - id: demo-6
    content: Criar script cli/restore-demo.php para restaurar dados demo
    status: completed
    dependencies:
      - demo-3
  - id: demo-7
    content: Adicionar proteções e validações para impedir edição de utilizador demo
    status: completed
    dependencies:
      - demo-1
  - id: demo-8
    content: Adicionar banner informativo em todas as páginas quando em modo demo
    status: completed
    dependencies:
      - demo-1
  - id: demo-9
    content: Configurar cronjob e documentar configuração
    status: completed
    dependencies:
      - demo-6
---

# Sistema Demo com Restauração Automática

## Objetivo

Criar uma conta demo completa com dados fictícios realistas que permita aos utilizadores explorar todas as funcionalidades da aplicação. Implementar um sistema de restauração automática que repõe os dados periodicamente, removendo todas as alterações feitas pelos utilizadores.

## Arquitetura

### Componentes Principais

1. **Utilizador Demo**: Conta especial com flag `is_demo` que não pode ter acessos editados
2. **Seeder Demo**: Script que popula dados fictícios completos
3. **Restaurador Demo**: Script CLI que restaura dados demo removendo alterações
4. **Cronjob**: Tarefa agendada que executa o restaurador periodicamente

## Estrutura de Dados

### Migration: Adicionar flag demo

**046_add_demo_flag_to_users.php**

- Adicionar coluna `is_demo BOOLEAN DEFAULT FALSE` na tabela `users`
- Adicionar índice em `is_demo`
- Criar utilizador demo: `demo@predio.pt` / `Demo@2024`

**047_add_demo_flag_to_condominiums.php**

- Adicionar coluna `is_demo BOOLEAN DEFAULT FALSE` na tabela `condominiums`
- Adicionar índice em `is_demo`
- Permitir identificar condomínios demo para restauração

## Implementação

### Fase 1: Estrutura Base

**Migration 046: Adicionar flag demo a users**

- Adicionar coluna `is_demo`
- Criar utilizador demo com role `admin`
- Password: `Demo@2024`

**Migration 047: Adicionar flag demo a condominiums**

- Adicionar coluna `is_demo`
- Marcar condomínios demo

### Fase 2: Seeder Demo

**database/seeders/DemoSeeder.php**

Criar seeder completo que popula:

1. **Condomínio Demo**

- Nome: "Residencial Sol Nascente"
- Morada fictícia completa
- Associado ao utilizador demo

2. **10 Frações** com nomes fictícios:

- Fração 1A - Maria Silva
- Fração 1B - João Santos
- Fração 2A - Ana Costa
- Fração 2B - Pedro Oliveira
- Fração 3A - Sofia Martins
- Fração 3B - Carlos Ferreira
- Fração 4A - Rita Alves
- Fração 4B - Miguel Rodrigues
- Fração 5A - Inês Pereira
- Fração 5B - Tiago Gomes
- Cada fração com permilagem diferente (100‰ a 1000‰)

3. **Utilizadores Condóminos** (10 utilizadores)

- Criar utilizadores para cada fração
- Associar às frações via `condominium_users`
- Alguns como proprietários, outros como arrendatários

4. **2 Contas Bancárias**

- Conta à Ordem: Banco BPI, IBAN fictício, saldo inicial €5000
- Conta Poupança: Banco BPI, IBAN fictício, saldo inicial €10000

5. **Movimentos Financeiros** (2024-2025)

- Entradas: Pagamentos de quotas, receitas diversas
- Saídas: Despesas mensais (água, luz, seguro, limpeza, manutenção)
- Distribuídos ao longo de 2024-2025

6. **Quotas 2025** (completas)

- Gerar quotas mensais para todas as frações
- Maioria pagas (70-80%)
- Algumas pendentes e em atraso
- Pagamentos associados a movimentos financeiros

7. **Orçamento 2025**

- Orçamento completo com receitas e despesas
- Itens categorizados (Água, Luz, Seguro, Limpeza, Manutenção, etc.)

8. **Despesas 2025**

- Despesas mensais de água (€150-€200)
- Despesas mensais de luz (€200-€300)
- Seguro anual (€600)
- Limpeza mensal (€300)
- Manutenção variada (€100-€500)
- Distribuídas ao longo do ano

9. **Fornecedores** (5-6 fornecedores)

- Empresa de Águas e Saneamento
- EDP - Energia
- Seguradora XYZ
- Empresa de Limpeza ABC
- Empresa de Manutenção DEF
- Com NIFs, contactos, contratos

10. **2 Assembleias**

 - Assembleia 1: Janeiro 2025 - Aprovação de orçamento (fechada, com ata)
 - Assembleia 2: Junho 2025 - Assuntos gerais (agendada)
 - Com presenças, votos, atas

11. **Reservas de Espaços** (5-10 reservas)

 - Reservas de salão de festas
 - Reservas de piscina
 - Reservas de campo de ténis
 - Algumas aprovadas, outras pendentes
 - Distribuídas ao longo de 2025

12. **Ocorrências** (8-10 ocorrências)

 - Vazamentos, avarias, limpeza
 - Diferentes estados (aberta, em progresso, resolvida)
 - Algumas atribuídas a fornecedores
 - Com comentários e histórico

### Fase 3: Restaurador Demo

**cli/restore-demo.php**

Script CLI que:

1. Identifica condomínios demo (`is_demo = TRUE`)
2. Remove todas as alterações:

- Elimina movimentos financeiros criados após seed
- Elimina quotas/pagamentos criados após seed
- Elimina despesas criadas após seed
- Elimina reservas criadas após seed
- Elimina ocorrências criadas após seed
- Elimina assembleias criadas após seed
- Elimina documentos criados após seed
- Restaura saldos das contas bancárias

3. Repõe dados originais:

- Executa `DemoSeeder` novamente
- Mantém IDs originais quando possível

4. Log de operações

### Fase 4: Proteções

**Middleware/Validação**

- Impedir edição de utilizador demo (`is_demo = TRUE`)
- Impedir eliminação de condomínio demo
- Avisos visuais nas páginas quando em modo demo
- Banner informativo sobre restauração automática

**Controllers**

- Adicionar verificações em métodos críticos
- Bloquear operações destrutivas em dados demo
- Mensagens informativas

### Fase 5: Cronjob

**Configuração Cronjob**

- Executar `php cli/restore-demo.php` diariamente às 3h00
- Ou a cada X horas conforme necessário
- Log de execução

**Documentação**

- Instruções para configurar cronjob
- Exemplo de configuração crontab

## Dados Fictícios Detalhados

### Frações e Condóminos

1. **Fração 1A** - Maria Silva (Proprietária)

- Permilagem: 120‰
- Email: maria.silva@email.com
- Telefone: +351 912 345 678

2. **Fração 1B** - João Santos (Proprietário)

- Permilagem: 120‰
- Email: joao.santos@email.com
- Telefone: +351 912 345 679

3. **Fração 2A** - Ana Costa (Arrendatária)

- Permilagem: 150‰
- Email: ana.costa@email.com
- Telefone: +351 912 345 680

4. **Fração 2B** - Pedro Oliveira (Proprietário)

- Permilagem: 150‰
- Email: pedro.oliveira@email.com
- Telefone: +351 912 345 681

5. **Fração 3A** - Sofia Martins (Proprietária)

- Permilagem: 180‰
- Email: sofia.martins@email.com
- Telefone: +351 912 345 682

6. **Fração 3B** - Carlos Ferreira (Proprietário)

- Permilagem: 180‰
- Email: carlos.ferreira@email.com
- Telefone: +351 912 345 683

7. **Fração 4A** - Rita Alves (Arrendatária)

- Permilagem: 200‰
- Email: rita.alves@email.com
- Telefone: +351 912 345 684

8. **Fração 4B** - Miguel Rodrigues (Proprietário)

- Permilagem: 200‰
- Email: miguel.rodrigues@email.com
- Telefone: +351 912 345 685

9. **Fração 5A** - Inês Pereira (Proprietária)

- Permilagem: 250‰
- Email: ines.pereira@email.com
- Telefone: +351 912 345 686

10. **Fração 5B** - Tiago Gomes (Proprietário)

 - Permilagem: 250‰
 - Email: tiago.gomes@email.com
 - Telefone: +351 912 345 687

### Contas Bancárias

1. **Conta à Ordem**

- Nome: "Conta Principal"
- Banco: Banco BPI
- IBAN: PT50 0000 0000 0000 0000 0000 1
- SWIFT: BBPIPTPL
- Saldo inicial: €5000

2. **Conta Poupança**

- Nome: "Poupança"
- Banco: Banco BPI
- IBAN: PT50 0000 0000 0000 0000 0000 2
- SWIFT: BBPIPTPL
- Saldo inicial: €10000

### Fornecedores

1. **Águas de Portugal**

- NIF: 500000000
- Contacto: geral@aguas.pt
- Telefone: +351 800 200 002

2. **EDP Comercial**

- NIF: 500000001
- Contacto: comercial@edp.pt
- Telefone: +351 800 100 100

3. **Seguros XYZ**

- NIF: 500000002
- Contacto: geral@segurosxyz.pt
- Telefone: +351 213 456 789

4. **Limpeza ABC**

- NIF: 500000003
- Contacto: geral@limpezabc.pt
- Telefone: +351 912 000 001

5. **Manutenção DEF**

- NIF: 500000004
- Contacto: geral@manutencao.pt
- Telefone: +351 912 000 002

## Considerações Técnicas

- **IDs Fixos**: Usar IDs específicos para dados demo (ex: condomínio demo sempre ID 999)
- **Timestamps**: Usar timestamps fixos para dados históricos
- **Integridade**: Garantir integridade referencial ao restaurar
- **Performance**: Otimizar queries de restauração
- **Logs**: Registrar todas as operações de restauração
- **Segurança**: Validar que apenas dados demo são restaurados

## Fluxo de Trabalho

1. **Setup Inicial**: Executar migrations e seeder demo
2. **Utilização**: Utilizadores exploram e modificam dados demo
3. **Restauração**: Cronjob executa restaurador periodicamente
4. **Reposição**: Dados são restaurados ao estado original

## Validações e Proteções

- Verificar `is_demo` antes de permitir edições críticas
- Banner informativo em todas as páginas quando em modo demo
- Mensagens claras sobre restauração automática
- Bloquear eliminação de dados demo essenciais
- Permitir apenas visualização de alguns dados demo críticos