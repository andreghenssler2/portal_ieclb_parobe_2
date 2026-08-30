# Integridade do armazenamento de mídia — v0.73.0

A v0.73.0 adiciona um diagnóstico entre o banco de dados e a pasta `uploads/`.

## Tela

`Admin > Mídia > Integridade`

O escaneamento é sob demanda para não deixar o painel mais lento.

## Verificações

- registros de mídia cujo arquivo físico não existe;
- arquivos cujo tamanho físico difere do valor salvo no banco;
- variantes WebP/thumbnail registradas mas ausentes;
- arquivos derivados órfãos;
- arquivos físicos sem registro na Biblioteca.

## Limpeza automática

A ferramenta é conservadora.

Pode remover automaticamente apenas:

1. registros de `midia_variantes` cujo arquivo físico não existe;
2. arquivos `.thumb.webp` e `.optimized.webp` sem registro correspondente,
   desde que tenham mais de uma hora.

## Arquivos originais órfãos

Nunca são apagados automaticamente.

Isso é importante porque instalações antigas podem ter URLs de imagens
gravadas diretamente em HTML, conteúdo importado do WordPress ou arquivos
enviados manualmente antes da Biblioteca de Mídia.

Esses itens são exibidos apenas para revisão.

## Limite

Cada escaneamento percorre até 10.000 arquivos físicos da pasta `uploads/`.

Se esse limite for atingido, a tela informa que o diagnóstico foi parcial.

## Banco

Nenhuma alteração estrutural.
