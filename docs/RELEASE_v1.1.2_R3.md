# Portal IECLB Parobé — v1.1.2 R3

## Correção da busca em Admin → Agenda

Corrige `SQLSTATE[HY093]: Invalid parameter number` em `admin/eventos/index.php`.

A causa era a reutilização do placeholder `:busca` três vezes no mesmo prepared statement.
A consulta passa a usar `:busca_titulo`, `:busca_local` e `:busca_resumo`, todos com o mesmo termo.

Sem migração de banco e sem alteração do APP_VERSION.
