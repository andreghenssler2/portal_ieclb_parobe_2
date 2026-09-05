# v0.86.0 — Busca Administrativa Avançada

A v0.86 complementa a busca rápida `Ctrl+K`.

## Busca rápida

Continua disponível normalmente pelo botão **Buscar** ou por:

```text
Ctrl + K
```

Ela é indicada para localizar rapidamente um item conhecido.

## Busca avançada

Nova tela:

```text
Admin → Busca Avançada
```

ou pelo link **Busca avançada** no rodapé da janela `Ctrl+K`.

Filtros:

- texto livre;
- módulo;
- status;
- data inicial;
- data final;
- pesquisar somente no título/nome;
- ordenação por mais recentes, mais antigos ou título;
- quantidade máxima por módulo.

## Módulos

A pesquisa inclui, conforme as permissões:

- Notícias;
- Páginas;
- Eventos;
- Documentos;
- Biblioteca de Mídia;
- Usuários;
- Comunidades.

## Conteúdo pesquisado

Quando “somente título” está desmarcado, a busca também consulta campos úteis
do módulo, por exemplo:

- resumo;
- conteúdo;
- slug;
- local/tipo de evento;
- descrição de documento;
- nome original da mídia;
- texto alternativo;
- e-mail e perfil de usuário.

## Permissões

O serviço monta a lista de módulos usando `Auth::can()`.

Um usuário não recebe resultado de módulo ao qual não possui acesso.

## Compatibilidade

Cada módulo é consultado isoladamente. Uma diferença de banco/tabela em um
módulo não derruba a pesquisa dos demais.

A v0.86 não cria tabela nova no banco.
