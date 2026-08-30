# Portal IECLB Parobé v0.65.0

## Testes automáticos e GitHub Actions

A v0.65.0 cria a primeira camada automática de qualidade do projeto.

### Novo teste local

`tests/run.php`

Não depende do banco e valida:

- PHP >= 8.2;
- estrutura essencial;
- sintaxe PHP;
- composer.json;
- includes estáticos do bootstrap;
- conflitos Git;
- CSS consolidado da v0.64;
- resíduos do header corrigidos na v0.64 R2;
- assinaturas de serviços críticos;
- config.example.php sem senha preenchida.

### GitHub Actions

Novo workflow:

`.github/workflows/quality.yml`

Executado automaticamente em `push` e `pull_request` para `main`.

Matriz:

- PHP 8.2
- PHP 8.3

### Documentação

Novo:

`TESTES.md`

### Banco

Nenhuma alteração de banco.
