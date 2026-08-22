# Portal IECLB Parobé v0.27.0

## Importação do WordPress

- Novo importador em **Ferramentas > Importar WordPress**.
- Importação completa na ordem: Categorias, Tags, Mídias, Páginas, Posts/Notícias e Eventos.
- Importação individual para cada módulo, com atalhos dentro dos menus administrativos.
- Conexão pela WordPress REST API (`/wp-json/`).
- Autenticação opcional por usuário + Application Password.
- Teste de conexão antes da importação.
- Detecção de Custom Post Types de eventos expostos pela REST API e suporte a endpoint personalizado.
- Processamento paginado em lotes para reduzir risco de timeout.
- Modos: apenas novos, novos + atualizar existentes e simulação sem gravação.
- Tabela de mapeamento entre IDs do WordPress e IDs locais, evitando duplicação em reimportações.
- Reconstrução de hierarquia de Categorias e Páginas quando os relacionamentos estão disponíveis.
- Associação de Categorias e Tags em Posts quando as tabelas relacionais do Portal são detectadas.
- Importação de mídia para `public/uploads/wordpress/AAAA/MM`.
- Preservação adaptativa de título, slug, conteúdo, resumo, datas, status, ALT, legenda, descrição e imagem destacada quando os campos existem no esquema local.
- Substituição de URLs conhecidas de mídias antigas pelas URLs locais após a importação das mídias.
- Histórico de importações, contadores, logs por item e diagnóstico de erros.
- Nova permissão `wordpress.importar`, concedida inicialmente ao Administrador.

## Banco de dados

Novas tabelas:

- `wordpress_importacoes`
- `wordpress_import_map`
- `wordpress_import_logs`

## Segurança

- Somente usuários com `wordpress.importar` podem iniciar/processar importações.
- CSRF validado no início e em cada lote AJAX.
- Application Password não é persistida no banco; permanece apenas na sessão durante a importação.
- Cliente HTTP aceita apenas HTTP/HTTPS e verifica certificados TLS.
