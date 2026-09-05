# Hotfix v0.89.0 R11 — Exportação da Agenda

Adiciona em `Admin → Agenda` o botão **Exportar Agenda**.

A nova página:

`admin/eventos/exportar.php`

gera arquivos iCalendar (`.ics`) compatíveis com:

- Google Calendar;
- Outlook;
- Apple Calendar;
- Thunderbird;
- outros sistemas iCalendar.

## Filtros

É possível exportar por:

- ano;
- tipo: Culto, Festa, Atividade ou Reunião;
- status;
- comunidade.

Também existem atalhos para:

- exportar o ano atual;
- exportar todos os itens publicados.

A geração reutiliza `EventCalendarService::buildCalendarIcs()`, já existente
no Portal.

Não há alteração no banco.
APP_VERSION permanece 0.89.0.
