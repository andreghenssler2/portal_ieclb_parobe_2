# v0.88.0 — Performance e Cache por Conteúdo

A v0.88 amplia o cache público existente.

Antes, o cache de página inteira era aplicado somente à Home pública.

Agora Notícias e Páginas individuais também podem ter um cache próprio.

## Fluxo

O conteúdo ainda é localizado no banco normalmente.

Depois disso:

- a Notícia registra a visualização no `NewsAnalyticsService`;
- o Portal verifica o cache específico daquele conteúdo;
- em HIT, entrega o HTML já pronto;
- em MISS, renderiza normalmente e grava o HTML para o próximo visitante.

Isso reduz consultas de categorias, blocos, hierarquia, conteúdos relacionados,
menus, tema e renderização repetida.

## Segurança

O cache por conteúdo só funciona quando:

- o visitante não está autenticado;
- a requisição é `GET`;
- não há query string;
- o cache geral e o cache de páginas estão ativos;
- o conteúdo não possui formulário incorporado;
- o HTML bruto não contém `<form>` ou sinais de CSRF.

Blocos `portal_form_embed` são automaticamente excluídos do cache de página.

Assim tokens CSRF, honeypots e formulários não são compartilhados entre
visitantes.

## Notícias e analytics

O `NewsAnalyticsService::trackView()` continua sendo executado antes da
consulta ao cache da Notícia.

Portanto, um HIT de cache não elimina a contagem de visualização.

## ETag

Notícias e Páginas cacheadas recebem:

```text
ETag
Last-Modified
Cache-Control: public, max-age=0, must-revalidate
```

Se o navegador já possui a mesma versão, o Portal pode responder:

```text
304 Not Modified
```

reduzindo transferência de HTML.

## Diagnóstico HTTP

O header:

```text
X-Portal-Content-Cache
```

pode mostrar:

```text
MISS
HIT
BYPASS
BYPASS-FORM
```

## Configurações

Em:

```text
Admin → Configurações → Performance
```

há a opção:

```text
Cachear Notícias e Páginas individuais
```

Padrão:

```text
Ativado
```

TTL padrão:

```text
300 segundos
```

Faixa:

```text
30 a 3600 segundos
```

## Invalidação

Alterações administrativas que já invalidavam o cache público também passam a
limpar o grupo:

```text
content-page
```

Além disso, a chave do cache inclui a versão do conteúdo (`updated_at`), de
modo que uma alteração salva gera automaticamente uma nova chave.

## Banco

A v0.88 não cria tabela nova.

As novas preferências usam a tabela existente `configuracoes`.
