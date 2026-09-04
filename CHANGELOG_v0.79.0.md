# Portal IECLB Parobé v0.79.0

## Respostas recebidas por e-mail

- Respostas dos contatos podem voltar ao Portal via IMAP.
- E-mails enviados recebem token `[IECLB-R<ID>]`.
- O remetente recebido precisa coincidir com o e-mail do formulário.
- Nova tabela `formulario_resposta_entradas`.
- Nova tela `Admin > Configurações > E-mail de Entrada`.
- Teste de conexão IMAP.
- Sincronização manual.
- Nova tarefa agendada a cada 5 minutos.
- Conversa por e-mail mostra enviados e recebidos em ordem cronológica.
- Ao chegar uma nova resposta, a mensagem do formulário volta para status `nova`.
- Reutiliza usuário e senha SMTP já protegidos pelo Portal.
