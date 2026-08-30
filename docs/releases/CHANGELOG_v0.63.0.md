# Portal IECLB Parobé v0.63.0

## Central de Diagnóstico para Produção

A v0.63.0 amplia a Saúde do Portal com uma visão operacional concentrada.

### Nova tela

`Admin > Ferramentas > Central de Diagnóstico`

### Indicadores

- versão do Portal/PHP pelo diagnóstico estrutural;
- conexão, versão e tamanho do banco;
- maiores tabelas do banco;
- espaço total/livre em disco;
- pasta `uploads` e permissão de escrita;
- volume aproximado de uploads;
- cache ativo/desativado;
- tamanho, quantidade e arquivos expirados do cache;
- último backup e idade;
- últimos backups disponíveis;
- tarefas agendadas ativas, atrasadas e com erro;
- situação individual das tarefas;
- alertas `warning`/`critical` da auditoria;
- transporte de e-mail/SMTP;
- diagnóstico SMTP sob demanda.

### Integração

A nova Central reutiliza `SiteHealthService` para os testes já existentes
de servidor, PHP, banco, arquivos, URLs, e-mail e segurança.

A tela clássica `Saúde do Portal` continua disponível.

### Banco

Nenhuma alteração de banco de dados é necessária.
