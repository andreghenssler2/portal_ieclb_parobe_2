# Portal IECLB Parobé — v1.2.0

## Consolidação

A v1.2.0 encerra o ciclo de atualizações incrementais da série 1.1.x.

### O que foi consolidado

- correções de publicação/agendamento e transporte do editor;
- manutenção automática/agendada;
- MP4, PDF local e Biblioteca de Mídia;
- Agenda e duplicação de eventos;
- Notícias avançadas;
- perfis completos de Comunidades;
- administração e dashboard;
- segurança;
- integridade de backups;
- fila/saúde de e-mail;
- SEO, Open Graph, dados estruturados e redirects.

### Limpeza de compatibilidade

O `bootstrap.php` deixa de tratar como opcionais serviços que já fazem parte da
base oficial desde o ciclo 0.x. Na v1.2.0 eles passam a ser dependências diretas:

- ContentPageCacheService;
- ContentAutosaveService;
- AdminAdvancedSearchService;
- AdminNotificationService;
- UserActivityService.

Isso elimina wrappers mantidos apenas para permitir transições de versões antigas.

### Bateria de testes

`tests/consolidation-v120.php` executa:

- auditoria de ambiente;
- auditoria de arquivos e classes obrigatórias;
- auditoria do schema principal e módulos recentes;
- verificação das rotas centrais;
- verificação da remoção dos wrappers de compatibilidade;
- lint recursivo dos arquivos PHP do Portal.

### Distribuição oficial

`tools/build-release-v120.php` gera:

`storage/releases/portal_ieclb_parobe_v1.2.0_oficial.zip`

O pacote gerado contém o código real da instalação consolidada, mas exclui:

- `config/config.php` com segredos;
- uploads do ambiente;
- cache e logs;
- backups;
- diretórios `_update_payload_*`;
- atualizadores/diagnósticos antigos na raiz;
- arquivos ZIP antigos.

Assim, as correções R1/R2/R3 ficam incorporadas apenas no estado final do código,
sem carregar a cadeia histórica de instaladores dentro da distribuição oficial.

APP_VERSION: **1.2.0**
