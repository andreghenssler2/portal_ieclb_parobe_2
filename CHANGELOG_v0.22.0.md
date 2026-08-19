# Portal IECLB Parobé — v0.22.0

## Backup completo

- Backup ZIP com banco de dados, `uploads/` e `theme/`.
- Seleção de inclusão de uploads e temas ao criar o backup.
- `manifest.json` com versão, data, quantidade de arquivos, tamanho e SHA-256.
- Validação de integridade de todos os arquivos antes de uma restauração.
- Restauração seletiva de banco, uploads e temas.
- Backup completo de segurança criado automaticamente antes da restauração.
- Arquivos restaurados sobrescrevem equivalentes, mas arquivos posteriores ao backup não são apagados automaticamente.
- `config/config.php` e credenciais não são incluídos/restaurados automaticamente.
- Política de retenção separada para backups do banco e backups completos.
- Rotina de Limpeza atualizada para aplicar retenção aos dois tipos de backup.
- Compatibilidade com XAMPP/HostGator quando a extensão PHP `ZipArchive` estiver disponível.
