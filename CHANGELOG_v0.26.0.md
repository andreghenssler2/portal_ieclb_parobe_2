# Portal IECLB Parobé v0.26.0

## E-mail / SMTP

- Novo menu **Configurações > E-mail**.
- Transporte selecionável entre PHP `mail()` e SMTP.
- SMTP com autenticação LOGIN, STARTTLS, SSL/TLS direto ou sem criptografia.
- Verificação de certificado TLS configurável.
- Teste de envio pelo painel.
- Remetente e Reply-To globais.
- Senha SMTP criptografada com Sodium ou OpenSSL e chave privada em `storage/private/mail.key`.
- Nova tabela `email_envios` com histórico de sucesso/falha e diagnóstico.
- Retenção configurável do histórico técnico.
- Nova permissão `email.gerenciar`, concedida inicialmente ao Administrador.
- Newsletter e confirmação de assinatura passam a usar o serviço central de e-mail.
- Campanhas registram o erro real retornado pelo transporte em caso de falha.
