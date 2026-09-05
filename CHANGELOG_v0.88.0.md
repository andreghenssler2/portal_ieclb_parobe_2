# Portal IECLB Parobé v0.88.0

## Performance e cache por conteúdo

- Cache público individual para Notícias.
- Cache público individual para Páginas.
- Mantém analytics de Notícias antes do cache.
- Bypass automático para formulários incorporados.
- Bypass para HTML com formulário/CSRF.
- ETag e Last-Modified.
- Suporte a resposta HTTP 304.
- Header X-Portal-Content-Cache para diagnóstico.
- Nova opção em Configurações > Performance.
- TTL independente para cache por conteúdo.
- Invalidação automática do grupo content-page.
- Sem nova tabela no banco.
