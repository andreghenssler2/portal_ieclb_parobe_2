# Dashboard Administrativo — v0.67.0

A página inicial do painel passa a ser montada conforme as permissões do
usuário conectado.

## Objetivos

- reduzir atalhos irrelevantes;
- destacar prioridades;
- mostrar métricas apenas dos módulos permitidos;
- aproximar ações frequentes;
- manter o administrador com visão ampla;
- entregar a perfis editoriais um painel mais focado.

## Estrutura

### Saudação e perfil

O topo exibe:

- saudação conforme o horário;
- nome do usuário;
- nome do perfil;
- acesso à Central de Pendências;
- acesso ao portal público.

### Prioridades

A Central de Pendências continua sendo a principal fonte dos itens que exigem
atenção.

### Atalhos do perfil

Os atalhos são montados por permissão. Exemplos:

- Nova notícia;
- Fila de revisão;
- Novo evento/culto;
- Nova página;
- Biblioteca de mídia;
- Moderação de comentários;
- Formulários;
- Backups;
- Diagnóstico;
- Configurações.

### Visão geral

Os cards também respeitam as permissões e podem mostrar:

- notícias publicadas;
- rascunhos;
- conteúdo em revisão;
- próximos eventos;
- comentários pendentes;
- respostas novas;
- páginas;
- mídias;
- documentos;
- usuários;
- alertas de auditoria.

### Blocos operacionais

Conforme o perfil, a tela pode exibir:

- conteúdo recente;
- próximos eventos e cultos;
- comentários pendentes;
- respostas de formulários;
- atividade administrativa.

## Banco de dados

Nenhuma alteração estrutural é necessária.

## Serviço

A lógica das consultas foi extraída de `admin/index.php` para:

`app/Services/AdminDashboardService.php`

A página administrativa fica responsável principalmente pela apresentação.
