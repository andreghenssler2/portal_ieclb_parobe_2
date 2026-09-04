# Responder respostas de formulários — v0.78.0

A v0.78.0 adiciona resposta manual por e-mail diretamente na tela de uma
mensagem recebida pelo Portal.

## Onde usar

Abra:

```text
Admin → Formulários → Respostas → abrir uma resposta
```

Abaixo dos dados enviados pelo visitante aparece:

```text
Responder por e-mail
```

## Destinatário

O Portal não permite digitar um destinatário arbitrário nessa tela.

O e-mail é obtido automaticamente usando:

1. o campo configurado em `campo_email_resposta_id`; ou
2. o primeiro campo ativo do tipo `email`.

Isso reduz o risco de responder para o endereço errado.

## Envio

O administrador informa:

- assunto;
- mensagem.

Ao clicar em:

```text
Enviar resposta
```

o Portal utiliza o `MailService` e as configurações SMTP já existentes.

## Histórico

Cada tentativa é registrada em:

```text
formulario_resposta_replicas
```

A própria tela mostra:

- data;
- destinatário;
- assunto;
- mensagem;
- administrador que respondeu;
- status enviado/erro.

## Notificações

Envios concluídos também geram um registro do tipo:

```text
resposta_manual
```

em `formulario_notificacoes`, quando essa tabela estiver disponível.

## Segurança

- exige permissão `formularios.gerenciar`;
- exige CSRF;
- destinatário é detectado automaticamente;
- valida e-mail;
- assunto limitado a 190 caracteres;
- mensagem limitada a 20.000 caracteres;
- conteúdo do e-mail é escapado antes de gerar HTML;
- utiliza o SMTP central do Portal.

## Banco

Nova tabela:

```text
formulario_resposta_replicas
```
