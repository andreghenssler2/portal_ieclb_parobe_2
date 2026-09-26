# Portal IECLB Parobé — v1.1.8

## Notícias avançadas

A v1.1.8 amplia o editor e a leitura pública de Notícias.

### Galeria de fotos

O editor pode associar até 30 imagens da Biblioteca de Mídia à notícia.
A galeria é exibida de forma responsiva após o conteúdo.

### Vídeo MP4

Além da inserção MP4 já disponibilizada no editor pela série 1.1.6, a notícia
agora pode ter um vídeo MP4 associado. Ele é exibido em um player HTML5
responsivo ao final do conteúdo.

### Anexos

Documentos da Biblioteca de Mídia podem ser associados à notícia e aparecem
em uma área de downloads, com nome e tamanho do arquivo.

### Destaque por período

O campo existente “Destacar na página inicial” pode receber um início e um fim.
Se os campos ficarem vazios, o destaque continua sem limite de período.

### Início e fim da publicação

O início continua usando `publicado_em`. A v1.1.8 acrescenta `publicado_ate`.
Depois do horário final, a notícia deixa de aparecer na leitura individual,
Home, Home modular, busca, relacionadas e ranking de notícias.

O registro não é excluído; permanece disponível no painel administrativo.

### Pré-visualização

Notícias salvas, inclusive rascunhos e agendadas, podem ser abertas em uma
pré-visualização administrativa antes da publicação.

### Banco

A instalação cria automaticamente e de forma idempotente:

- `post_publicacao_extras`
- `post_galeria_midias`
- `post_anexos_midias`

Não há alteração destrutiva da tabela `posts`.

`APP_VERSION = 1.1.8`
