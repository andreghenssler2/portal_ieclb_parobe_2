# Changelog — Portal IECLB Parobé

## v0.95.0 — SPF, DKIM e DMARC

- adiciona diagnóstico DNS de e-mail;
- verifica SPF, DKIM, DMARC e MX;
- adiciona seletor DKIM configurável;
- adiciona teste CLI `tests/email-dns.php`;
- não altera registros DNS automaticamente.


## v0.94.0 — Saúde do Cron e Tarefas Agendadas

- adiciona heartbeat do Cron Job em `storage/cron/last-run.json`;
- diferencia Cron Job real de execuções manuais `--task` e `--all`;
- adiciona `php cron.php --health` sem executar tarefas;
- adiciona Admin → Ferramentas → Tarefas Agendadas → Saúde do cron;
- mostra tarefas vencidas, erros consecutivos e execuções órfãs;
- adiciona `tests/scheduler.php`;
- nenhuma nova tabela é criada.

## v0.93.0 — Teste seguro de restaurabilidade

- adiciona teste de restaurabilidade para backups do banco;
- valida SHA-256, tabelas e estrutura do dump SQL;
- adiciona teste de integridade do backup completo;
- revalida arquivos do manifest.json por tamanho e SHA-256;
- adiciona Ferramentas → Backups → Teste de restauração;
- adiciona `tests/backups.php`;
- o teste não restaura nem sobrescreve o Portal ativo.

## v0.92.0 — Auditoria de Perfis e Permissões

- adiciona Auditoria de Permissões no Admin;
- adiciona matriz Perfil × Permissão;
- exporta a matriz em CSV;
- audita integridade das tabelas de perfis/permissões;
- detecta vínculos órfãos ou duplicados;
- compara permissões usadas no código com as cadastradas no banco;
- revisa a proteção estática das páginas administrativas;
- adiciona `tests/permissions.php`;
- não altera automaticamente as permissões existentes.

## v0.91.0 — Cookies e Consentimento

- adiciona Central pública de Consentimento de Cookies;
- categorias Necessários, Estatísticas e Marketing;
- adiciona Aceitar todos, Recusar opcionais e Personalizar;
- adiciona Preferências de cookies no rodapé;
- condiciona Google Analytics 4 ao consentimento de Estatísticas;
- condiciona Google Tag Manager à categoria configurada;
- permite aumentar a versão do consentimento para solicitar nova escolha;
- adiciona Configurações → Cookies no Admin.

## v0.90.0 — Consolidação

- consolida os hotfixes finais da v0.89.0;
- padroniza seleção de imagens e documentos por modal;
- consolida Agenda com Cultos, Festas, Atividades e Reuniões;
- adiciona importação/exportação/assinatura iCalendar;
- consolida controles e configuração do Sitemap;
- adiciona Google Tag Manager administrável;
- completa a CSP para Google Tag Manager e Google Analytics 4.

Este arquivo passa a ser o histórico consolidado do projeto a partir da
v0.66.0. Notas detalhadas de versões antigas podem permanecer arquivadas em
`docs/releases/`.

























## 0.89.0 — Backups automáticos

- Backup automático diário do banco.
- Backup completo automático semanal opcional.
- Integração com o Scheduler.
- Monitoramento em Ferramentas > Backups.
- Reutilização das retenções e opções existentes.
- Sem nova tabela no banco.
## 0.88.0 — Performance e cache por conteúdo

- Cache individual para Notícias e Páginas públicas.
- Bypass automático para formulários/CSRF.
- ETag e Last-Modified.
- Suporte a resposta HTTP 304.
- Configuração e TTL próprios em Performance.
- Invalidação automática do grupo `content-page`.
- Sem nova tabela no banco.
## 0.87.0 — Histórico de atividades por usuário

- Linha do tempo individual de atividade.
- Unifica Auditoria e Sessões.
- Filtros por categoria, nível e período.
- “Minha atividade” no menu da conta.
- Atalho no editor de usuários e na Auditoria.
- Sem nova tabela no banco.
## 0.86.0 — Busca Administrativa Avançada

- Nova tela de busca com filtros.
- Filtros por módulo, status e período.
- Pesquisa em conteúdo e metadados.
- Ordenação configurável.
- Integração com Ctrl+K.
## 0.85.0 — Central de Notificações

- Central de notificações individual no Admin.
- Badge de notificações não lidas.
- Integração com as pendências do Portal.
- Histórico de lidas e resolvidas.
- Marcar como lida/não lida.
- Nova tabela `admin_notifications`.
## 0.84.0 — Autosave e recuperação de rascunhos

- Autosave nos editores de Notícias e Páginas.
- Recuperação após fechamento/interrupção.
- Indicador visual do autosave.
- Recuperação do TinyMCE, formulário e blocos.
- Rascunho separado do conteúdo definitivo.
- Nova tabela `content_autosaves`.
## 0.83.0 — Sessões e Acessos

