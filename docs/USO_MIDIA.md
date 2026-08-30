# Uso seguro de mídia — v0.71.0

A v0.71.0 complementa a Biblioteca de Mídia 2.0 com rastreamento de uso.

## Problema

Uma mídia pode estar vinculada a:

- notícia;
- página;
- evento/culto;
- documento;
- outras tabelas com `FOREIGN KEY` para `midias.id`.

Apagar o registro sem verificar essas relações pode quebrar capa, documento ou
outro conteúdo público.

## Nova tela de detalhes

`Admin > Mídia > Biblioteca > Detalhes`

Passa a mostrar:

- pré-visualização;
- nome e tipo;
- tamanho;
- dimensões;
- existência do arquivo físico;
- divergência entre tamanho físico e banco;
- URL copiável;
- título;
- texto alternativo;
- total de referências;
- lista dos conteúdos que usam a mídia.

## Exclusão protegida

`MediaService::delete()` passa a chamar `MediaUsageService::assertCanDelete()`.

Isso significa que a proteção vale tanto para:

- exclusão individual;
- exclusão em massa;
- futuras telas que usem `MediaService::delete()`.

Se houver vínculo, a exclusão é bloqueada e o administrador recebe orientação
para remover ou substituir a referência primeiro.

## Descoberta de vínculos

O serviço usa duas fontes:

1. relações conhecidas do Portal, mesmo quando a instalação antiga não possui
   `FOREIGN KEY`;
2. `information_schema.KEY_COLUMN_USAGE` para relações adicionais que apontem
   para `midias.id`.

## Banco

Nenhuma migração estrutural é necessária.
