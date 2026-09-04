# v0.80.0 R2 — Leitura das Notícias

Correção do instalador da v0.80.0.

O R2 não depende de uma linha exata em `noticia.php`. Ele reconhece variações
de espaços/quebras de linha e aplica:

- remoção do resumo da leitura individual;
- controle por notícia para exibir/ocultar a imagem de capa;
- campo `posts.exibir_imagem_capa`;
- opção no editor de Posts / Notícias.

O resumo continua sendo usado em cards, listas, busca e SEO.

A imagem de capa, quando ocultada na leitura, continua disponível para cards,
destaques e compartilhamento/Open Graph.
