# Portal IECLB Parobé v0.25.0

## Newsletter

- Cadastro público em `/newsletter`.
- Confirmação por e-mail opcional (double opt-in).
- Confirmação por rota `/newsletter/confirmar/{token}`.
- Descadastro por rota `/newsletter/cancelar/{token}`.
- Gestão de assinantes com filtros, ativação, cancelamento, exclusão e CSV.
- Campanhas em HTML com TinyMCE.
- Personalização `{{nome}}`, `{{email}}` e `{{descadastrar_url}}`.
- Envio manual em lotes de até 30 destinatários para reduzir timeouts.
- Registro de envios e falhas por campanha.
- Nova permissão `newsletter.gerenciar`.
- Link Newsletter adicionado ao Menu Principal e rodapé quando habilitado.

> O envio usa a função PHP `mail()`. Para confirmação automática e campanhas, a hospedagem precisa estar configurada para envio de e-mail.
