# Hotfix v0.89.0 R9 — Tipos de Eventos

Tipos oficiais:

- Cultos
- Festas
- Atividades
- Reuniões

A R9 substitui os marcadores frágeis das R7/R8 por localização do `<select>`
inteiro. Assim, os trechos PHP dentro dos `<option>` não interferem na
detecção.

Registros antigos `tipo=evento` são convertidos para `tipo=atividade`.

A opção Santa Ceia continua exclusiva do tipo Culto.

O instalador:
1. prepara e valida todos os arquivos;
2. cria backup dos arquivos;
3. cria backup do banco;
4. altera o ENUM;
5. grava os arquivos;
6. valida o resultado.

APP_VERSION permanece 0.89.0.
