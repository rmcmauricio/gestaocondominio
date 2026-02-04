---
name: Completar Sistema de Assembleias e Votações
overview: "Completar e melhorar o sistema de assembleias e votações com funcionalidades avançadas: sistema de tópicos de votação, votações ponderadas por permilagem, melhorias na geração de PDFs, convocatórias automáticas melhoradas, geração de atas completa e visualização de resultados em tempo real."
todos:
  - id: vote-topics-system
    content: Implementar sistema de tópicos de votação (migration, model, controller, views)
    status: completed
  - id: weighted-voting
    content: Implementar votações ponderadas por permilagem com cálculo automático
    status: completed
    dependencies:
      - vote-topics-system
  - id: real-time-results
    content: Adicionar visualização de resultados em tempo real com gráficos
    status: completed
    dependencies:
      - weighted-voting
  - id: pdf-improvements
    content: Melhorar geração de PDFs de convocatórias e atas
    status: completed
  - id: auto-convocations
    content: Melhorar sistema de convocatórias automáticas com agendamento
    status: completed
  - id: assembly-management
    content: Adicionar funcionalidades de gestão (editar, iniciar/fechar assembleias)
    status: completed
  - id: complete-minutes
    content: Completar geração de atas com todos os detalhes e resultados
    status: completed
    dependencies:
      - weighted-voting
      - pdf-improvements
---

# Completar Sistema de Assembleias e Votações

## Objetivo

Completar e melhorar o sistema de assembleias e votações com funcionalidades avançadas, incluindo sistema de tópicos de votação, votações ponderadas por permilagem, melhorias na geração de PDFs, convocatórias automáticas melhoradas, geração de atas completa e visualização de resultados em tempo real.

## Funcionalidades a Implementar

### 1. Sistema de Tópicos de Votação

Criar estrutura para tópicos de votação nas assembleias:

- **Arquivo**: Criar migration `036_create_assembly_vote_topics_table.php`
- **Arquivo**: Criar `app/Models/VoteTopic.php`
- Tabela `assembly_vote_topics` para armazenar tópicos de votação
- CRUD completo de tópicos
- Múltiplas opções de votação por tópico
- Ordem de votação dos tópicos

### 2. Votações Ponderadas por Permilagem

Melhorar sistema de votações para usar permilagem:

- **Arquivo**: `app/Models/Vote.php` (atualizar método `create` para calcular weighted_value)
- **Arquivo**: `app/Models/Vote.php` (melhorar método `calculateResults`)
- Calcular valor ponderado baseado na permilagem da fração
- Resultados mostrando votos por permilagem e por número de votos
- Validação de quórum baseado em permilagem

### 3. Melhorias na Geração de PDFs

Melhorar geração de convocatórias e atas:

- **Arquivo**: `app/Services/PdfService.php`
- Convocatórias mais profissionais com logo e formatação melhorada
- Atas completas com todos os detalhes da assembleia
- Inclusão de resultados de votações nas atas
- Formatação adequada para impressão

### 4. Convocatórias Automáticas Melhoradas

Melhorar sistema de envio de convocatórias:

- **Arquivo**: `app/Controllers/AssemblyController.php`
- Envio automático X dias antes da assembleia (configurável)
- Template de email melhorado
- Anexo PDF da convocatória
- Lembrete automático antes da assembleia

### 5. Geração de Atas Completa

Melhorar geração de atas com todos os detalhes:

- **Arquivo**: `app/Services/PdfService.php`
- Incluir lista completa de presentes com permilagem
- Incluir todos os tópicos de votação e resultados
- Incluir decisões tomadas
- Formatação profissional para arquivo

### 6. Visualização de Resultados em Tempo Real

Interface para visualizar resultados de votações:

- **Arquivo**: `app/Views/pages/assemblies/show.html.twig`
- Mostrar tópicos de votação na página da assembleia
- Formulário para votar em cada tópico
- Visualização de resultados em tempo real
- Gráficos de resultados (Chart.js)

### 7. Gestão de Assembleias

Melhorar gestão de assembleias:

- **Arquivo**: `app/Controllers/AssemblyController.php`
- Editar assembleias antes de iniciar
- Iniciar/fechar assembleias
- Adicionar/editar tópicos de votação
- Cancelar assembleias

## Arquivos a Criar/Modificar

### Novos Arquivos

- `database/migrations/036_create_assembly_vote_topics_table.php` - Tabela de tópicos de votação
- `app/Models/VoteTopic.php` - Model para tópicos de votação
- `app/Views/pages/assemblies/vote-topics.html.twig` - Gestão de tópicos (pode ser incluída em show.html.twig)

### Arquivos a Modificar

- `app/Controllers/AssemblyController.php` - Adicionar métodos para editar, iniciar/fechar, melhorar convocatórias
- `app/Controllers/VoteController.php` - Melhorar para usar tópicos corretamente
- `app/Models/Vote.php` - Atualizar para calcular weighted_value e melhorar resultados
- `app/Models/Assembly.php` - Adicionar métodos auxiliares
- `app/Services/PdfService.php` - Melhorar templates de PDFs
- `app/Views/pages/assemblies/show.html.twig` - Adicionar seção de tópicos de votação e resultados
- `app/Views/pages/assemblies/index.html.twig` - Melhorar listagem
- `app/Views/pages/assemblies/create.html.twig` - Melhorar formulário de criação
- `routes.php` - Adicionar rotas para tópicos e gestão de assembleias

## Prioridades

1. **Alta**: Sistema de tópicos de votação
2. **Alta**: Votações ponderadas por permilagem
3. **Alta**: Visualização de resultados em tempo real
4. **Média**: Melhorias na geração de PDFs
5. **Média**: Convocatórias automáticas melhoradas
6. **Média**: Gestão de assembleias (editar, iniciar/fechar)
7. **Baixa**: Geração de atas completa

## Notas Técnicas

- A tabela `assembly_votes` atual tem estrutura diferente do esperado, precisa criar migration para ajustar ou criar nova estrutura
- Os tópicos de votação devem ter múltiplas opções (sim/não/abstenção ou opções customizadas)
- As votações devem calcular automaticamente o valor ponderado baseado na permilagem
- Os PDFs podem usar biblioteca como TCPDF ou DomPDF para geração real de PDF (atualmente gera HTML)
- As convocatórias automáticas podem usar CLI command para envio agendado
- Os resultados devem mostrar tanto votos por número quanto por permilagem
- O quórum deve ser calculado considerando a permilagem dos presentes