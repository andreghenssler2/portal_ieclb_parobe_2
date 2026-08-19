# Portal IECLB Parobé v0.16.0

## Revisões
- Notícias e Páginas passam a gerar uma revisão automaticamente antes de cada alteração.
- Tela de histórico com data, autor, status e resumo da versão anterior.
- Restauração de revisões com preservação automática da versão atual.
- Limite configurável de 5 a 100 revisões por conteúdo em Configurações > Escrita (padrão 30).

## Lixeira
- Notícias e Páginas agora são movidas para a Lixeira em vez de excluídas diretamente.
- Restauração recupera o status anterior.
- Exclusão permanente só é permitida dentro da Lixeira.
- Páginas enviadas para a Lixeira deixam de ser exibidas no menu público.

## Banco
- Nova tabela `revisoes`.
- `posts.status` e `paginas.status` recebem o valor `lixeira`.
- Novas colunas `status_anterior` e `lixeira_em` em `posts` e `paginas`.
- Nova configuração `writing_revision_limit`.
