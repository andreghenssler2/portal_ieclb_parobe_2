# Cabeçalhos HTTP de segurança — v0.75.0

A v0.75.0 adiciona proteções do navegador às respostas PHP do Portal.

## Cabeçalhos

Por padrão:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy`
- `Cross-Origin-Opener-Policy: same-origin-allow-popups`
- `Content-Security-Policy-Report-Only`

## CSP

A Content Security Policy começa em:

```text
Report-Only
```

Esse modo não bloqueia scripts ou estilos. Violações aparecem no console do
navegador e podem ser avaliadas antes de promover a política para `Enforce`.

A política atual mantém `unsafe-inline` porque o Portal ainda possui scripts e
estilos inline em telas públicas e administrativas. Mesmo assim, limita
origens externas a serviços conhecidos, como Bootstrap CDN, Google Analytics,
Google Fonts e alguns provedores de frame.

Uma etapa futura pode retirar scripts inline e endurecer a CSP.

## HSTS

HSTS fica **desativado por padrão**.

Ele só é enviado quando:

1. está habilitado no painel; e
2. o Portal detecta uma requisição HTTPS.

Isso evita problemas em instalações locais/XAMPP.

## Configuração

Abra:

```text
Admin > Configurações > Cabeçalhos HTTP
```

## Banco

Nenhuma migração estrutural.

As opções são gravadas na tabela de configurações já existente.

## Bootstrap

`SecurityHeadersService::apply()` é chamado antes do cache público, garantindo
que páginas PHP normais e respostas de cache do Portal recebam a política.
