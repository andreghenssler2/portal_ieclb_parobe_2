# Portal IECLB Parobé — v1.1.4 R2

## PDFs locais sem Google Viewer

Corrige posts antigos/importados que incorporam um PDF local através de
`docs.google.com/gview`, `docs.google.com/viewer` ou visualizador equivalente
do Google.

Alguns navegadores recebem bloqueio do Google dentro do iframe. Como o arquivo
PDF já está hospedado no Portal, a R2 troca automaticamente o `src` do iframe
para o PDF local.

Exemplo:

`Google Viewer -> https://ieclbparobe.com.br/wp-content/uploads/.../arquivo.pdf`

passa a ser:

`iframe -> https://ieclbparobe.com.br/wp-content/uploads/.../arquivo.pdf`

### Segurança

A conversão só ocorre se:

- a origem for um visualizador do Google; e
- o parâmetro interno apontar para um `.pdf`; e
- o PDF estiver hospedado no domínio do Portal.

PDFs de domínios externos não são reescritos.

O HTML salvo no banco não é modificado. A correção acontece na renderização,
portanto também corrige posts antigos sem necessidade de reimportação.

A lógica anterior de permissões do Google Maps é preservada.

Sem migração de banco. APP_VERSION permanece 1.1.4.
