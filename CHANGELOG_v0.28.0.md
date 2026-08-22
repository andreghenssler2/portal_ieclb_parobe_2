# Portal IECLB Parobé — v0.28.0

## Página inicial modular

A v0.28.0 redesenha a área principal do portal com seções configuráveis pelo administrador.

### Novidades

- Novo menu **Aparência → Página Inicial**.
- O administrador pode adicionar, editar, excluir, ativar/desativar e reordenar seções.
- Três layouts disponíveis:
  - **Destaque + 2**: uma notícia grande e duas menores ao lado.
  - **Carrossel**: cards horizontais com controles anterior/próximo.
  - **Grade**: cards responsivos em grade.
- Fontes de conteúdo: **Posts / Notícias**, **Eventos** e **Páginas**.
- Posts podem ser filtrados por categoria.
- Configurações por seção: título, quantidade, link “Veja mais/Mostrar Todos”, data, resumo e autoplay.
- Imagens de capa são resolvidas de forma adaptativa pelo ID da Biblioteca de Mídia, caminho ou URL.
- Layout responsivo para desktop, tablet e celular.

### Seções iniciais

Quando ainda não há configuração, o atualizador cria:

1. Últimas Notícias — Destaque + 2
2. Comunidades — Carrossel
3. Paróquia — Carrossel

O instalador tenta associar automaticamente as categorias Comunidade/Comunidades e Paroquial/Paróquia quando elas existem.

### Banco de dados

Nova tabela:

- `home_secoes`

Nova permissão:

- `home.gerenciar`

A permissão é concedida ao perfil Administrador durante a atualização.

### Integração com o index.php

O atualizador procura a tag `<main>` do `index.php`, cria um backup do arquivo e substitui apenas o conteúdo interno do `<main>` pelo renderizador modular da v0.28.0. Cabeçalho, menu e rodapé existentes são preservados.

Se a instalação não possuir uma tag `<main>` literal no `index.php`, nenhum conteúdo é removido. Nesse caso o instalador gera `INTEGRAR_HOME_v0.28.0.txt` com a linha de integração manual.
