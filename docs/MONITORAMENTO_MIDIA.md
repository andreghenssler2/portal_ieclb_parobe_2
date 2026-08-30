# Monitoramento automático da mídia — v0.74.0

A v0.74.0 transforma o diagnóstico manual da v0.73 em uma rotina operacional
do Portal.

## Novas tarefas

### Verificar integridade da mídia

Padrão:

```text
1440 minutos (1 dia)
```

Executa diagnóstico sem apagar arquivos.

### Limpeza segura da mídia

Padrão:

```text
10080 minutos (7 dias)
```

Pode remover somente:

- registros de variantes sem arquivo físico;
- `.thumb.webp` órfãos;
- `.optimized.webp` órfãos.

Depois da limpeza executa novo diagnóstico e salva o estado resultante.

## Histórico

Nova tabela:

`midia_integridade_relatorios`

Armazena até 120 relatórios recentes com:

- origem da execução;
- status;
- arquivos analisados;
- originais ausentes;
- tamanhos divergentes;
- variantes ausentes;
- arquivos órfãos;
- derivados órfãos;
- quantidade limpa;
- bytes liberados.

## Central de Pendências

Quando o último relatório contém itens que exigem revisão administrativa, a
Central de Pendências passa a mostrar um item para a Biblioteca de Mídia.

## Tela

`Admin > Mídia > Monitoramento`

Permite:

- ver o último estado;
- consultar histórico;
- executar verificação manual;
- executar limpeza segura + verificação;
- abrir diagnóstico detalhado da v0.73;
- abrir Tarefas Agendadas.

## Cron

A v0.74 utiliza o `SchedulerService` existente.

Portanto o `cron.php` já usado pelo Portal continua sendo o ponto único de
execução automática.
