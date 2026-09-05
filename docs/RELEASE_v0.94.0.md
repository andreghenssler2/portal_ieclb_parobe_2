# Portal IECLB Parobé — v0.94.0

## Saúde do Cron e Tarefas Agendadas

A v0.94.0 adiciona uma camada operacional para confirmar se o Cron Job da
hospedagem está realmente chamando o agendador do Portal.

### Heartbeat

Quando `cron.php` é executado no modo normal, sem `--task`, `--all` ou
`--health`, o Portal grava:

`storage/cron/last-run.json`

O arquivo guarda apenas informações operacionais: data/hora, PID, versão do PHP,
SAPI e versão do Portal.

### Por que isso é necessário

O histórico de tarefas registra apenas tarefas que efetivamente foram executadas.
Quando nenhuma tarefa está vencida, antes não havia uma forma simples de saber se
o Cron Job havia sido chamado.

O heartbeat resolve isso sem criar uma nova tabela.

### Admin

Novo caminho:

`Admin → Ferramentas → Tarefas Agendadas → Saúde do cron`

A tela mostra:

- estado geral;
- idade do último heartbeat;
- tarefas ativas;
- tarefas vencidas;
- erros consecutivos;
- erros nas últimas 24 horas;
- execuções marcadas como `executando` há mais de 60 minutos;
- última tarefa originada pelo cron;
- capacidade de gravar o heartbeat;
- comando recomendado para cPanel/HostGator.

### CLI

Saúde sem executar tarefas:

`php cron.php --health`

Teste estrutural:

`php tests/scheduler.php`

### Segurança

`cron.php` continua disponível somente por linha de comando.

`--health` não executa tarefas e não atualiza o heartbeat.

Execuções manuais com `--task` ou `--all` também não contam como heartbeat do
Cron Job real.


## Compatibilidade R2

O instalador passa a localizar estruturalmente os pontos de integração em
`cron.php`, sem depender de indentação ou quebra de linha específica.
