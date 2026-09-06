# Portal IECLB Parobé — v0.99.0

## Pré-produção / Release Readiness

A v0.99.0 é a etapa de consolidação imediatamente anterior à v1.0.

Ela não adiciona um grande módulo de conteúdo. Em vez disso, reúne os
diagnósticos construídos nas versões anteriores em uma única Central de
Pré-produção.

### Admin

Novo caminho:

`Admin → Ferramentas → Pré-produção`

### Verificações automáticas

- versão do Portal;
- PHP >= 8.2;
- APP_ENV;
- APP_DEBUG;
- BASE_URL;
- HTTPS;
- timezone;
- `storage` gravável;
- `config/config.php`;
- proteção de scripts de manutenção no `.htaccess`;
- extensões PHP principais;
- auditoria de perfis e permissões;
- estrutura de backup/restauração;
- existência de backups;
- cron e heartbeat;
- erros operacionais do agendador;
- domínio de e-mail e score SPF/DKIM/DMARC/MX;
- cache e OPcache;
- estrutura de acessibilidade.

### Estados

`Pronto`
: nenhuma falha nem aviso automático.

`Atenção`
: sem bloqueadores, mas existem itens para revisar antes da v1.0.

`Bloqueado`
: existe falha estrutural que deve ser corrigida.

### Importante

Em ambiente local é esperado receber avisos para:

- `APP_ENV=development`;
- `APP_DEBUG=true`;
- BASE_URL localhost;
- ausência de HTTPS;
- heartbeat de cron não configurado na hospedagem;
- DNS de produção não resolvido localmente.

Esses avisos não falham o teste estrutural.

### CLI

`php tests/release-readiness.php`

O teste retorna código 1 apenas quando existem bloqueadores estruturais.

### Próxima etapa

Depois da v0.99.0 e do fechamento do checklist, a próxima versão planejada é a
v1.0.0.
