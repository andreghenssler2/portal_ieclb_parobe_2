# Portal IECLB Parobé v0.33.1

## Pesquisa nas listagens administrativas

Complementa a paginação da v0.33.0 com pesquisa em:

- Posts / Notícias;
- Categorias;
- Tags;
- Páginas.

### Comportamento

- A pesquisa usa o parâmetro GET `q`.
- A paginação permanece em 50 registros por página.
- O termo pesquisado é mantido ao navegar entre as páginas de resultados.
- Em Posts e Páginas, o filtro de Lixeira/ativos é mantido durante a pesquisa e a paginação.
- O painel mostra a quantidade de resultados encontrados.
- O botão **Limpar** remove somente a pesquisa e preserva filtros relevantes, como `status=lixeira`.
- Categorias continuam carregando a árvore completa no seletor de categoria ascendente; somente a tabela administrativa é filtrada/paginada.

## Banco de dados

Nenhuma alteração de banco de dados é necessária.
