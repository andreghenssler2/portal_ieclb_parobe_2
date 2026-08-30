# Changelog — Portal IECLB Parobé

Este arquivo passa a ser o histórico consolidado do projeto a partir da
v0.66.0. Notas detalhadas de versões antigas podem permanecer arquivadas em
`docs/releases/`.






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