- Controle individual das sessões administrativas.
- Nova tela `Minhas sessões`.
- Nova tela `Configurações > Sessões ativas`.
- Revogação remota de acesso.
- Troca de senha encerra outras sessões.
- Cookie/ID da sessão PHP reforçados.
- Nova tabela `user_sessions`.
## 0.82.0 — Páginas

- Editor de Páginas com o mesmo layout visual do editor de Notícias.
- Nova configuração global da imagem de capa das Páginas em Mídia.
- Padrão: não mostrar.
- Resumo não aparece na leitura pública.
- Nova coluna `paginas.exibir_imagem_capa`.
## 0.81.0 — Padrão da imagem de capa

- Nova opção em `Configurações > Mídia`.
- Define se novas Notícias/Posts mostram a capa na leitura.
- Padrão inicial: não mostrar.
- Cada notícia continua podendo sobrescrever a opção.
- `posts.exibir_imagem_capa` passa a ter `DEFAULT 0`.
## 0.80.0 — Leitura das Notícias

- O resumo não aparece mais no corpo da notícia individual.
- O resumo continua em cards, busca e SEO.
- Novo controle "Imagem de capa na leitura".
- Cada notícia escolhe se a capa aparece ao abrir o conteúdo.
- A imagem continua disponível para cards, destaques e Open Graph.
- Nova coluna `posts.exibir_imagem_capa`.
## 0.79.0 — Respostas recebidas por e-mail

- Respostas dos contatos voltam ao Portal via IMAP.
- E-mails enviados recebem token `[IECLB-R<ID>]`.
- Remetente é validado contra o e-mail original do formulário.
- Nova tabela `formulario_resposta_entradas`.
- Nova tela `Admin > Configurações > E-mail de Entrada`.
- Teste de conexão e sincronização manual.
- Nova tarefa agendada a cada 5 minutos.
- Conversa por e-mail une mensagens enviadas e recebidas.
- Nova resposta recebida coloca o item novamente como `nova`.
## 0.78.0 — Responder mensagens dos formulários

- Resposta por e-mail diretamente na tela da mensagem recebida.
- Destinatário detectado automaticamente pelo campo de e-mail do formulário.
- Envio pelo `MailService`/SMTP do Portal.
- Histórico de respostas manuais.
- Nova tabela `formulario_resposta_replicas`.
- Registra administrador, assunto, mensagem, destinatário e status.
- CSRF e validações preservados.
## 0.77.0 — Bloco Importar Formulário

- Novo bloco dinâmico `portal_form_embed`.
- Formulários publicados podem ser incorporados em Páginas e Notícias.
- Respostas e notificações continuam usando o módulo Formulários.
- Título, descrição, visual e texto do botão são configuráveis.
- CSRF, honeypot, throttle e validações preservados.
- Nenhuma alteração estrutural no banco.
## 0.76.0 — Relatórios CSP

- Novo `CspReportService`.
- Endpoint `csp-report.php`.
- `report-uri` integrado à CSP.
- Nova tabela `security_csp_reports`.
- Deduplicação e contador de ocorrências.
- Retenção configurável.
- Nenhum IP, query string ou `script-sample` armazenado.
- Nova tela `Admin > Configurações > Relatórios CSP`.
## 0.75.0 — Cabeçalhos HTTP e CSP

- Novo `SecurityHeadersService`.
- `X-Content-Type-Options`.
- `X-Frame-Options`.
- `Referrer-Policy`.
- `Permissions-Policy`.
- `Cross-Origin-Opener-Policy`.
- Content Security Policy em `Report-Only` por padrão.
- CSP pode ser desativada ou promovida para `Enforce`.
- HSTS opcional e somente em HTTPS.
- Nova tela `Admin > Configurações > Cabeçalhos HTTP`.
- Nenhuma alteração estrutural no banco.
## 0.74.0 — Monitoramento automático da mídia

- Verificação automática diária da integridade da Biblioteca.
- Limpeza segura semanal de variantes/derivados órfãos.
- Nova tabela `midia_integridade_relatorios`.
- Histórico de até 120 execuções.
- Nova tela `Admin > Mídia > Monitoramento`.
- Integração com `SchedulerService`.
- Integração com Central de Pendências.
- Arquivos originais órfãos continuam sem exclusão automática.
## 0.73.0 — Integridade do armazenamento de mídia

- Diagnóstico entre banco e pasta `uploads/`.
- Detecta originais ausentes.
- Detecta divergência de tamanho.
- Detecta variantes ausentes.
- Detecta arquivos físicos órfãos.
- Limpeza segura de registros/derivados da v0.72.
- Arquivos originais órfãos não são apagados automaticamente.
- Nenhuma alteração estrutural no banco.
## 0.72.0 — Otimização automática de imagens

- Redimensionamento opcional do original.
- Geração de WebP.
- Miniaturas WebP para a Biblioteca.
- Configuração de qualidade e dimensões.
- Processamento em lotes da biblioteca existente.
- Reprocessamento individual.
- Nova tabela `midia_variantes`.
- Variantes removidas junto com a mídia.
- R3: integração robusta com o `MediaService` independente de CRLF/formatação.
## 0.71.0 — Uso seguro da Biblioteca de Mídia

