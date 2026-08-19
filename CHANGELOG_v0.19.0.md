# Portal IECLB Parobé v0.19.0

- Editor de Temas funcional em Aparência.
- Edição restrita a PHP, CSS, JavaScript e JSON dentro de `theme/`.
- Backup automático antes de salvar e antes de restaurar.
- Restauração de backups pelo painel.
- Validação de `theme.json`.
- Validação PHP com `php -l` quando `proc_open` estiver disponível.
- Proteção contra path traversal e links simbólicos.
- Nova permissão `tema_editor.gerenciar` (Administrador por padrão).
- Pasta de backups protegida em `storage/theme-backups`.
