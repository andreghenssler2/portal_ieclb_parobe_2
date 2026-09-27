# Portal IECLB Parobé — v1.1.13

## E-mail

### Revisão completa do SMTP

A nova tela `admin/configuracoes/email-saude.php` reúne:

- problema atual de configuração;
- avisos de porta/criptografia/certificado;
- diagnóstico de conexão e autenticação SMTP;
- volume enviado e falhas recentes;
- situação SPF, DKIM, DMARC e MX.

### Teste de envio melhorado

O teste avançado executa:

1. validação da configuração;
2. diagnóstico SMTP;
3. verificação DNS;
4. envio real com código único;
5. duração total.

Falhas do teste não entram na fila, evitando poluir a fila operacional.

### Histórico de falhas

A Central lista até 30 falhas recentes de `email_envios`.

### SPF, DKIM e DMARC

O Portal continua sem alterar o DNS do provedor. A v1.1.13 mostra o estado
detectado e ações corretivas para o administrador publicar na hospedagem.

### Fila de e-mails que falharam

Nova tabela:

`email_fila_falhas`

Falhas reais de transporte em `MailService::sendHtml()` são colocadas
automaticamente na fila, quando habilitada.

A tarefa `reenviar_emails_falhos` processa a fila automaticamente.
As tentativas usam espera progressiva e não geram itens duplicados durante
o próprio reenvio.

### Segurança

A fila guarda somente os campos de remetente/resposta explicitamente usados
pela mensagem. Senha SMTP e outras credenciais nunca são copiadas para a fila.

`APP_VERSION = 1.1.13`
