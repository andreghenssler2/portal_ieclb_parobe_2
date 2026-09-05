# Portal IECLB Parobé — v0.93.0

## Teste seguro de restaurabilidade

A v0.93.0 adiciona uma etapa de validação para os mecanismos de backup e
restauração já existentes no Portal.

### Objetivo

Confirmar que os backups gerados possuem estrutura, integridade e conteúdo
compatíveis com uma futura restauração, sem executar uma restauração no banco
ou nos arquivos ativos durante o teste.

### Banco

O teste:

- cria um backup real com `BackupService`;
- confere tamanho e SHA-256;
- compara as tabelas atuais com as tabelas presentes no dump;
- verifica `DROP TABLE`, `CREATE TABLE` e blocos `INSERT`;
- confirma o ciclo `FOREIGN_KEY_CHECKS=0/1`;
- confirma que o método de restauração está disponível.

### Backup completo

Quando `ZipArchive` está disponível, o teste:

- cria um backup completo real;
- reabre o `manifest.json`;
- valida novamente cada arquivo listado no manifesto;
- compara tamanho e SHA-256 de cada entrada;
- confirma a presença do arquivo do banco;
- confirma que o método de restauração completa está disponível.

### Admin

Novo caminho:

`Admin → Ferramentas → Backups → Teste de restauração`

O teste completo pode incluir temas e, opcionalmente, uploads.

Uploads ficam desmarcados por padrão para evitar gerar um ZIP grande durante a
revisão.

### CLI

`php tests/backups.php`

O teste CLI cria backup do banco e, quando possível, backup completo com temas.
Uploads não são incluídos no teste CLI.

### Importante

Os arquivos de teste ficam em `storage/backups` e podem ser apagados depois pela
Central de Backups.

Nenhuma restauração é executada pelo teste.
