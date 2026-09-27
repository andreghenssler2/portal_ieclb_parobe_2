# Portal IECLB Parobé — v1.1.12

## Backup e integridade

A v1.1.12 cria uma central específica em:

`admin/ferramentas/backup-integridade.php`

### Backup automático agendado

A tela permite ativar/desativar e ajustar o intervalo das tarefas:

- `backup_banco_automatico`;
- `backup_completo_automatico`;
- `backup_integridade_automatico`.

O backup do banco permanece recomendado diariamente.
O backup completo depende de `ZipArchive`.

### Retenção configurável

A mesma central permite definir:

- quantidade de backups do banco;
- quantidade de backups completos;
- número de horas para considerar o backup antigo.

Ao reduzir a retenção, os arquivos excedentes são removidos imediatamente.

### Download pelo painel

Os backups mais recentes aparecem com botão **Baixar** e reutilizam o
endpoint protegido existente `backup-download.php`, que exige a permissão
`backups.gerenciar`.

### Aviso de backup antigo

A configuração `backup_stale_hours` usa 36 horas como padrão.
A central destaca em amarelo quando o backup mais recente do banco ultrapassa
esse limite e em vermelho quando nenhum backup existe.

### Teste automático de integridade

A nova tarefa `backup_integridade_automatico` roda diariamente por padrão.

O teste:

- não restaura o banco;
- não sobrescreve o Portal;
- verifica tamanho e SHA-256;
- lê integralmente SQL/GZIP;
- confirma `CREATE TABLE`;
- confirma o ciclo `FOREIGN_KEY_CHECKS`;
- abre o backup completo;
- valida `manifest.json`;
- confere tamanho e SHA-256 dos arquivos listados no manifesto;
- confirma que o arquivo do banco existe dentro do ZIP.

O último resultado é salvo em:

`storage/backups/integrity-last.json`

Essa pasta já é protegida pelo mecanismo de backups do Portal.

### Banco

Não há novas tabelas. A tarefa é registrada pelo `SchedulerService::ensureRegistry()`.

`APP_VERSION = 1.1.12`
