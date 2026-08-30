# Testes e Qualidade — Portal IECLB Parobé

A partir da v0.65.0 o projeto possui uma suíte de qualidade que não depende
de banco de dados e pode ser executada antes de cada atualização/deploy.

## Executar localmente

Na raiz do Portal:

```bash
php tests/run.php
```

O comando retorna:

- `exit 0` quando os testes passam;
- `exit 1` quando existe uma falha que deve impedir o deploy.

## O que é verificado

A suíte valida:

- PHP 8.2 ou superior;
- arquivos essenciais do Portal;
- sintaxe dos arquivos PHP sem executá-los;
- validade do `composer.json`;
- arquivos estáticos exigidos pelo `bootstrap.php`;
- marcadores de conflito do Git;
- integridade básica do `admin-v64.css`;
- carregamento único do CSS consolidado;
- ausência dos antigos CSS no `<head>`;
- ausência do resíduo `">` corrigido pela v0.64 R2;
- assinaturas dos serviços críticos:
  - permalinks;
  - workflow editorial;
  - autenticação em dois fatores;
  - Central de Pendências;
- segurança básica do `config.example.php`.

## GitHub Actions

Também é criado:

`.github/workflows/quality.yml`

A cada `push` ou `pull_request` para `main`, o GitHub testa o Portal em:

- PHP 8.2
- PHP 8.3

Não é necessário banco de dados para essa verificação.

## Por que os testes não carregam bootstrap.php?

`bootstrap.php` tenta conectar ao banco e iniciar os serviços do Portal.

No GitHub Actions não existem as credenciais do servidor de produção. Por
isso a suíte faz verificações estáticas e sintáticas, sem executar o runtime.

Isso permite detectar grande parte dos erros mais comuns antes do deploy sem
expor nenhuma credencial.

## Fluxo recomendado

Antes de enviar uma versão para o Git:

```bash
php tests/run.php
git status
git add .
git commit -m "Portal v0.65.0"
git push
```

Depois do `git push`, confira se o workflow **Portal PHP Quality** ficou verde.
