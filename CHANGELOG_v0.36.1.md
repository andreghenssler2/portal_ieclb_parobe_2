# Portal IECLB Parobé v0.36.1

## PHPMailer via Composer em /lib

- PHPMailer deixa de ser carregado manualmente de `/vendor/phpmailer/phpmailer/src`.
- Novo `composer.json` oficial do Portal.
- Composer configurado com `vendor-dir` igual a `lib`.
- PHPMailer fixado em `7.1.1`.
- Novo autoload central: `/lib/autoload.php`.
- `bootstrap.php` passa a carregar o autoload do Composer.
- `MailService` passa a depender exclusivamente do autoload do Composer.
- Mensagens de diagnóstico agora orientam a executar `composer install`.
- Configurações > E-mail identifica que o PHPMailer é gerenciado pelo Composer.
- O PHPMailer antigo em `/vendor/phpmailer/phpmailer` é removido após backup, somente depois de a instalação em `/lib` ser validada.
- `/lib` é protegido contra acesso direto pelo Apache.
- `.gitignore` passa a ignorar dependências geradas em `/lib`, preservando `lib/.htaccess`.
- Nenhuma alteração de banco de dados.
