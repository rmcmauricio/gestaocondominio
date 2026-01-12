# Sistema Demo - Documentação

## Visão Geral

O sistema demo permite que utilizadores explorem todas as funcionalidades da aplicação com dados fictícios realistas. Todas as alterações feitas pelos utilizadores são automaticamente repostas através de um cronjob.

## Credenciais de Acesso

- **Email**: `demo@predio.pt`
- **Password**: `Demo@2024`
- **Role**: Admin

## Dados Demo Incluídos

### Condomínio
- **Nome**: Residencial Sol Nascente
- **Morada**: Rua das Flores, 123, 1000-001 Lisboa
- **NIF**: 500000000
- **Total de Frações**: 10

### Frações (10)
1. Fração 1A - Maria Silva (Proprietária) - 120‰
2. Fração 1B - João Santos (Proprietário) - 120‰
3. Fração 2A - Ana Costa (Arrendatária) - 150‰
4. Fração 2B - Pedro Oliveira (Proprietário) - 150‰
5. Fração 3A - Sofia Martins (Proprietária) - 180‰
6. Fração 3B - Carlos Ferreira (Proprietário) - 180‰
7. Fração 4A - Rita Alves (Arrendatária) - 200‰
8. Fração 4B - Miguel Rodrigues (Proprietário) - 200‰
9. Fração 5A - Inês Pereira (Proprietária) - 250‰
10. Fração 5B - Tiago Gomes (Proprietário) - 250‰

### Contas Bancárias (2)
1. **Conta Principal** (Banco BPI)
   - IBAN: PT50000000000000000000001
   - SWIFT: BBPIPTPL
   - Saldo inicial: €5,000

2. **Poupança** (Banco BPI)
   - IBAN: PT50000000000000000000002
   - SWIFT: BBPIPTPL
   - Saldo inicial: €10,000

### Fornecedores (5)
1. Águas de Portugal
2. EDP Comercial
3. Seguros XYZ
4. Limpeza ABC
5. Manutenção DEF

### Dados Financeiros
- **Orçamento 2025**: Aprovado com receitas e despesas
- **Quotas 2025**: 120 quotas geradas (90 pagas, 30 pendentes)
- **Despesas 2025**: 41 despesas (água, energia, limpeza, manutenção, seguro)
- **Movimentos Financeiros**: Todos os pagamentos e despesas registados

### Assembleias (2)
1. **Assembleia Geral - Aprovação de Orçamento 2025**
   - Data: 15 de Janeiro de 2025
   - Status: Fechada/Completa
   - 7 frações presentes
   - Votação realizada

2. **Assembleia Geral - Assuntos Gerais**
   - Data: 20 de Junho de 2025
   - Status: Agendada

### Espaços Comuns (3)
1. Salão de Festas
2. Piscina
3. Campo de Ténis

### Reservas (7)
- Reservas distribuídas ao longo de 2025
- Diferentes estados (aprovadas, pendentes)

### Ocorrências (8)
- Vazamentos, avarias, limpeza
- Diferentes estados (aberta, em progresso, resolvida)
- Algumas atribuídas a fornecedores

## Proteções Implementadas

### Utilizador Demo
- Não pode editar os seus próprios acessos
- Banner informativo em todas as páginas
- Mensagens claras sobre restauração automática

### Condomínio Demo
- Não pode ser eliminado
- Banner informativo quando acedido
- Todas as alterações são repostas automaticamente

## Restauração Automática

### Script de Restauração
- **Localização**: `cli/restore-demo.php`
- **Função**: Remove todas as alterações e repopula dados demo
- **Modo Dry-Run**: `php cli/restore-demo.php --dry-run`

### Cronjob
Ver documentação em `docs/CRONJOB_DEMO.md` para instruções de configuração.

## Estrutura Técnica

### Migrations
- `046_add_demo_flag_to_users.php`: Adiciona flag `is_demo` a utilizadores
- `047_add_demo_flag_to_condominiums.php`: Adiciona flag `is_demo` a condomínios

### Models
- `DemoSeeder.php`: Seeder completo com todos os dados fictícios
- `DemoProtectionMiddleware.php`: Middleware de proteção para dados demo

### Scripts CLI
- `cli/restore-demo.php`: Script de restauração automática

## Utilização

1. **Acesso**: Login com `demo@predio.pt` / `Demo@2024`
2. **Exploração**: Explore todas as funcionalidades da aplicação
3. **Alterações**: Todas as alterações serão repostas automaticamente pelo cronjob

## Notas Importantes

- ⚠️ **Todas as alterações são temporárias** - serão repostas pelo cronjob
- ⚠️ **Não edite o utilizador demo** - os acessos são protegidos
- ⚠️ **Não elimine o condomínio demo** - está protegido contra eliminação
- ✅ **Pode criar, editar e eliminar outros dados** - serão repostos na próxima execução do cronjob
