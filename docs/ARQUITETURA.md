# Arquitetura do Portal

## Visão geral

O Portal é uma aplicação PHP tradicional, sem framework full-stack.

O ponto comum de inicialização é `bootstrap.php`, que carrega:

- configuração;
- autoload do Composer;
- banco de dados;
- sessão e autenticação;
- CSRF;
- helpers;
- serviços de domínio.

## Camadas principais

### `mod/`

Infraestrutura de baixo nível.

Exemplos:

- `mod/db/Database.php`
- `mod/auth/Session.php`
- `mod/auth/Auth.php`
- `mod/security/Csrf.php`

### `app/Helpers/`

Funções compartilhadas, URLs, formatação, configurações e utilidades.

### `app/Services/`

Regras de negócio e serviços reutilizáveis.

Exemplos:

- mídia;
- categorias;
- conteúdo;
- revisões;
- workflow editorial;
- cache;
- e-mail;
- 2FA;
- analytics;
- newsletter;
- importação WordPress;
- documentos;
- tarefas agendadas.

### `admin/`

Interfaces e ações administrativas.

Arquivos comuns:

- `_header.php`
- `_footer.php`
- `_pagination.php`
- `_search.php`

Cada módulo possui suas próprias telas.

### Área pública

As páginas públicas ficam principalmente na raiz do projeto e utilizam os
serviços carregados pelo `bootstrap.php`.

`router.php` participa do roteamento quando o servidor PHP embutido é usado e
as regras do Apache são tratadas por `.htaccess`.

## Banco de dados

O projeto usa PDO com MySQL/MariaDB.

Alterações estruturais devem ser feitas por migrações/atualizadores
idempotentes sempre que possível.

## Configuração

A configuração do ambiente fica em:

`config/config.php`

Esse arquivo é local e ignorado pelo Git.

O repositório contém apenas:

`config/config.example.php`

## Dependências

O Composer está configurado para instalar em `lib/`.

Nunca versione dependências instaladas quando elas puderem ser restauradas
por `composer install`.

## Dados mutáveis

Arquivos gerados em runtime devem permanecer fora do versionamento:

- uploads;
- cache;
- backups;
- logs;
- arquivos privados;
- configuração local.

## Segurança

A autenticação usa sessão, CSRF, permissões, limite de tentativas e 2FA.

Operações administrativas devem sempre:

1. exigir login;
2. exigir a permissão correta;
3. validar CSRF em ações mutáveis;
4. registrar auditoria quando relevante.
