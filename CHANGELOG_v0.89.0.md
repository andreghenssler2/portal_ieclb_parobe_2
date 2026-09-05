# Portal IECLB Parobé v0.89.0

## Backups automáticos

- Backup automático diário do banco.
- Backup completo automático semanal disponível, desativado por padrão.
- Integração com o Scheduler existente.
- Reutiliza BackupService e FullBackupService.
- Respeita as políticas de retenção existentes.
- Respeita opções de uploads/temas do backup completo.
- Status dos backups automáticos em Ferramentas > Backups.
- Link direto para Tarefas agendadas.
- ZipArchive ausente gera tarefa ignorada, não erro global.
- Sem nova tabela no banco.
