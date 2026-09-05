# Hotfix v0.89.0 R13 — Agenda por link

Além do download `.ics`, a Agenda pública passa a oferecer uma URL de
assinatura.

## URL padrão

`agenda.ics`

Exemplos:

- todos os itens publicados:
  `agenda.ics`
- somente 2026:
  `agenda.ics?ano=2026`
- somente cultos:
  `agenda.ics?tipo=culto`
- cultos de 2026:
  `agenda.ics?ano=2026&tipo=culto`
- filtros da Agenda pública:
  também aceita `mes`, `comunidade`, `categoria` e `q`.

## Assinatura

A página pública `agenda-exportar.php` mostra:

- link pronto;
- botão Copiar;
- botão `webcal://` para abrir no aplicativo;
- botão para testar o feed;
- download tradicional `.ics`.

Ao assinar por link, o aplicativo de calendário pode consultar novamente o
Portal e receber eventos novos/alterados conforme a política de atualização
do próprio aplicativo.

## Segurança

O feed sempre aplica:

`status='publicado'`

Não expõe rascunhos, cancelados ou arquivados.

## Rota

`.htaccess`:

`agenda.ics` → `agenda-feed.php`

Sem alteração de banco.
APP_VERSION permanece 0.89.0.
