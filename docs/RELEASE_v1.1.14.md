# Portal IECLB Parobé — v1.1.14

## SEO e compartilhamento

### WhatsApp / Facebook

O tema já possuía Open Graph. A v1.1.14 melhora o comportamento para conteúdos
sem imagem de capa:

- Notícias;
- Eventos;
- Comunidades.

Quando não existe imagem específica, o Portal gera automaticamente uma imagem
social 1200 × 630 com o título do conteúdo.

O arquivo padrão `public/images/social-auto-default-v1114.png` é usado quando
a extensão GD não está disponível.

### Dados estruturados

O tema passa a emitir JSON-LD específico:

- `NewsArticle` para Notícias;
- `Event` para Eventos;
- `Church` para Comunidades.

O JSON-LD usa URL canônica, descrição, datas, imagem e demais dados existentes
no conteúdo.

### Sitemap

A v1.1.14:

- deixa de listar notícias cujo período de publicação já terminou;
- inclui a imagem social automática no sitemap quando notícia, evento ou
  comunidade não possuir outra imagem;
- mantém os segmentos já existentes do sitemap.

### URLs canônicas

As URLs canônicas do tema passam por normalização para URL absoluta antes de
serem emitidas no HTML/Open Graph.

Os redirects canônicos já existentes em Notícias, Eventos e Comunidades
continuam preservados.

### Redirects de conteúdos antigos

Nova tabela:

`seo_redirects`

Nova tela:

`admin/seo/redirects.php`

O administrador pode cadastrar:

`/url-antiga` → `/url-atual`

com HTTP 301 ou 302.

Por segurança, o destino é sempre interno ao Portal; redirects externos não
são permitidos.

`APP_VERSION = 1.1.14`
