# Hotfix v0.89.0 R10 — Importação externa da Agenda

Adiciona em `Admin → Agenda` um botão **Importação externa**.

O botão abre:

`admin/eventos/importar.php`

## Formato suportado

iCalendar (`.ics`), compatível com exportações de:

- Google Calendar;
- Microsoft Outlook;
- Apple Calendar;
- outros sistemas que exportem VEVENT.

## Dados aproveitados

- título;
- descrição;
- local;
- data/hora de início;
- data/hora de término;
- UID.

O UID é usado para gerar um slug estável. Ao importar novamente o mesmo
calendário, o evento é atualizado em vez de duplicado.

## Tipos

O administrador pode escolher um tipo padrão e ativar detecção automática:

- Culto;
- Festa;
- Reunião;
- caso contrário, usa o tipo padrão (normalmente Atividade).

## Status

É possível importar como:

- Rascunho;
- Publicado.

Não há alteração de banco.
APP_VERSION permanece 0.89.0.
