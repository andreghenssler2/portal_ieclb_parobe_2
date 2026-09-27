# Portal IECLB Parobé — v1.2.0 R4

## Consolidação final das duplicidades encontradas pela R3

A saída da R3 mostrou dois problemas diferentes.

### 1. O auditor ainda tinha falso positivo de escopo

Em strings PHP como:

```php
"LIMIT {$limit}"
```

`token_get_all()` emite tokens especiais de interpolação. O auditor R3 contava o
`}` da interpolação como se fosse o fechamento real da classe. A partir dali,
métodos da própria classe passavam a ser classificados como funções globais.

A R4 corrige isso.

### 2. As cópias eram realmente idênticas

A auditoria confirmou 10 grupos de arquivos com SHA-256 idêntico.

Em vez de simplesmente apagar caminhos antigos, a R4 substitui as cópias por
wrappers mínimos de compatibilidade usando `require_once` para o arquivo
canônico. Assim:

- a declaração duplicada deixa de existir;
- URLs ou includes antigos continuam funcionando;
- o código passa a ter uma única fonte real;
- o risco de quebrar favoritos ou referências externas é reduzido.

### Pares consolidados

- `SessionSecurityService_v0.83.0.php`
  -> `app/Services/SessionSecurityService.php`
- `app/Services/ContentAutosaveServiceV84R3.php`
  -> `app/Services/ContentAutosaveService.php`
- `app/Services/UserActivityServiceV87R2.php`
  -> `app/Services/UserActivityService.php`
- `admin/app/Services/DocumentService.php`
  -> `app/Services/DocumentService.php`
- `admin/admin/documentos/categorias.php`
  -> `admin/documentos/categorias.php`
- `admin/admin/documentos/index.php`
  -> `admin/documentos/index.php`
- `admin/documento-baixar.php`
  -> `documento-baixar.php`
- `admin/documento.php`
  -> `documento.php`
- `admin/documentos.php`
  -> `documentos.php`
- `categoria_paginacao_20.php`
  -> `categoria.php`

A substituição só ocorre quando a cópia ainda é byte-a-byte equivalente ao
arquivo canônico. Se houver divergência, o instalador para antes de alterar
aquele arquivo.

Todos os arquivos alterados são copiados para:

`storage/update-backups/v1.2.0-R4-duplicidades-*`

`APP_VERSION` permanece `1.2.0`.
