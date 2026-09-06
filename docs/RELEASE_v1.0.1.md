# Portal IECLB Parobé — v1.0.1

## Acabamento pós-release

A v1.0.1 é uma atualização de manutenção da série 1.x. Não adiciona migração de
banco e não muda conteúdo público.

### Ajustes

- atualiza o checklist que ainda citava a pré-produção v0.99.0;
- transforma o teste final em um teste reutilizável para toda a série 1.x;
- diferencia OPcache do PHP CLI e OPcache do servidor web;
- no CLI, OPcache inativo deixa de reduzir artificialmente a pontuação;
- no servidor web, OPcache inativo continua aparecendo como aviso;
- melhora a mensagem da tarefa opcional de recebimento IMAP;
- adiciona teste pós-release específico da v1.0.1.

### OPcache

O PHP CLI e o PHP usado pelo Apache/FPM podem utilizar configurações diferentes.

Por isso:

- `php tests/release-readiness.php` não trata OPcache inativo no CLI como falha
  operacional do site;
- a tela `Admin → Ferramentas → Desempenho`, executada pelo servidor web,
  continua sendo a referência para conferir OPcache no ambiente publicado.

### IMAP

A extensão IMAP é necessária apenas se o recurso de recebimento de respostas por
e-mail estiver sendo usado. Quando ela não existe, a tarefa é ignorada sem erro e
a mensagem passa a explicar esse caráter opcional.

### Testes

```bash
php tests/run.php
php tests/post-release-v101.php
php tests/release-readiness.php
php tests/release-final.php
```
