# Portal IECLB Parobé — v1.1.10

## Administração

A v1.1.10 amplia o Dashboard administrativo com uma Central Operacional.

### Números do Portal

São exibidos, conforme as permissões do usuário:

- notícias publicadas;
- notícias aguardando publicação;
- eventos futuros;
- comunidades ativas;
- arquivos da Biblioteca de Mídia;
- usuários ativos.

### Últimos acessos

Mostra os usuários administrativos ordenados pelo campo `ultimo_login`.

### Notícias aguardando publicação

Agrupa conteúdos em rascunho, revisão, aprovados e agendados/futuros para
facilitar o acompanhamento editorial.

### Eventos próximos

Exibe os próximos eventos publicados, com comunidade/local e data.

### Mídias

Mostra:

- espaço total registrado;
- quantidade de arquivos;
- imagens;
- vídeos MP4;
- documentos.

### Alertas operacionais

Cards de status para:

- manutenção;
- cron/agendador;
- backup;
- e-mail.

Quando os serviços correspondentes existem, a Central usa diretamente
`CronHealthService`, `BackupService`, `MailDnsHealthService` e
`ProductionReadinessService`.

### Atalhos

Atalhos rápidos são filtrados pelas permissões do usuário para Notícias,
Eventos, Mídias, Backups, Tarefas, E-mail, Manutenção e Auditoria.

### Banco

Sem migração de banco.

`APP_VERSION = 1.1.10`
