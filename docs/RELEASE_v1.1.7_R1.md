# Portal IECLB Parobé — v1.1.7 R1

## Reparo do instalador da Agenda

O instalador inicial da v1.1.7 parava em:

`EventCalendarService.php: filterSql não encontrado.`

O método `filterSql()` existe, mas o instalador usava uma âncora multilinha
com quebra `LF`. No XAMPP/Windows os arquivos podem usar `CRLF`.

A R1 normaliza apenas a cópia em memória que será atualizada, mantendo o
arquivo original intacto no backup. Depois executa as mesmas validações da
v1.1.7.

Nenhuma alteração do instalador original foi aplicada quando ocorreu o erro,
pois a falha aconteceu durante a fase de preparação, antes do backup/escrita.

Sem migração de banco.

`APP_VERSION = 1.1.7`
