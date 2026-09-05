# v0.87.0 — Histórico unificado de atividades por usuário

A v0.87 cria uma linha do tempo individual para cada usuário administrativo.

## Onde acessar

Pelo menu da conta:

```text
Minha atividade
```

ou, para administradores/gestores:

```text
Usuários → Editar usuário → Histórico de atividade
```

A Auditoria também ganha atalho para a visão por usuário.

## Fontes unificadas

A linha do tempo consulta diretamente:

1. `logs` — auditoria administrativa;
2. `user_sessions` — início e encerramento das sessões da v0.83.

Nenhum evento é duplicado em uma nova tabela. A Auditoria permanece sendo a
fonte oficial dos registros administrativos.

## Informações

A tela mostra:

- data e hora;
- ação;
- categoria;
- nível;
- detalhes;
- entidade e ID;
- rota;
- IP;
- request ID;
- navegador/user agent;
- fonte do evento.

Para sessões também mostra:

- início da sessão;
- encerramento;
- dispositivo;
- IP;
- motivo do encerramento.

## Resumo

Para cada usuário há indicadores de:

- eventos hoje;
- eventos nos últimos 30 dias;
- alertas nos últimos 30 dias;
- críticos nos últimos 30 dias;
- sessões ativas;
- total de logs;
- última atividade.

## Filtros

- busca textual;
- categoria;
- nível;
- data inicial;
- data final.

Categorias automáticas:

- Conteúdo;
- Conta e usuários;
- Segurança e sessões;
- Configurações;
- Mídia e arquivos;
- Formulários;
- Sistema.

## Permissões

Todo usuário autenticado pode abrir **a própria atividade**.

Para visualizar outro usuário é necessário:

- `auditoria.visualizar`; ou
- `usuarios.gerenciar`; ou
- ser Administrador.

## Banco

A v0.87 não cria nova tabela.
