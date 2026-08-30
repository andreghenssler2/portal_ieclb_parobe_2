# Portal IECLB Parobé v0.73.0

## Integridade do armazenamento de mídia

- Novo `MediaIntegrityService`.
- Nova tela `Admin > Mídia > Integridade`.
- Detecta arquivos originais ausentes.
- Detecta divergência de tamanho entre banco e arquivo.
- Detecta variantes WebP/thumbnail ausentes.
- Detecta derivados órfãos.
- Lista arquivos físicos não registrados.
- Limpeza segura de registros de variantes inválidos.
- Limpeza segura de `.thumb.webp` e `.optimized.webp` órfãos.
- Arquivos originais órfãos nunca são apagados automaticamente.
- Nenhuma alteração no banco de dados.
