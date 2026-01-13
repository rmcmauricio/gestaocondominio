# Guia de Testes

Este documento explica como executar e escrever testes para o sistema de gestão de condomínios.

## Estrutura de Testes

Os testes estão organizados em duas categorias principais:

- **Testes Unitários** (`tests/Unit/`): Testam componentes isolados (models, services, middleware, core)
- **Testes de Integração** (`tests/Integration/`): Testam fluxos completos entre múltiplos componentes

## Executar Testes

### Executar Todos os Testes

```bash
composer test
```

ou

```bash
vendor/bin/phpunit
```

### Executar Apenas Testes Unitários

```bash
composer test:unit
```

ou

```bash
vendor/bin/phpunit --testsuite Unit
```

### Executar Apenas Testes de Integração

```bash
composer test:integration
```

ou

```bash
vendor/bin/phpunit --testsuite Integration
```

### Executar com Relatório de Cobertura

```bash
composer test:coverage
```

O relatório HTML será gerado em `coverage/html/index.html`.

## Escrever Novos Testes

### Estrutura de um Teste

Todos os testes devem estender `Tests\Helpers\TestCase`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\Helpers\TestCase;

class UserTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Setup específico do teste
    }

    public function testMethodName(): void
    {
        // Arrange
        $user = $this->createUser(['email' => 'test@example.com']);

        // Act
        $result = $userModel->findByEmail('test@example.com');

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('test@example.com', $result['email']);
    }
}
```

### Convenções de Nomenclatura

- Classes de teste: `[ClassName]Test.php`
- Métodos de teste: `test[MethodName]()` ou usar anotação `#[Test]`
- Um teste por comportamento/método

### Métodos Auxiliares Disponíveis

A classe `TestCase` fornece os seguintes métodos auxiliares:

- `createUser(array $attributes = []): array` - Cria um utilizador de teste
- `createCondominium(array $attributes = []): array` - Cria um condomínio de teste
- `createFraction(int $condominiumId, array $attributes = []): array` - Cria uma fração de teste
- `loginAs(array $user): void` - Simula login de um utilizador
- `logout(): void` - Limpa a sessão
- `assertAuthenticated(): void` - Verifica se o utilizador está autenticado
- `assertGuest(): void` - Verifica se o utilizador não está autenticado
- `assertUserRole(string $role): void` - Verifica o role do utilizador
- `cleanUpDatabase(): void` - Limpa dados de teste da base de dados

### Testes Unitários

Testes unitários devem testar um único componente isoladamente:

```php
public function testFindByEmailReturnsUserWhenExists(): void
{
    if (!$this->db) {
        $this->markTestSkipped('Database not available');
    }

    $userData = $this->createUser(['email' => 'test@example.com']);
    $result = $this->userModel->findByEmail('test@example.com');

    $this->assertIsArray($result);
    $this->assertEquals('test@example.com', $result['email']);

    $this->cleanUpDatabase();
}
```

### Testes de Integração

Testes de integração testam fluxos completos:

```php
public function testLoginFlowWithValidCredentials(): void
{
    if (!$this->db) {
        $this->markTestSkipped('Database not available');
    }

    // Create user
    $userData = $this->createUser([
        'email' => 'login@example.com',
        'password' => 'password123'
    ]);

    // Simulate login
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['email'] = 'login@example.com';
    $_POST['password'] = 'password123';

    // Verify password
    $this->assertTrue($this->userModel->verifyPassword('login@example.com', 'password123'));

    $this->cleanUpDatabase();
}
```

## Boas Práticas

1. **Um teste, uma responsabilidade**: Cada teste deve verificar apenas um comportamento
2. **Nomes descritivos**: Nomes de testes devem descrever claramente o que está sendo testado
3. **Arrange-Act-Assert**: Organize testes em três fases claras
4. **Limpeza**: Sempre limpe dados de teste após cada teste
5. **Isolamento**: Testes não devem depender uns dos outros
6. **Skip quando necessário**: Use `markTestSkipped()` quando dependências não estão disponíveis

## Assertions Comuns

- `$this->assertEquals($expected, $actual)` - Verifica igualdade
- `$this->assertTrue($condition)` - Verifica que é verdadeiro
- `$this->assertFalse($condition)` - Verifica que é falso
- `$this->assertNull($value)` - Verifica que é null
- `$this->assertNotNull($value)` - Verifica que não é null
- `$this->assertIsArray($value)` - Verifica que é array
- `$this->assertIsString($value)` - Verifica que é string
- `$this->assertIsInt($value)` - Verifica que é inteiro
- `$this->assertArrayHasKey($key, $array)` - Verifica que array tem chave
- `$this->assertStringContainsString($needle, $haystack)` - Verifica que string contém substring
- `$this->assertEqualsWithDelta($expected, $actual, $delta)` - Comparação com tolerância para floats

## Configuração

### Base de Dados de Teste

Os testes podem usar uma base de dados de teste separada. Configure no `phpunit.xml`:

```xml
<env name="DB_NAME" value="predio_test" force="false"/>
```

### Variáveis de Ambiente

Variáveis de ambiente de teste são definidas no `phpunit.xml` e podem ser sobrescritas:

- `APP_ENV=testing`
- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`

## Troubleshooting

### Testes falham com erro de sessão

Certifique-se de que `session_start()` é chamado no `setUp()` quando necessário.

### Testes falham com erro de base de dados

- Verifique se a base de dados de teste existe
- Verifique as credenciais no `phpunit.xml`
- Use `markTestSkipped()` se a base de dados não estiver disponível

### Testes não encontram classes

Execute `composer dump-autoload` para regenerar o autoloader.

## Exemplos de Testes

Veja os testes existentes em `tests/Unit/` e `tests/Integration/` para exemplos completos de como escrever testes para diferentes componentes do sistema.
