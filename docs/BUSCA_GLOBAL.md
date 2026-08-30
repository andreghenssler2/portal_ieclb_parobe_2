# Busca Global Administrativa — v0.68.0

A v0.68.0 adiciona uma busca rápida para o painel.

## Abrir

Use o botão **Buscar** na barra superior ou o atalho:

```text
Ctrl + K
```

No macOS, `Command + K` também funciona.

## O que pode ser encontrado

Conforme as permissões do usuário:

- notícias;
- páginas;
- eventos/cultos;
- documentos/downloads;
- biblioteca de mídia;
- usuários;
- comunidades.

## Segurança

A pesquisa respeita `Auth::can()`.

Um usuário sem permissão de usuários, por exemplo, não recebe usuários nos
resultados.

O endpoint exige login e não grava dados.

## Endpoint

```text
admin/api/busca-global.php?q=termo
```

A resposta é JSON e usa `Cache-Control: no-store`.

## Navegação pelo teclado

Depois de pesquisar:

- `↓` próximo resultado;
- `↑` resultado anterior;
- `Enter` abre o resultado;
- `Esc` fecha a janela.

## Arquivos

- `app/Services/AdminGlobalSearchService.php`
- `admin/api/busca-global.php`
- `admin/_global_search.php`
- `public/js/admin-global-search-v68.js`

O instalador também integra o botão à topbar, inclui o modal no footer e
acrescenta os estilos ao `admin-v64.css`.
