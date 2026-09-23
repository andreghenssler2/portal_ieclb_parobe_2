# Portal IECLB Parobé — v1.1.3 R2

## Expiração automática do modo manutenção

### Problema

O painel já salvava a **Previsão de retorno** em
`maintenance_expected_end`, porém esse horário era apenas informativo.

O bloqueio público continuava baseado em `maintenance_enabled=1`, portanto o
Portal permanecia em manutenção mesmo depois do horário configurado.

### Correção

Antes de executar `enforceMaintenanceMode()`, o bootstrap agora verifica o
prazo configurado.

Quando:

- o modo manutenção está ativo; e
- existe uma previsão de retorno; e
- a data/hora atual é maior ou igual à previsão,

o Portal automaticamente:

- define `maintenance_enabled=0`;
- limpa `maintenance_enabled_at`;
- limpa `maintenance_expected_end`;
- registra `maintenance_last_auto_disabled_at`;
- registra a ação `manutencao.expirar` na auditoria quando disponível.

A primeira requisição após o horário de término já volta a servir o Portal
público normalmente.

Se não houver previsão de retorno, o modo manutenção continua ativo até ser
desligado manualmente.

### Fuso horário

A verificação ocorre depois que o bootstrap aplica o `site_timezone`, portanto
utiliza o mesmo fuso configurado pelo Portal.

### Banco

Sem migração. As configurações usam a tabela de configurações já existente.

APP_VERSION permanece 1.1.3.
