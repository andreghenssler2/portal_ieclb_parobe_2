# Portal IECLB Parobé v0.61.0

## Fluxo editorial para Notícias

A v0.61.0 introduz uma fila de aprovação editorial para novos conteúdos.

### Fluxo

`Rascunho → Em revisão → Ajustes (quando necessário) → Aprovado → Publicado`

### Novidades

- Nova tela `Admin > Notícias > Fila de revisão`.
- Botão **Enviar para revisão** no editor de notícias salvas.
- Revisor pode:
  - aprovar;
  - solicitar ajustes com observação.
- Publicador pode publicar um conteúdo aprovado.
- Aprovação é vinculada ao conteúdo por hash SHA-256.
- Se título, texto, SEO, imagem, categorias ou tags mudarem após a aprovação,
  a publicação é bloqueada até uma nova revisão.
- Novas permissões:
  - `noticias.revisar`;
  - `noticias.publicar`.
- Administradores recebem automaticamente as duas permissões.
- Nova opção em `Configurações > Escrita`:
  **Exigir aprovação editorial antes de publicar novas notícias**.
- Ações de publicação em massa respeitam a aprovação editorial.
- Auditoria das ações:
  - enviar para revisão;
  - aprovar;
  - solicitar ajustes;
  - publicar.
- `APP_VERSION` atualizado para `0.61.0`.

### Compatibilidade

A primeira versão do workflow controla principalmente conteúdos ainda não
publicados. Notícias que já estavam publicadas continuam podendo ser editadas
como nas versões anteriores, evitando quebrar o processo editorial existente.
