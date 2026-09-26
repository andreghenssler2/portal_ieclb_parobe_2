# Portal IECLB Parobé — v1.1.8 R1

## Reparo do instalador

Corrige o erro:

`SearchService.php / notícias: número inesperado de ocorrências (2).`

### Causa

A fonte de Notícias e a fonte de Páginas no `SearchService` usam a mesma
combinação de `base_where` e `fields`. O instalador inicial procurava esse
trecho no arquivo inteiro e, por segurança, recusava continuar ao encontrar
duas ocorrências.

### Correção R1

O instalador agora:

1. localiza o início da fonte `noticia`;
2. localiza o início da fonte seguinte, `pagina`;
3. isola somente o bloco de Notícias;
4. adiciona o filtro de término de publicação apenas nesse bloco;
5. valida todos os arquivos antes de criar backup ou gravar alterações.

A falha do instalador inicial ocorreu na preparação em memória. Nenhum arquivo
da v1.1.8 foi gravado e o APP_VERSION permaneceu 1.1.7.

A funcionalidade da v1.1.8 permanece a mesma:
galeria, MP4, anexos, destaque por período, início/fim da publicação e preview.

Sem alteração destrutiva da tabela `posts`.
