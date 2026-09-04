# v0.80.0 R4 — Resumo na leitura

A R4 reforça a regra da v0.80:

- o campo `resumo` nunca deve aparecer como bloco visível na notícia individual;
- ele continua disponível para cards, listagens, busca e SEO;
- se o conteúdo legado começar com um primeiro `<p>` cujo texto é exatamente
  igual ao `resumo`, esse primeiro parágrafo duplicado também é ocultado apenas
  na renderização pública;
- o conteúdo salvo no banco não é alterado;
- a imagem de capa continua obedecendo `exibir_imagem_capa`.

A remoção do parágrafo duplicado é conservadora: só acontece quando o texto
normalizado do primeiro `<p>` é exatamente igual ao resumo normalizado.
