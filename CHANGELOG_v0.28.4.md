# Portal IECLB Parobé — v0.28.4

## Correção: Comunidades e Paróquia não apareciam na Home

- A Home agora considera simultaneamente a categoria principal do post e todas as relações de categorias encontradas no banco.
- Adicionada a relação estável `home_post_categorias`, independente do nome da tabela usada por versões antigas do Portal.
- O atualizador copia automaticamente a categoria principal dos posts para a nova relação.
- Quando existe histórico de importação WordPress, o atualizador tenta recuperar as múltiplas categorias dos posts pela REST API pública do site de origem.
- O importador WordPress passa a preencher `home_post_categorias` em todas as novas importações.
- Ao executar Posts / Notícias em **Apenas novos**, os posts já existentes têm suas categorias reparadas sem serem duplicados.
- A Home detecta também tabelas legadas com nomes como `post_categoria`, `categoria_posts`, `posts_categories` e equivalentes.
- Categorias-pai continuam incluindo suas subcategorias.
- Em **Aparência → Página Inicial**, cada seção mostra quantos conteúdos foram encontrados pelo filtro. Se uma seção ativa retornar zero, o painel exibe aviso em vermelho.

## Compatibilidade

Esta atualização é incremental sobre a v0.28.3 e preserva as configurações dos blocos existentes.
