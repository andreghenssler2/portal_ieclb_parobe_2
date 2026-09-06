# Portal IECLB Parobé — v1.1.1

## Snapshots automáticos da Saúde do Portal

A v1.1.1 automatiza o histórico criado na v1.1.0.

### Nova tarefa do agendador

Slug:

`registrar_saude_portal`

Configuração padrão:

- ativa;
- execução diária;
- intervalo de 1440 minutos;
- utiliza o cron já existente do Portal.

Cada execução grava um snapshot em:

`storage/health/portal/`

### Alertas administrativos

A automação compara o snapshot novo com o snapshot anterior.

Uma notificação administrativa é criada para os administradores ativos quando
ocorre pelo menos uma destas situações:

- queda de pontuação;
- novo aviso;
- novo bloqueador.

O alerta aponta para:

`Admin → Ferramentas → Saúde do Portal`

Se o estado permanece igual, nenhum novo alerta é criado.

### Banco de dados

Não existe nova tabela nem migração.

Apenas uma nova tarefa é registrada na tabela já existente
`tarefas_agendadas` pelo mecanismo normal do `SchedulerService`.

### Teste manual

Para executar somente a nova tarefa:

```bash
php cron.php --task=registrar_saude_portal
```

Depois:

```bash
php diagnosticar_v1.1.1.php
php tests/portal-health-automation.php
```
