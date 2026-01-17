---
name: Sistema de Testes PHPUnit
overview: Implementar uma suite completa de testes unitários e de integração usando PHPUnit para garantir a integridade do sistema quando são feitas alterações de código.
todos:
  - id: setup-phpunit
    content: Criar phpunit.xml com configuração completa (cobertura, suites, relatórios)
    status: completed
  - id: setup-bootstrap
    content: Criar tests/bootstrap.php para inicialização do ambiente de testes
    status: completed
  - id: create-structure
    content: Criar estrutura de diretórios (Unit/, Integration/, Fixtures/, Helpers/)
    status: completed
  - id: create-testcase-base
    content: Criar tests/Helpers/TestCase.php com métodos auxiliares e setup/teardown
    status: completed
    dependencies:
      - create-structure
  - id: add-composer-scripts
    content: Adicionar scripts de teste ao composer.json (test, test:unit, test:integration, test:coverage)
    status: completed
  - id: test-security
    content: Criar testes unitários para Security (sanitize, CSRF tokens)
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-auth-middleware
    content: Criar testes unitários para AuthMiddleware e RoleMiddleware
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-user-model
    content: Criar testes unitários para User model (CRUD, findByEmail, verifyPassword)
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-condominium-model
    content: Criar testes unitários para Condominium model
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-fee-service
    content: Criar testes unitários para FeeService (cálculos, geração de quotas)
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-auth-flow
    content: Criar testes de integração para fluxo completo de autenticação (login/logout)
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-profile-flow
    content: Criar testes de integração para fluxo de atualização de perfil
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: test-reservation-flow
    content: Criar testes de integração para fluxo completo de criação de reserva
    status: completed
    dependencies:
      - setup-phpunit
      - create-testcase-base
  - id: create-test-docs
    content: Criar tests/README.md com documentação sobre como escrever e executar testes
    status: completed
---

# Sistema de Testes com PHPUnit

## Objetivo

Criar uma infraestrutura de testes robusta que permita validar automaticamente a funcionalidade do sistema após alterações de código, garantindo que regressões não sejam introduzidas.

## Estrutura de Testes

### 1. Configuração Base

**Arquivo: `phpunit.xml`**

- Configuração do PHPUnit com:
  - Cobertura de código (excluindo vendor, cache, logs)
  - Bootstrap para inicialização do ambiente
  - Suítes de testes separadas (unitários vs integração)
  - Relatórios HTML e XML

**Arquivo: `tests/bootstrap.php`**

- Inicialização do ambiente de testes
- Configuração de variáveis de ambiente de teste
- Setup de base de dados de teste (se necessário)

**Estrutura de Diretórios:**

```
tests/
├── Unit/              # Testes unitários
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Middleware/
│   └── Core/
├── Integration/       # Testes de integração
│   ├── Controllers/
│   ├── Services/
│   └── Database/
├── Fixtures/          # Dados de teste
│   └── database/
└── Helpers/           # Classes auxiliares para testes
```

### 2. Testes Unitários

#### Models (`tests/Unit/Models/`)

- **UserTest.php**: Testar métodos `findById`, `findByEmail`, `create`, `update`, `verifyPassword`
- **CondominiumTest.php**: Testar CRUD e métodos de busca
- **FeeTest.php**: Testar `getFeesMapByYear`, `getAvailableYears`, cálculos de quotas
- **ReservationTest.php**: Testar `getByUser`, validações de datas
- **OccurrenceTest.php**: Testar `getByUser`, filtros

#### Services (`tests/Unit/Services/`)

- **SecurityTest.php**: Testar `sanitize`, `generateCSRFToken`, `verifyCSRFToken`
- **EmailServiceTest.php**: Testar envio de emails (mock)
- **FileStorageServiceTest.php**: Testar upload/download de ficheiros
- **NotificationServiceTest.php**: Testar criação de notificações

#### Middleware (`tests/Unit/Middleware/`)

- **AuthMiddlewareTest.php**: Testar verificação de autenticação
- **RoleMiddlewareTest.php**: Testar verificação de roles e permissões
- **DemoProtectionMiddlewareTest.php**: Testar proteção de utilizadores demo

#### Core (`tests/Unit/Core/`)

- **RouterTest.php**: Testar roteamento e parâmetros
- **ControllerTest.php**: Testar métodos base do controller
- **ModelTest.php**: Testar métodos base do model

### 3. Testes de Integração

#### Controllers (`tests/Integration/Controllers/`)

- **AuthControllerTest.php**: Fluxo completo de login/logout
- **ProfileControllerTest.php**: Fluxo de atualização de perfil
- **ReservationControllerTest.php**: Fluxo completo de criação de reserva
- **OccurrenceControllerTest.php**: Fluxo de criação e gestão de ocorrências

#### Services (`tests/Integration/Services/`)

- **FeeServiceTest.php**: Geração completa de quotas com validações
- **PaymentServiceTest.php**: Processamento completo de pagamentos
- **ReportServiceTest.php**: Geração de relatórios completos

#### Database (`tests/Integration/Database/`)

- **MigrationsTest.php**: Validar que todas as migrations funcionam
- **SeedersTest.php**: Validar seeders de dados

### 4. Helpers e Fixtures

**Arquivo: `tests/Helpers/TestCase.php`**

- Classe base para todos os testes
- Métodos auxiliares: `createUser()`, `createCondominium()`, `loginAs()`
- Setup/teardown de base de dados de teste

**Arquivo: `tests/Fixtures/database/`**

- SQL fixtures para dados de teste
- Factories para criar entidades de teste

### 5. Scripts e Comandos

**Atualizar `composer.json`:**

```json
"scripts": {
    "test": "phpunit",
    "test:unit": "phpunit --testsuite Unit",
    "test:integration": "phpunit --testsuite Integration",
    "test:coverage": "phpunit --coverage-html coverage"
}
```

### 6. Documentação

**Arquivo: `tests/README.md`**

- Como executar testes
- Como escrever novos testes
- Convenções e boas práticas
- Estrutura de testes

## Prioridades de Implementação

### Fase 1 - Infraestrutura Base

1. Criar `phpunit.xml` com configuração completa
2. Criar `tests/bootstrap.php`
3. Criar estrutura de diretórios
4. Criar `tests/Helpers/TestCase.php` base
5. Adicionar scripts ao `composer.json`

### Fase 2 - Testes Unitários Críticos

1. Security (sanitização, CSRF)
2. AuthMiddleware e RoleMiddleware
3. Models principais (User, Condominium)
4. Services críticos (FeeService, PaymentService)

### Fase 3 - Testes de Integração

1. Fluxos de autenticação
2. Fluxos de gestão de condomínios
3. Fluxos financeiros (quotas, pagamentos)
4. Fluxos de reservas e ocorrências

### Fase 4 - Cobertura Completa

1. Restantes Models
2. Restantes Controllers
3. Restantes Services
4. Validação de migrations

## Execução

**Comandos principais:**

- `composer test` - Executar todos os testes
- `composer test:unit` - Apenas testes unitários
- `composer test:integration` - Apenas testes de integração
- `composer test:coverage` - Com relatório de cobertura

## Benefícios

1. **Detecção Precoce de Bugs**: Problemas são encontrados antes de chegar à produção
2. **Confiança em Refatorações**: Permite refatorar código com segurança
3. **Documentação Viva**: Testes servem como documentação do comportamento esperado
4. **Integração Contínua**: Preparado para CI/CD futuro
5. **Qualidade de Código**: Incentiva código mais testável e bem estruturado