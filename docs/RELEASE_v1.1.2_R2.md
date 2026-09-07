# Portal IECLB Parobé — v1.1.2 R2

## Status Agendado automático

Ao escolher uma data/hora futura no editor de Notícias, o campo **Status**
passa automaticamente para **Agendado**.

A regra também é validada no servidor. Assim, mesmo que o JavaScript não seja
executado, uma data futura salva pelo editor resulta em `status='agendado'`.

### Regras

- data/hora futura → Status = Agendado;
- Status Agendado exige data/hora futura;
- data passada de uma notícia já publicada não altera o status ao abrir o editor;
- Administrador continua podendo agendar diretamente conforme a R1;
- conteúdo agendado continua oculto da Home até a data programada.

Sem migração de banco.
