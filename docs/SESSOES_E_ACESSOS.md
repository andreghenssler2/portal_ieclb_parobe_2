# v0.83.0 — Sessões e Acessos

A v0.83 adiciona gerenciamento de sessões administrativas.

## Minhas sessões

Disponível em:

```text
Admin → menu do usuário → Minhas sessões
```

Mostra:

- dispositivo/navegador;
- IP;
- início da sessão;
- último acesso;
- sessão atual;
- sessões encerradas recentemente.

O usuário pode:

- encerrar uma sessão específica;
- encerrar todas as outras sessões.

A sessão atual não pode ser encerrada acidentalmente nessa tela.

## Administração

Quem possui:

```text
seguranca.gerenciar
```

também pode abrir:

```text
Admin → Configurações → Sessões ativas
```

e visualizar os acessos administrativos de todos os usuários.

É possível encerrar:

- uma sessão específica;
- todas as sessões de um usuário.

Quando o administrador atua sobre o próprio usuário, a sessão atual é
preservada.

## Revogação

Uma sessão encerrada remotamente é invalidada na próxima requisição feita por
aquele navegador/dispositivo.

## Senha

Quando o usuário troca a própria senha em `Minha conta`, todas as outras
sessões da conta são encerradas automaticamente.

## Cookies e ID da sessão

A v0.83 reforça o início da sessão PHP com:

- `session.use_strict_mode=1`;
- somente cookies;
- `HttpOnly`;
- `SameSite=Lax`;
- `Secure` quando HTTPS é detectado, inclusive atrás de proxy com
  `X-Forwarded-Proto=https`.

Durante uma sessão autenticada, o ID PHP é regenerado a cada 15 minutos.

## Privacidade

O banco não guarda o ID bruto da sessão PHP.

Cada login recebe um token aleatório de 256 bits e o banco armazena somente:

```text
SHA-256(token)
```

## Expiração

A lista de sessões usa a mesma configuração já existente:

```text
Configurações → Segurança
→ Expirar sessão após inatividade
```

Sessões além desse prazo são marcadas como encerradas por inatividade.

## Banco

Nova tabela:

```text
user_sessions
```
