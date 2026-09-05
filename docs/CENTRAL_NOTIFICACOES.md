# v0.85.0 — Central de Notificações do Admin

A v0.85 cria uma caixa de notificações individual para cada usuário do painel.

## Central

Nova tela:

```text
Admin → Notificações
```

Ela oferece:

- notificações não lidas;
- notificações ativas;
- histórico recente;
- marcação individual como lida/não lida;
- marcar todas como lidas;
- abrir o módulo relacionado;
- limpar notificações antigas já resolvidas.

## Integração com Pendências

A Central de Pendências continua existindo.

A nova Central de Notificações utiliza as mesmas fontes operacionais para
avisar o usuário quando algo merece atenção, respeitando as permissões do
perfil:

- notícias em revisão;
- notícias com ajustes solicitados;
- notícias aprovadas;
- notícias agendadas;
- comentários pendentes;
- novas respostas de formulários;
- alertas de segurança;
- alertas de integridade da Biblioteca de Mídia.

Quando a quantidade de uma pendência aumenta, a notificação volta a ficar
como **não lida**.

Quando a pendência deixa de existir, a notificação é marcada automaticamente
como **resolvida**.

## Barra superior

A barra superior passa a ter um botão próprio de notificações com badge da
quantidade não lida.

A antiga Central de Pendências permanece disponível separadamente.

## Persistência

Cada usuário possui sua própria leitura.

Marcar uma notificação como lida não afeta os outros usuários.

## API interna

O serviço também oferece:

```php
AdminNotificationService::notify(...)
```

para que módulos futuros possam criar notificações direcionadas a um usuário
específico sem depender da Central de Pendências.

## Banco

Nova tabela:

```text
admin_notifications
```

## Segurança

A tela exige login e todas as alterações utilizam CSRF.

A sincronização respeita `Auth::can()` e as permissões já existentes do Portal.
