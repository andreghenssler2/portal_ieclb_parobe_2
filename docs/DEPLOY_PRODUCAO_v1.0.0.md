# Deploy de Produção — Portal IECLB Parobé v1.0.0

## 1. Antes de publicar

- crie backup do banco;
- crie backup completo dos arquivos;
- execute `php tests/run.php`;
- execute `php tests/release-readiness.php`;
- execute `php tests/release-final.php`;
- confirme que não existem bloqueadores.

## 2. Configuração do ambiente final

No servidor de produção:

- `APP_ENV` deve ser `production`;
- `APP_DEBUG` deve ser `false`;
- `BASE_URL` deve usar o domínio definitivo;
- prefira HTTPS;
- mantenha `TIMEZONE=America/Sao_Paulo`;
- configure as credenciais do banco;
- confirme permissão de escrita em `storage` e uploads.

## 3. Cron

Configure a execução do `cron.php` no servidor.

Exemplo conceitual a cada 5 minutos:

```text
*/5 * * * * php /caminho/do/portal/cron.php
```

Use o caminho real fornecido pela hospedagem.

Depois confirme o heartbeat em:

`Admin → Ferramentas → Saúde do Cron`

## 4. E-mail

Confirme:

- SMTP;
- SPF;
- DKIM;
- DMARC;
- MX;
- envio de formulário;
- newsletter.

A configuração DNS deve ser feita no provedor do domínio.

## 5. Segurança

- mantenha atualizadores e diagnósticos bloqueados na web;
- confirme 2FA administrativo;
- revise perfis e permissões;
- revise sessões;
- revise CSP e cabeçalhos HTTP;
- use HTTPS.

## 6. Desempenho

Na hospedagem final, confira:

`Admin → Ferramentas → Desempenho`

Verifique especialmente:

- cache;
- OPcache;
- permissões de `storage`;
- tempo de resposta do banco.

OPcache inativo no PHP CLI local não prova que o OPcache do servidor web está
inativo.

## 7. Pós-publicação

Teste no domínio real:

- Home;
- notícias;
- páginas;
- agenda;
- comunidades;
- grupos;
- lideranças;
- documentos;
- formulários;
- busca;
- login/2FA;
- envio de e-mail;
- cookies/GTM;
- sitemap;
- RSS;
- desktop/mobile/tablet;
- teclado e zoom de 200%.

## 8. Fechamento

Quando o domínio real estiver validado:

```bash
php tests/release-readiness.php
php tests/release-final.php
```

Guarde o backup imediatamente anterior ao go-live.
