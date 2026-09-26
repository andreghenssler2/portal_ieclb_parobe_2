# Portal IECLB Parobé — v1.1.9 R3

## Reparo consolidado do salvamento de Comunidades

O R2 falhou antes de alterar arquivos porque tentava localizar a assinatura de
`ensureSchema()` em um formato exato.

A R3 não depende mais desse ponto de alteração. Ela:

- valida que o arquivo atual ainda é `CommunityProfileService`;
- faz backup do serviço atual;
- substitui o serviço inteiro por uma versão consolidada e corrigida;
- usa cache de schema por conexão PDO;
- não executa `CREATE TABLE` dentro de uma transação ativa;
- instala teste transacional específico;
- mantém `APP_VERSION` em 1.1.9.

O formulário administrativo não precisa ser substituído para esta correção:
o problema está no serviço chamado durante a transação.
