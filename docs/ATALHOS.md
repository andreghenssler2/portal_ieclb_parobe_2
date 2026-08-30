# Favoritos e acessos recentes — v0.69.0

A v0.69.0 adiciona atalhos pessoais ao painel administrativo.

## O que muda

A barra superior ganha o botão **Atalhos**.

Ao abrir, o usuário vê:

- página atual;
- botão para favoritar/desfavoritar a página;
- favoritos da própria conta;
- páginas administrativas acessadas recentemente.

## Persistência

Os dados ficam na tabela:

```text
usuario_admin_atalhos
```

Cada usuário possui seus próprios registros.

## Histórico

Ao carregar uma página administrativa, o Portal registra:

- rota;
- título;
- quantidade de acessos;
- data/hora do último acesso.

São mantidos até 60 itens recentes não favoritos por usuário.

Favoritos não são removidos automaticamente.

## Segurança

O endpoint exige login.

Ações de gravação exigem CSRF.

Rotas absolutas, caminhos contendo `..`, login/logout e endpoints da API não
podem ser salvos como atalhos.

## Arquivos

- `app/Services/AdminShortcutService.php`
- `admin/api/atalhos.php`
- `admin/_admin_shortcuts.php`
- `public/js/admin-shortcuts-v69.js`
- `migrations/2026_08_30_v0.69.0.sql`
