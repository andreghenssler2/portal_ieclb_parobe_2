# Portal IECLB Parobé — v1.1.2 R7

## Correção do erro "Not Acceptable" ao atualizar Notícias

O erro é gerado pelo ModSecurity antes de o PHP receber o formulário.

Quando uma notícia contém HTML rico, especialmente `iframe` de Google Maps com
atributos como `sandbox`, `allow-scripts` e `allow`, algumas regras WAF podem
interpretar o corpo do POST como tentativa de injeção HTML/script e devolver
HTTP 406 / "Not Acceptable".

### Correção

Somente no transporte do formulário de Notícias:

1. o HTML do TinyMCE é convertido no navegador para base64url;
2. o `textarea` original deixa de ser enviado no POST;
3. o servidor decodifica o conteúdo antes das validações e gravação;
4. o banco continua recebendo exatamente o HTML original.

A correção não desativa ModSecurity, não relaxa `.htaccess` e mantém CSRF e as
permissões administrativas existentes.

Sem migração de banco. APP_VERSION permanece 1.1.2.
