# Portal IECLB Parobé — v1.0.0

## Release estável de produção

A v1.0.0 encerra o ciclo de construção iniciado nas versões 0.x e consolida o
Portal como versão estável para publicação.

Esta release não introduz migrações de banco nem grandes módulos novos. O
objetivo é reduzir risco e marcar um estado conhecido, testado e documentado.

### Critérios usados para fechar a v1.0.0

- suíte geral de qualidade aprovada;
- nenhuma falha de sintaxe PHP;
- auditoria de perfis e permissões sem bloqueadores;
- backup e restaurabilidade disponíveis;
- cron/heartbeat validado;
- SPF, DKIM, DMARC e MX validados;
- acessibilidade estrutural aprovada;
- refinamentos mobile aplicados;
- Central de Pré-produção sem bloqueadores;
- `APP_VERSION` consolidado em `1.0.0`.

### Avisos de ambiente

A Central de Pré-produção pode continuar apresentando avisos que dependem do
servidor final, por exemplo OPcache inativo no PHP CLI do XAMPP.

Esses avisos devem ser revisados na hospedagem, mas não representam
necessariamente falha do Portal.

### Novo teste final

```bash
php tests/release-final.php
```

O teste final falha somente quando encontra bloqueador real da release.

### Próximas versões

Após a v1.0.0, novas funcionalidades devem seguir versionamento incremental:

- correções: `1.0.x`;
- melhorias compatíveis: `1.x.0`;
- mudanças incompatíveis: próxima versão maior.
