# Portal IECLB Parobé — v1.1.8 R2

## Reparo da finalização do instalador

Corrige o fatal:

`Cannot declare class NewsFeatureService, because the name is already in use`

### Causa

O instalador R1 carregava `NewsFeatureService.php` diretamente do payload
para criar as tabelas e, depois de gravar os arquivos, carregava `bootstrap.php`.
O bootstrap carregava novamente a mesma classe a partir de
`app/Services/NewsFeatureService.php`.

Como eram dois caminhos físicos diferentes, `require_once` não evitava a
segunda declaração da classe.

### Situação após o erro R1

O erro aconteceu **depois** de:

- criar o backup;
- criar/verificar as tabelas v1.1.8;
- gravar todos os arquivos;
- atualizar `config/config.php` e `config/config.example.php` para 1.1.8;
- instalar os testes e documentação.

Portanto não deve ser feito rollback automático.

### Correção R2

O instalador não carrega mais a classe do payload antes da gravação.
A preparação do banco é feita diretamente pelo próprio instalador com SQL
idempotente. Depois os arquivos são gravados e apenas então o `bootstrap.php`
é carregado, fazendo `NewsFeatureService` ser declarado uma única vez.

A R2 aceita tanto APP_VERSION 1.1.7 quanto 1.1.8, permitindo concluir
uma instalação que parou no fatal da R1.

Sem alteração destrutiva da tabela `posts`.
