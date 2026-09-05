# v0.81.0 — Padrão da imagem de capa

A v0.81.0 adiciona em:

```text
Admin → Configurações → Mídia
```

a configuração:

```text
Imagem de capa das Notícias
Padrão para novas notícias:
(•) Não mostrar na leitura
( ) Mostrar na leitura
```

## Comportamento

O padrão inicial é:

```text
Não mostrar na leitura
```

A configuração vale como padrão para novos Posts / Notícias.

Cada notícia continua podendo escolher individualmente:

```text
Imagem de capa na leitura
( ) Sim, exibir ao abrir a notícia
( ) Não, ocultar na leitura
```

## Banco

A coluna:

```text
posts.exibir_imagem_capa
```

também passa a ter:

```text
DEFAULT 0
```

Assim, inserções feitas fora do editor também seguem o padrão seguro de não
mostrar a capa na leitura.

## Notícias existentes

A v0.81 não altera novamente os registros existentes.

As notícias já publicadas que receberam a v0.80 R6 permanecem com a capa
oculta na leitura.
