# Biblioteca de Mídia 2.0 — v0.70.0

A v0.70.0 reorganiza a Biblioteca de Mídia para instalações com muitos
arquivos.

## Problema anterior

A listagem carregava todos os registros da tabela `midias` de uma vez.

Com o crescimento do Portal isso aumenta:

- tempo de consulta;
- quantidade de HTML;
- memória usada pelo PHP;
- tempo de renderização do navegador.

## Novidades

### Paginação

A biblioteca mostra 24 itens por página.

### Pesquisa

Pesquisa por:

- título;
- nome original;
- texto alternativo;
- extensão.

### Filtros

- Todos;
- Imagens;
- Documentos.

Os botões mostram a quantidade total por tipo.

### Ordenação

- Mais recentes;
- Mais antigos;
- Nome A–Z;
- Nome Z–A;
- Maiores arquivos;
- Menores arquivos.

### Visualizações

- Grade;
- Lista.

### Exclusão em massa

É possível selecionar vários itens da página atual e excluir em uma única
ação.

Cada exclusão continua passando por `MediaService::delete()` e é registrada
na auditoria.

Arquivos protegidos por referências do banco podem gerar erro individual sem
interromper a exclusão dos demais.

## Banco de dados

Nenhuma alteração estrutural é necessária.
