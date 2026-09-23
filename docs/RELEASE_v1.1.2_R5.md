# Portal IECLB Parobé — v1.1.2 R5

## Permissões seguras para Google Maps incorporado

A R5 corrige mapas incorporados que apresentavam no navegador:

`Blocked script execution ... because the document's frame is sandboxed and the 'allow-scripts' permission is not set.`

### Correção

Somente iframes oficiais do Google Maps em `/maps/embed` recebem:

- `allow-scripts`
- `allow-same-origin`
- `allow-forms`
- `allow-popups`
- `allow-popups-to-escape-sandbox`

Também recebem `allow="fullscreen"`.

A correção funciona em conteúdo público de:

- Páginas;
- Notícias;
- Eventos.

### Segurança

A regra não é aplicada globalmente.

Iframes de outros domínios permanecem exatamente como estavam. A allowlist
aceita apenas hosts do Google e somente o caminho oficial `/maps/embed`.

O CSP do Portal já permite `www.google.com` e `maps.google.com` em `frame-src`,
portanto não é necessário liberar scripts ou frames globalmente.

### Banco

Nenhum conteúdo salvo no banco é alterado. A normalização ocorre somente na
renderização pública.

Sem migração de banco. APP_VERSION permanece 1.1.2.
