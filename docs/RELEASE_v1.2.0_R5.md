# Portal IECLB Parobé — v1.2.0 R5

## Saúde do Portal unificada

O menu Ferramentas possuía três entradas sobrepostas:

- Saúde do Portal (`saude-portal.php`);
- Central de Diagnóstico (`diagnostico.php`);
- Saúde do Portal (`saude.php`).

As três páginas consultavam partes do mesmo estado operacional.

A R5 cria uma única central:

`admin/ferramentas/saude-central.php`

Ela reúne:

- diagnóstico técnico de PHP, banco, arquivos, segurança, URLs e e-mail;
- diagnóstico SMTP;
- métricas operacionais de produção;
- backups recentes;
- tarefas agendadas;
- maiores tabelas do banco;
- pontuação atual de saúde;
- snapshots e tendência histórica.

O menu Ferramentas passa a mostrar apenas:

`Saúde do Portal`

Os caminhos antigos continuam válidos, mas agora redirecionam para a central
unificada. Isso evita quebrar favoritos e links antigos.

`APP_VERSION` permanece `1.2.0`.
