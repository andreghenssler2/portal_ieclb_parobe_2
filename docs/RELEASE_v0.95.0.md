# Portal IECLB Parobé — v0.95.0

## Entregabilidade de e-mail: SPF, DKIM e DMARC

A v0.95.0 adiciona diagnóstico DNS para o domínio configurado como remetente em
Configurações → E-mail.

### Verificações

- SPF;
- DKIM, quando o seletor é informado;
- DMARC e política `p=`;
- MX;
- duplicidade de SPF;
- alerta para `+all`;
- disponibilidade de `dns_get_record()` no servidor.

### Admin

Novo caminho:

`Admin → Configurações → E-mail → Autenticação de domínio`

O Portal somente consulta os registros. Nenhuma alteração DNS é feita
automaticamente.

### CLI

`php tests/email-dns.php`

Avisos de SPF/DKIM/DMARC não impedem o Portal de funcionar, mas devem ser
corrigidos antes da entrada em produção para melhorar autenticação e
entregabilidade.
