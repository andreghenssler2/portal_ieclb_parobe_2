# Respostas de e-mail dentro do Portal — v0.79.0

A v0.79.0 completa a conversa iniciada na v0.78.

## Fluxo

1. uma pessoa envia um formulário;
2. o administrador abre a resposta;
3. responde pelo Portal;
4. o e-mail enviado recebe um token como `[IECLB-R123]`;
5. a pessoa clica em Responder no seu cliente de e-mail;
6. o Portal consulta a caixa IMAP;
7. a resposta é vinculada à conversa;
8. a mensagem original do formulário volta para status `nova`.

## Segurança de correlação

A mensagem recebida só é importada quando:

- o assunto contém um token válido `[IECLB-R<ID>]`; e
- o remetente é exatamente o mesmo e-mail informado no formulário original.

Isso evita vincular mensagens de terceiros a uma conversa.

## IMAP

A v0.79 reutiliza:

- usuário SMTP;
- senha SMTP criptografada pelo `MailService`.

São configurados separadamente:

- host IMAP;
- porta;
- SSL/TLS;
- validação do certificado;
- pasta (normalmente `INBOX`).

## Extensão PHP

A hospedagem precisa ter a extensão:

```text
imap
```

habilitada para a mesma versão do PHP usada pelo Portal.

## Configuração

Abra:

```text
Admin → Configurações → E-mail de Entrada
```

Teste primeiro a conexão IMAP.

## Automação

Nova tarefa:

```text
Receber respostas por e-mail
```

Padrão:

```text
a cada 5 minutos
```

Ela usa o Scheduler/cron já existente do Portal.

## Conversa

Em:

```text
Admin → Formulários → Respostas → abrir
```

a seção:

```text
Conversa por e-mail
```

mistura cronologicamente:

- mensagens enviadas pelo Portal;
- respostas recebidas da pessoa.

## Banco

Nova tabela:

```text
formulario_resposta_entradas
```
