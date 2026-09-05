# v0.89.0 — Backups Automáticos

A v0.89 conecta o sistema de backups já existente ao Agendador do Portal.

## O que já existia

O Portal já possuía:

- backup manual do banco;
- backup completo em ZIP;
- restauração com cópia de segurança;
- SHA-256;
- política de retenção;
- download;
- Agendador de tarefas.

A v0.89 não duplica esse sistema. Ela automatiza o que já existe.

## Novas tarefas

### Backup automático do banco

Slug:

```text
backup_banco_automatico
```

Padrão:

```text
Ativo
```

Intervalo:

```text
1440 minutos (1 dia)
```

O backup é gravado em:

```text
storage/backups/
```

e utiliza a retenção configurada em:

```text
Ferramentas → Backups
```

### Backup completo automático

Slug:

```text
backup_completo_automatico
```

Padrão:

```text
Desativado
```

Intervalo sugerido:

```text
10080 minutos (7 dias)
```

Ele fica desativado por padrão porque pode consumir muito espaço, sobretudo
quando inclui uploads.

Pode ser ativado em:

```text
Ferramentas → Tarefas agendadas
```

## Backup completo

Quando ativado, reutiliza as opções já salvas no Portal:

- incluir uploads;
- incluir temas;
- retenção de backups completos.

Se `ZipArchive` não estiver disponível, a tarefa é marcada como ignorada em vez
de derrubar o Agendador.

## Monitoramento

A página:

```text
Ferramentas → Backups
```

passa a mostrar:

- tarefa ativa/inativa;
- intervalo;
- próxima execução;
- última execução;
- último status;
- último arquivo automático;
- tamanho do último arquivo.

## Cron

As tarefas automáticas dependem do mesmo `cron.php` que já executa as demais
tarefas do Scheduler.

## Segurança

- não inclui `config/config.php` no backup completo;
- mantém a política atual de SHA-256;
- mantém os backups de segurança antes de restaurações;
- usa a retenção já configurada;
- não cria tabela nova.

## Banco

A v0.89 não cria nova tabela.

As novas tarefas são registradas em `tarefas_agendadas`, tabela já existente.
