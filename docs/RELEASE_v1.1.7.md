# Portal IECLB Parobé — v1.1.7

## Agenda aprimorada

A v1.1.7 concentra melhorias na Agenda pública e na administração.

### Portal público

- visualização mensal mais amigável no celular;
- dias sem eventos são ocultados no modo móvel;
- eventos aparecem em cartões verticais no celular;
- botão **Imprimir agenda**;
- CSS específico de impressão;
- legenda visual para Culto, Festa, Atividade e Reunião;
- botão **Google Agenda** na listagem;
- botão **Google Agenda** na página individual do evento;
- `.ics` continua disponível normalmente.

### Administração

- botão **Duplicar** na lista de eventos;
- a cópia é criada como **Rascunho** e aberta para edição;
- ao criar um novo evento é possível gerar recorrências:
  - semanal;
  - mensal;
  - anual;
- quantidade limitada a 52 ocorrências;
- as ocorrências são criadas como eventos independentes para que possam ser
  editadas individualmente depois.

### Banco

Sem migração de banco.

`APP_VERSION = 1.1.7`
