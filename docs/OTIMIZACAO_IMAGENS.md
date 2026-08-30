# Otimização automática de imagens — v0.72.0

A v0.72.0 adiciona processamento de imagens ao upload e à biblioteca existente.

## Recursos

- redimensionamento opcional do arquivo original;
- largura máxima configurável;
- geração de variante WebP;
- qualidade WebP configurável;
- miniatura WebP para o painel;
- processamento em lotes de 20 imagens;
- reprocessamento individual;
- remoção das variantes quando a mídia é excluída.

## Formatos

Processados:

- JPEG;
- PNG;
- WebP.

GIF é preservado sem processamento porque a extensão GD normalmente perderia
quadros de uma animação.

## GD

A otimização depende da extensão GD do PHP.

Se GD estiver indisponível:

- upload continua funcionando;
- documento continua funcionando;
- mídia original continua funcionando;
- apenas redimensionamento/WebP/miniatura são ignorados.

## Configurações

`Admin > Configurações > Mídia`

Novas opções:

- otimização automática;
- largura máxima do original;
- gerar WebP;
- qualidade WebP;
- gerar miniatura;
- largura da miniatura.

## Biblioteca existente

Use:

`Admin > Mídia > Otimizar imagens`

O processamento ocorre em lotes pequenos para evitar timeout.

## Banco

Nova tabela:

`midia_variantes`

Ela registra caminho, tipo, tamanho e dimensões das variantes.
