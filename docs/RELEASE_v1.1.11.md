# Portal IECLB Parobé — v1.1.11

## Segurança

A v1.1.11 consolida em uma **Central de Segurança** os mecanismos que o Portal
já possuía e reforça a visibilidade administrativa.

### Histórico de login

A Central mostra os eventos de login registrados na Auditoria:

- login realizado;
- falha de login;
- login bloqueado;
- solicitação/falha de 2FA;
- logout.

A retenção segue a configuração da Auditoria.

### Bloqueio temporário

O Portal continua usando `login_tentativas` e as configurações:

- `security_max_login_attempts`;
- `security_lockout_minutes`.

A Central mostra e-mails/IPs que atingiram o limite da janela atual.

### Sessões abertas

A Central lista sessões administrativas ativas com:

- usuário;
- dispositivo;
- IP;
- último acesso;
- indicação da sessão atual.

É possível encerrar remotamente uma sessão ou as sessões de um usuário.
A própria sessão atual é protegida.

### 2FA opcional

A autenticação TOTP existente permanece opcional e compatível com aplicativos
autenticadores. A Central mostra:

- quantidade de contas com 2FA;
- cobertura percentual;
- contas ativas ainda sem 2FA;
- link direto para configurar o próprio 2FA.

### Auditoria reforçada

A Central reúne os últimos eventos sensíveis:

- login;
- 2FA;
- sessões;
- acesso negado;
- configurações de segurança.

Encerramentos remotos feitos pela Central também geram registros de auditoria.

### Banco

A v1.1.11 não introduz novas tabelas. O instalador apenas garante, de forma
idempotente, a estrutura de segurança já utilizada pelo Portal.

`APP_VERSION = 1.1.11`
