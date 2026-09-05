# v0.84.0 — Autosave e Recuperação de Rascunhos

A v0.84 adiciona autosave aos editores de:

- Posts / Notícias;
- Páginas.

## Como funciona

Ao editar um conteúdo, o Portal monitora as alterações.

Depois de uma alteração:

- há um debounce de aproximadamente 8 segundos;
- existe também uma verificação periódica a cada 30 segundos;
- somente alterações diferentes do último autosave são enviadas.

O status aparece na barra superior do editor, por exemplo:

```text
Autosave ativo
Alterações não salvas
Salvando rascunho...
Salvo automaticamente às 08:42
```

## Recuperação

Se o navegador fechar, a conexão cair ou o usuário sair sem salvar, na próxima
vez que abrir o mesmo conteúdo aparece:

```text
Rascunho automático encontrado
[Recuperar rascunho] [Descartar]
```

Ao recuperar, as alterações voltam para o formulário, mas ainda não substituem
o conteúdo definitivo. O usuário precisa clicar em **Salvar/Atualizar**.

Isso evita que um autosave publique ou sobrescreva conteúdo por conta própria.

## Conteúdo preservado

O autosave inclui:

- título;
- conteúdo do TinyMCE;
- resumo;
- slug;
- SEO;
- status;
- data de publicação;
- categorias;
- tags;
- opções/checkboxes;
- Página superior;
- ordem/menu;
- JSON dos blocos de conteúdo.

A v0.84 também adiciona uma API de restauração ao `ContentBlockEditor`, para
que os blocos voltem visualmente ao recuperar o rascunho.

## O que não é salvo automaticamente

Uploads binários não fazem parte do autosave:

- arquivo escolhido no campo upload;
- preview/ID da imagem destacada.

A imagem destacada continua sendo salva pelo fluxo normal do editor.

## Após salvar normalmente

Quando uma Notícia/Post ou Página é salva com sucesso, o rascunho automático
correspondente é removido.

Se houver erro de validação no formulário, o rascunho permanece disponível.

## Novos conteúdos

Para conteúdos ainda não salvos existe um rascunho por:

```text
usuário + tipo
```

Ou seja:

- um rascunho de nova Notícia;
- um rascunho de nova Página;

por usuário.

## Segurança

- endpoint exige usuário autenticado;
- exige permissão do módulo;
- exige CSRF;
- autosave é individual por usuário;
- limite de 4 MB por snapshot;
- dados antigos são limpos probabilisticamente após 30 dias.

## Banco

Nova tabela:

```text
content_autosaves
```
