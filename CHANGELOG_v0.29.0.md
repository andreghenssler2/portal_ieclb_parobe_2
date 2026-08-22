# Portal IECLB Parobé — v0.29.0

## Consolidação da Página Inicial modular

A v0.29.0 consolida em uma única versão estável as correções realizadas durante a série v0.28.x.

- Mantém o editor **Aparência → Página Inicial** com blocos ativáveis, removíveis, editáveis e ordenáveis.
- Mantém o layout **Últimas Notícias** no formato destaque grande + 2 notícias menores.
- Mantém os blocos **Comunidades** e **Paróquia** em carrossel de notícias.
- Preserva as configurações existentes em `home_secoes`; a atualização não recria nem apaga os blocos configurados.
- Consolida a normalização de URLs para instalações em subpastas e Windows/XAMPP.
- Consolida o uso das mídias locais importadas antes de URLs remotas do WordPress.
- Consolida a tabela `home_post_categorias` e o suporte a posts com múltiplas categorias.
- Categorias-pai continuam incluindo seus descendentes.
- O importador WordPress continua reparando vínculos de categorias sem duplicar posts quando executado em **Apenas novos**.
- O atualizador mostra um diagnóstico dos blocos ativos e a quantidade de itens encontrada por cada filtro.

## Compatibilidade

Atualização incremental recomendada sobre a v0.28.4. Também contém verificações idempotentes das estruturas essenciais da Home para facilitar recuperação de instalações que passaram por alguma das versões v0.28.x.
