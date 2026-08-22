# Portal IECLB Parobé — v0.28.2

Correção da página inicial modular introduzida na v0.28.x.

## Corrigido

- URLs de notícias que estavam sendo geradas como `http://localhost/C:/xampp/htdocs/...` no Windows/XAMPP.
- Conversão automática de caminhos físicos Windows/Linux para a URL pública definida em `BASE_URL`.
- Links internos de Posts/Notícias, Páginas e Eventos agora são resolvidos dentro do próprio Portal; permalink externo do WordPress não é reutilizado.
- Capas da Home passam a priorizar o ID da Biblioteca de Mídias e o caminho local antes de URLs antigas.
- `midias.arquivo/caminho/path` passa a ter prioridade sobre URL remota quando ambos existirem.
- Compatibilidade com caminhos `public/uploads/...`, `uploads/wordpress/...`, caminhos absolutos do XAMPP/WAMP/Laragon e valores contaminados como `http://localhost/C:/...`.
- Fallback para a primeira `<img src>` do conteúdo quando o esquema instalado não possui um campo reconhecível de capa.
- Links configurados nas seções da Home usam a mesma normalização de URL.

## Banco de dados

Nenhuma migration adicional é necessária. A correção não regrava os posts nem as mídias já importadas.
