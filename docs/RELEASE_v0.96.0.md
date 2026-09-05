# Portal IECLB Parobé — v0.96.0

## Saúde de desempenho e cache

A v0.96.0 adiciona uma tela operacional para revisar o cache do Portal antes da
entrada em produção.

### Admin

Novo caminho:

`Admin → Ferramentas → Desempenho`

### Recursos

- estado do cache de dados;
- estado do cache da Home pública;
- quantidade e tamanho dos arquivos de cache;
- quantidade de arquivos expirados;
- limpeza de cache expirado;
- limpeza manual de todo o cache;
- configuração de TTL;
- estado do OPcache;
- versão/SAPI do PHP;
- `memory_limit`;
- `realpath_cache_size` e `realpath_cache_ttl`;
- informações básicas da conexão com o banco;
- benchmark seguro de `SELECT 1`;
- benchmark temporário de escrita/leitura do cache.

### Segurança

O benchmark do banco executa somente `SELECT 1`.

O benchmark do cache cria uma chave temporária no grupo `diagnostic` e a remove
imediatamente após a leitura.

Nenhum conteúdo, usuário ou configuração é alterado pelo benchmark.

### CLI

`php tests/performance.php`

### Observação

O cache de página inteira continua restrito à Home pública, em GET sem
querystring e para visitantes anônimos.
