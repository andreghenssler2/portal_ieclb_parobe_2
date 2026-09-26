# Portal IECLB Parobé — v1.1.5

## Correção dos botões da Agenda

A Agenda pública usava vários botões com apenas classes do Bootstrap Icons:

- Filtrar;
- Limpar;
- Mês anterior;
- Próximo mês.

O tema público carregava o Bootstrap, mas não carregava o CSS do
**Bootstrap Icons**. Por isso os botões existiam, porém ficavam visualmente
vazios.

### Correções

- adiciona Bootstrap Icons 1.11.3 ao tema público;
- os botões **Filtrar** e **Limpar** passam a ter texto visível;
- a navegação mensal usa `‹` e `›` como fallback independente de fonte;
- Calendário/Lista mantêm rótulos visíveis também em telas menores;
- aumenta o espaço das ações do filtro para evitar botões comprimidos.

Assim a Agenda continua utilizável mesmo se a fonte de ícones externa falhar.

Sem migração de banco.

`APP_VERSION = 1.1.5`
