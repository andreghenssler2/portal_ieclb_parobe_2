# Portal IECLB Parobé — v1.1.2

## Correção: notícias agendadas na Home

A v1.1.2 corrige a Home modular quando uma notícia está com status
`agendado`.

### Problema

O conteúdo individual já exigia:

- `status='publicado'`;
- `publicado_em` nulo ou menor/igual ao momento atual.

Porém a consulta genérica da Home modular aceitava qualquer status que não fosse
rascunho/lixeira/privado. Com isso, `agendado` podia aparecer no card da página
inicial antes da data programada, enquanto o clique resultava em notícia não
encontrada.

### Correção

Para a origem `posts`, a Home passa a usar a mesma regra pública da página
individual:

- somente `status='publicado'`;
- somente `publicado_em IS NULL OR publicado_em <= NOW()`.

Assim:

1. notícia agendada futura não aparece na Home;
2. quando a tarefa `publicar_conteudos_agendados` alterar o status para
   `publicado` na data programada, ela passa a ficar disponível;
3. mesmo um registro indevidamente marcado como `publicado` com data futura
   permanece oculto até a data chegar.

### Cache

O instalador invalida o cache da Home após aplicar a correção.

### Banco

Não há migração de banco.

### Teste

```bash
php tests/scheduled-public-visibility.php
```
