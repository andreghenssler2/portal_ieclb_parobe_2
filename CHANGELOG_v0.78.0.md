# Portal IECLB Parobé v0.78.0

## Responder mensagens dos formulários

- Nova área "Responder por e-mail" abaixo da mensagem recebida.
- Destinatário detectado automaticamente pelo campo de e-mail do formulário.
- Envio pelo `MailService`/SMTP já configurado.
- Assunto e mensagem personalizados.
- Nova tabela `formulario_resposta_replicas`.
- Histórico de respostas enviadas na própria tela.
- Registra administrador, destinatário, assunto, mensagem, status e erro.
- Resposta enviada mantém a mensagem como `lida`.
- CSRF e validações preservados.
