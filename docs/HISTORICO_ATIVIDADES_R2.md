# v0.87.0 R2 — Correção do Histórico de Atividades

## Sintoma

Ao clicar em:

```text
Auditoria → Atividade por usuário
```

a tela mostra:

```text
O histórico de atividades ainda não está disponível.
```

Isso significa que `admin/usuarios/atividade.php` foi publicado, porém a classe
`UserActivityService` não estava disponível naquela requisição.

## R2

A R2:

1. garante `app/Services/UserActivityService.php`;
2. instala uma cópia de segurança exclusiva:
   `app/Services/UserActivityServiceV87R2.php`;
3. atualiza `admin/usuarios/atividade.php` para tentar os dois arquivos;
4. garante o carregamento condicional no `bootstrap.php`;
5. não cria tabela;
6. mantém `APP_VERSION` em `0.87.0`;
7. cria backup antes de alterar arquivos existentes.

Assim, mesmo se o bootstrap de uma publicação parcial estiver diferente, a
própria tela de Atividade consegue carregar o serviço.
