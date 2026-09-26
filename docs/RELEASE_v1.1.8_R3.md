# Portal IECLB Parobé — v1.1.8 R3

## Correção: `There is no active transaction`

A v1.1.8 passou a chamar `NewsFeatureService::save()` dentro da transação
do editor de Notícias.

`NewsFeatureService::save()` chamava `ensureSchema()`, que executava
`CREATE TABLE IF NOT EXISTS`. Em MySQL/MariaDB, DDL pode encerrar
implicitamente uma transação ativa. Assim, ao chegar em `$pdo->commit()`,
o PDO informava:

`There is no active transaction`

A R3:

- mantém cache de schema por conexão PDO;
- nunca executa DDL novamente se o schema já foi preparado na requisição;
- se `ensureSchema()` for chamado dentro de uma transação sem cache,
  apenas verifica se as tabelas existem usando `information_schema`;
- não altera `APP_VERSION`;
- aceita Portal 1.1.8 ou 1.1.9.

O teste R3 abre uma transação, chama `ensureSchema()` e confirma que a
transação continua ativa.
