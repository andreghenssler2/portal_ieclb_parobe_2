# Portal IECLB Parobé — v1.2.0 R6

## Dashboard administrativo

A R6 limpa a duplicação visual do Dashboard.

### Removido

O bloco antigo **Visão geral** foi retirado porque repetia indicadores já
apresentados na **Central operacional**, que é a versão mais nova do painel.

### Mantido

A **Central operacional** permanece como painel principal de indicadores.

### Layout

Os blocos abaixo passam a ocupar a largura inteira disponível:

- **Próximos eventos**;
- **Conteúdo recente**.

### Saúde

O botão de saúde da Central operacional passa a apontar diretamente para:

`admin/ferramentas/saude-central.php`

em vez do endereço antigo de diagnóstico.

Não há alteração de banco.

`APP_VERSION` permanece `1.2.0`.
