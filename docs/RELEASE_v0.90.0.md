# Portal IECLB Parobé — v0.90.0

A v0.90.0 é uma versão de consolidação da base v0.89.0 e dos hotfixes aplicados
durante a revisão final do Portal.

## Consolida

- padrão de seleção de imagens por modal no Admin;
- modal de documentos;
- Agenda com os tipos Culto, Festa, Atividade e Reunião;
- importação externa da Agenda em `.ics`;
- exportação administrativa em `.ics`;
- exportação pública e assinatura por `agenda.ics`;
- controles Ativo/Inativo do Sitemap;
- seleção das páginas de `/geral.sitemaps.xml`;
- correção para o sitemap público respeitar essas opções;
- Google Tag Manager administrável;
- CSP compatível com Google Tag Manager e Google Analytics 4.

## Não altera os dados importados da Agenda

Os 476 registros do Calendário 2026 são dados da instalação e não fazem parte
do pacote de código v0.90.0. O atualizador não remove nem recria esses registros.

## Estratégia do atualizador

O atualizador detecta os módulos já instalados e os pula. Se encontrar um
recurso ausente, aplica somente o módulo necessário. A versão é alterada para
`0.90.0` apenas depois das verificações finais.

## Próxima etapa

Após a v0.90.0 consolidada, a revisão para a v1.0.0 deve se concentrar em:

- consentimento/cookies;
- testes completos de permissões;
- restauração real de backups;
- cron/tarefas;
- e-mail;
- performance;
- mobile;
- acessibilidade;
- páginas de erro;
- limpeza de instaladores e arquivos temporários.
