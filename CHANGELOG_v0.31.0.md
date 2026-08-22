# Portal IECLB Parobé — v0.31.0

## Cache e Performance

A v0.31.0 adiciona uma camada de cache simples em arquivos, sem dependência de Redis/Memcached, adequada tanto para XAMPP quanto para hospedagem compartilhada.

### Novidades

- Novo menu **Configurações → Performance**.
- Cache persistente da tabela `configuracoes` entre requisições.
- Cache de página inteira da **Home pública**, somente para visitantes anônimos, GET sem query string.
- Painel administrativo, sessões e páginas com formulários não são cacheados.
- Invalidação automática da Home quando operações administrativas alteram conteúdos relacionados.
- Limpeza manual de todo o cache e limpeza somente dos itens expirados.
- Estatísticas de quantidade de arquivos, tamanho e permissão de escrita.
- Configuração de TTL geral e TTL da Home.
- Pasta `storage/cache/` protegida por `.htaccess`.
- Compressão HTTP via `mod_deflate`, quando disponível.
- Expiração de navegador para CSS, JavaScript, imagens e fontes via `mod_expires`, quando disponível.
- Cabeçalho de diagnóstico `X-Portal-Cache: HIT` ou `MISS` na Home.
- Nova permissão `performance.gerenciar`, concedida inicialmente apenas ao Administrador.

### Valores padrão

- Cache persistente: ativo.
- Cache da Home: ativo.
- TTL geral: 300 segundos.
- TTL da Home: 120 segundos.

### Compatibilidade

Atualização incremental recomendada sobre a v0.30.0. Não cria novas tabelas.
