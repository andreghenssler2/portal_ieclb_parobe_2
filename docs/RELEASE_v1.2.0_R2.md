# Portal IECLB Parobé — v1.2.0 R2

## Correção do atualizador de consolidação

A R1 corrigiu o falso negativo do Media Picker, porém revelou um erro no
próprio instalador ao consolidar o `bootstrap.php`.

O código usava incorretamente:

```php
str_replace($old, $new, $count)
```

fazendo `$count` ser interpretado como o conteúdo a substituir. Isso causava:

- `Undefined variable $count`;
- `TypeError` no `str_replace()`.

A R2 usa a assinatura correta:

```php
str_replace($old, $new, $content, $count)
```

com `$count` inicializado antes da chamada.

A falha da R1 ocorreu antes da etapa de backup/escrita, portanto a tentativa
não alterou arquivos do Portal nem o banco.

A correção R1 do Media Picker foi mantida.

`APP_VERSION` final permanece `1.2.0`.
