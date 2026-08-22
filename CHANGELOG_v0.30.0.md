# Portal IECLB Parobé — v0.30.0

## Saúde do Portal + hardening

A v0.30.0 adiciona uma central de diagnóstico administrativo inspirada no conceito de “Saúde do Site” do WordPress, sem depender de serviços externos.

### Ferramentas → Saúde do Portal

Verificações incluídas:

- versão do PHP (8.2+);
- extensões obrigatórias e recomendadas;
- `max_execution_time`, memória e limites de upload;
- conexão, versão, charset e estrutura principal do banco;
- presença e permissão de gravação em `uploads/` e `storage/`;
- `BASE_URL`, HTTPS, sitemap, robots e roteador;
- instalação/configuração do PHPMailer;
- diagnóstico SMTP opcional (DNS, conexão, TLS e autenticação, sem enviar mensagem);
- APP_DEBUG/display_errors em produção;
- timeout de sessão e proteção contra tentativas de login;
- presença de backups antigos de `config.php` dentro da pasta pública.

### Segurança

- nova permissão `saude.visualizar`, concedida inicialmente ao Administrador;
- `config/.htaccess` bloqueia acesso HTTP à configuração e backups;
- o atualizador adiciona regras ao `.htaccess` principal para bloquear via HTTP:
  - `atualizar*.php`;
  - `database*.sql`;
  - arquivos `.bak*`;
  - arquivos `.sha256`;
- os atualizadores continuam funcionando normalmente pela CLI (`php atualizar_vX.Y.Z.php`);
- backups antigos `config/config.php.bak-*` são movidos para `storage/config-backups/`;
- backups criados pela atualização passam a ficar fora de `config/`.

## Atualização

Copie os arquivos da v0.30.0 para a raiz do Portal e execute:

```bash
php atualizar_v0.30.0.php
```

Depois acesse **Ferramentas → Saúde do Portal**.
