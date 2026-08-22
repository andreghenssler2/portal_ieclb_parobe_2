# Portal IECLB Parobé v0.27.1

Correção focada na importação de imagens destacadas do WordPress.

## Corrigido

- Posts, páginas e eventos importados isoladamente agora buscam sua `featured_media` diretamente na REST API, sem exigir que o módulo Mídias tenha sido importado antes.
- Capas de registros já importados pela v0.27.0 são recuperadas ao repetir a importação, inclusive no modo **Importar apenas novos**.
- O importador passa a reconhecer esquemas que armazenam a capa por ID da mídia, caminho de arquivo ou URL.
- Se o download local do arquivo falhar, o importador tenta preservar a capa usando a URL remota da mídia, sem impedir a importação do conteúdo.
- Mantida a prevenção de duplicação pela tabela `wordpress_import_map`.

## Como corrigir posts já importados

1. Instale esta atualização sobre a v0.27.0.
2. Execute `php atualizar_v0.27.1.php`.
3. Abra **Ferramentas → Importar WordPress → Posts / Notícias**.
4. Use a mesma URL do WordPress e execute novamente a importação.
5. Pode manter **Importar apenas novos**: a v0.27.1 identifica os registros já existentes e preenche as capas ausentes.