- Novo `MediaUsageService`.
- Tela de detalhes da mídia reformulada.
- Lista notícias, páginas, eventos e documentos que utilizam a mídia.
- Detecta FOREIGN KEYs adicionais para `midias.id`.
- Verifica existência e tamanho do arquivo físico.
- `MediaService::delete()` bloqueia exclusão de mídia em uso.
- Proteção vale para exclusão individual e em massa.
- Nenhuma alteração no banco de dados.
## 0.70.0 — Biblioteca de Mídia 2.0

- Paginação com 24 itens por página.
- Pesquisa por título, arquivo, texto alternativo e extensão.
- Filtros de imagens/documentos com contadores.
- Ordenação por data, nome e tamanho.
- Visualização em grade ou lista.
- Seleção da página atual e exclusão em massa.
- Auditoria preservada nas exclusões.
- Nenhuma alteração no banco de dados.
## 0.69.0 — Favoritos e acessos recentes

- Novo botão `Atalhos` na barra superior do Admin.
- Favoritos por usuário.
- Histórico de páginas administrativas recentes.
- Quantidade de acessos e último acesso por rota.
- Até 60 itens recentes não favoritos por usuário.
- Favoritos não são podados automaticamente.
- Gravações protegidas por CSRF.
- Nova tabela `usuario_admin_atalhos`.
## 0.68.0 — Busca Global Administrativa

- Busca rápida no painel com `Ctrl+K` / `Command+K`.
- Resultados agrupados por módulo.
- Navegação com setas e Enter.
- Pesquisa respeita permissões do usuário.
- Notícias, páginas, eventos, documentos, mídia, usuários e comunidades.
- Endpoint JSON autenticado e sem cache.
- Nenhuma alteração no banco de dados.
## 0.67.0 — Dashboard personalizado por perfil

- Dashboard reformulado para mostrar somente módulos permitidos ao usuário.
- Nova saudação com nome e perfil.
- Prioridades integradas à Central de Pendências.
- Atalhos rápidos montados conforme permissões.
- Indicadores por perfil.
- Conteúdo recente, agenda, comentários, formulários e auditoria aparecem
  somente quando o usuário possui acesso.
- Consultas extraídas para `AdminDashboardService`.
- Nenhuma alteração no banco de dados.
## 0.66.0 — Organização do repositório

- README completamente atualizado.
- Removidas do README credenciais administrativas padrão.
- Criado `CHANGELOG.md` consolidado.
- Criada documentação de arquitetura, atualização e organização do Git.
- `.gitignore` reorganizado e documentado.
- Atualizado `config/config.example.php`.
- Arquivos históricos `CHANGELOG_v*`, `LEIA-ME_v*` e `MANIFEST_v*` podem ser
  arquivados automaticamente em `docs/releases/`.
- Limpeza segura de duplicatas antigas em `admin/admin/documentos` e
  `admin/app/Services/DocumentService.php` quando forem byte a byte idênticas
  às cópias canônicas.
- Nenhuma alteração no banco de dados.

## 0.65.0 — Qualidade automática

- Criado `tests/run.php`.
- GitHub Actions em PHP 8.2 e 8.3.
- Validação de sintaxe, estrutura, Composer, CSS consolidado e componentes
  críticos antes de deploy.

## 0.64.0 — CSS administrativo consolidado

- Consolidação dos CSS administrativos em `public/css/admin-v64.css`.
- Preservação dos CSS anteriores para rollback.
- Correção R2 para resíduos `">` no topo do painel.

## 0.63.0 — Central de Diagnóstico

- Nova visão operacional para produção.
- Disco, banco, uploads, cache, backups, tarefas, SMTP e alertas reunidos.
- Integração com a Saúde do Portal.

## 0.62.0 — Central de Pendências

- Central administrativa de itens que exigem atenção.
- Sino com contador.
- Integração com revisão, comentários, formulários, agenda e auditoria.

## 0.61.0 — Fluxo editorial

- Rascunho → revisão → ajustes/aprovação → publicação.
- Novas permissões para revisar e publicar.
- Hash da versão aprovada para impedir publicação de conteúdo diferente do
  revisado.

## 0.60.0 — Autenticação em dois fatores

- 2FA TOTP por usuário.
- Códigos de recuperação.
- Integração ao login e à Minha Conta.
- Auditoria das ações de 2FA.

## 0.59 e anteriores

O Portal evoluiu incrementalmente com:

- notícias, páginas e eventos;
- categorias, tags e menus;
- mídia e documentos;
- usuários e permissões;
- formulários, newsletter e comentários;
- SEO, sitemap, cache e backups;
- temas, widgets e blocos de conteúdo;
- importação WordPress;
- analytics, busca e tarefas agendadas.

Consulte `docs/releases/` e o histórico do Git para detalhes das versões
anteriores.
