# Portal IECLB Parobé — v0.33.0

## Paginação no administrador

A v0.33.0 limita as principais listagens de conteúdo do painel a 50 registros por página.

### Listagens paginadas

- Posts / Notícias
- Categorias de Posts
- Tags de Posts
- Páginas

### Comportamento

- A paginação só aparece quando a listagem possui mais de 50 itens.
- Cada página exibe no máximo 50 registros.
- Mostra a faixa atual, por exemplo: `51–100 de 237 itens`.
- Controles `Anterior`, números de página e `Próxima`.
- Preserva o filtro de Lixeira nas listagens que possuem esse recurso.
- Páginas fora do intervalo são automaticamente ajustadas para a última página válida.
- Categorias mantêm a árvore completa no seletor de categoria ascendente; somente a tabela é paginada.
- As consultas de Posts, Tags e Páginas passam a usar `LIMIT/OFFSET`, evitando carregar centenas de linhas de uma vez no administrador.

### Banco de dados

Nenhuma alteração de estrutura é necessária nesta versão.
