# Portal IECLB Parobé — v1.1.7 R2

## Reparo do instalador da Agenda

Corrige o erro:

`agenda.php / ações superiores: número inesperado de ocorrências (3).`

O instalador R1 procurava uma classe Bootstrap genérica:

`<div class="d-flex flex-wrap gap-2">`

Essa classe aparece várias vezes na Agenda. A R2 localiza especificamente
o grupo de ações do cabeçalho da Agenda e altera somente a primeira ocorrência
dentro daquele contexto.

Também foi endurecida a inserção do botão **Google Agenda** na visualização
em lista para evitar que ele seja inserido dentro de um link do calendário mensal.

A falha da R1 aconteceu na fase de preparação em memória. Nenhum arquivo foi
gravado e o `APP_VERSION` permaneceu 1.1.6.

Sem migração de banco.
