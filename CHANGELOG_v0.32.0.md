# Portal IECLB Parobé — v0.32.0

## Otimização de imagens e WebP

A v0.32.0 adiciona processamento de imagens da Biblioteca de Mídia sem substituir os arquivos originais.

### Novidades

- Novo menu **Mídia → Otimização de imagens**.
- Geração automática de variantes redimensionadas para novos uploads JPEG, PNG e WebP.
- Suporte a **Imagick** com fallback para **GD**.
- Geração opcional de WebP quando o PHP possui suporte.
- Tamanhos padrão: 320, 640, 1024 e 1600 px, configuráveis pelo painel.
- Qualidade configurável entre 50% e 95%.
- Arquivo original sempre preservado.
- Variantes registradas na nova tabela `midia_variantes`.
- Imagens renderizadas através de `mediaUrl()` passam a preferir uma variante otimizada local.
- A Home modular passa a preferir uma variante adequada da mídia destacada.
- Exclusão de uma mídia também remove seus derivados físicos.
- Processamento de imagens existentes em lotes de 10 pelo painel.
- Ferramenta CLI para bibliotecas grandes: `php otimizar_imagens_v0.32.0.php`.
- Opção `--force` para regenerar variantes e `--limit=N` para limitar o lote.

### Segurança e compatibilidade

- GIF não é reprocessado para evitar perder animações.
- Imagens gigantes são ignoradas pelo GD quando o processamento puder causar estouro de memória.
- Falha na otimização nunca cancela um upload que já foi salvo corretamente.
- Requer Portal v0.31.0 ou superior.
