# Portal IECLB Parobé — v1.1.12 R1

Corrige falsos negativos do diagnóstico e do teste da v1.1.12.

`BackupService`, `FullBackupService` e `AutomaticBackupService` são carregados
sob demanda no Portal. O diagnóstico anterior verificava `class_exists()`
antes desse carregamento e reportava três falhas indevidas.

A R1 carrega explicitamente esses serviços apenas nos scripts de validação.
Não altera os serviços de produção, o banco ou o APP_VERSION.

A ausência inicial de backups passa a ser tratada como aviso, não falha.
