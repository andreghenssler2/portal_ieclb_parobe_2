# Portal IECLB Parobé

CMS próprio em PHP + MySQL/MariaDB para gerenciamento do portal da IECLB Parobé.

**Versão atual: 0.66.0**

## Principais recursos

O Portal possui painel administrativo inspirado em fluxos de CMS, com:

- notícias e categorias hierárquicas;
- páginas institucionais;
- eventos e agenda;
- biblioteca de mídia;
- documentos e downloads;
- comentários;
- formulários e respostas;
- newsletter;
- menus hierárquicos;
- temas, personalização e widgets;
- blocos e padrões de conteúdo;
- SEO, sitemap e Open Graph;
- busca;
- analytics e notícias mais lidas;
- importação de conteúdo do WordPress;
- usuários, perfis e permissões;
- auditoria administrativa;
- cache;
- backups;
- tarefas agendadas;
- modo manutenção;
- autenticação em dois fatores (TOTP);
- fluxo editorial de revisão/aprovação;
- Central de Pendências;
- Central de Diagnóstico;
- testes automáticos e GitHub Actions.

## Requisitos

- PHP 8.2 ou superior;
- MySQL 8+ ou MariaDB compatível;
- PDO MySQL;
- Fileinfo;
- Apache com `mod_rewrite`;
- Composer 2 para restaurar dependências.

A versão de PHP suportada oficialmente pelo CI é testada em PHP 8.2 e 8.3.

## Instalação

### 1. Copie o projeto

Exemplo para XAMPP:

```text
C:\xampp\htdocs\portal_ieclb_parobe
```

### 2. Instale as dependências

Na raiz:

```bash
composer install --no-dev --optimize-autoloader
```

O projeto usa `lib/` como diretório de dependências Composer.

### 3. Crie a configuração local

Copie:

```text
config/config.example.php
```

para:

```text
config/config.php
```

Depois ajuste URL, banco, ambiente e demais valores da instalação.

`config/config.php` não deve ser enviado ao Git.

### 4. Banco de dados

Em uma instalação nova, importe o SQL base disponível no projeto e depois
aplique as migrações necessárias para a versão atual.

Em uma instalação existente, use sempre os atualizadores/migrações de versão
em ordem, mantendo backup do banco e dos arquivos.

### 5. Permissões

Garanta escrita para o processo do PHP nas pastas usadas pelo Portal, em
especial `uploads/` e `storage/`.

## Segurança

Para produção:

- use `APP_ENV=production`;
- desative `APP_DEBUG`;
- nunca versione `config/config.php`;
- use senha forte para todos os usuários;
- ative autenticação em dois fatores para contas administrativas;
- configure limite de tentativas de login;
- mantenha backups recentes;
- revise periodicamente Auditoria e Central de Diagnóstico.

Credenciais administrativas não são documentadas no repositório. Em uma
instalação nova, crie ou redefina a conta administrativa por um procedimento
seguro antes de publicar o Portal.

## Atualizações

Consulte:

- [`docs/ATUALIZACAO.md`](docs/ATUALIZACAO.md)
- [`CHANGELOG.md`](CHANGELOG.md)

Regra principal: **backup primeiro, atualização depois**.

## Testes

Antes de commit/deploy:

```bash
php tests/run.php
```

O GitHub também executa automaticamente o workflow:

```text
Portal PHP Quality
```

em `push` e `pull_request` para `main`.

Mais informações:

- [`TESTES.md`](TESTES.md)

## Estrutura resumida

```text
admin/                 painel administrativo
app/Helpers/           funções auxiliares
app/Services/          serviços de domínio
config/                configuração de exemplo e proteção
docs/                  documentação do projeto
migrations/            migrações incrementais
mod/                    infraestrutura de banco, sessão e segurança
public/                 CSS, JS e recursos públicos
storage/                dados locais, cache, backups e arquivos privados
tests/                  verificações automáticas
uploads/                mídia enviada pelo painel
```

Detalhes:

- [`docs/ARQUITETURA.md`](docs/ARQUITETURA.md)
- [`docs/REPOSITORIO.md`](docs/REPOSITORIO.md)

## Histórico

O histórico consolidado fica em [`CHANGELOG.md`](CHANGELOG.md).

Notas históricas de versões antigas podem ser mantidas em `docs/releases/`.

## Licença

Projeto de uso próprio da IECLB Parobé. Consulte `composer.json` e as regras
internas do projeto para distribuição e implantação.
