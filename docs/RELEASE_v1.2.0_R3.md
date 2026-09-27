# Portal IECLB Parobé — v1.2.0 R3

## Limpeza de duplicidades

A auditoria inicial de duplicidades identificou problemas reais, porém também
classificou incorretamente métodos de classes diferentes como se fossem a mesma
função global.

Exemplos que NÃO são duplicidade de declaração:

- `GroupService::save()` e `LeadershipService::save()`;
- métodos `cut()`, `tableExists()`, `delete()` ou `stats()` em classes diferentes.

A R3 inclui uma auditoria corrigida com controle de escopo de classe.

### Remoção automática segura

O atualizador considera apenas sobras históricas/versionadas conhecidas:

- `SessionSecurityService_v0.83.0.php`;
- `app/Services/ContentAutosaveServiceV84R3.php`;
- `app/Services/UserActivityServiceV87R2.php`.

Um arquivo só é removido quando:

1. o arquivo canônico existe;
2. o conteúdo é exatamente igual ao canônico;
3. nenhuma referência explícita ao caminho/nome legado é encontrada no código PHP ativo.

Antes da exclusão é criado backup em:

`storage/update-backups/v1.2.0-R3-duplicidades-*`

### Duplicidades que ficam apenas para revisão

Cópias de páginas/rotas, como `admin/admin/documentos/*`,
`admin/documentos.php` e `categoria_paginacao_20.php`, não são apagadas
automaticamente nesta R3, mesmo quando o conteúdo é idêntico.

O motivo é que uma URL antiga ainda pode estar em favoritos, links ou regras
de roteamento. A remoção dessas rotas exige validação de referências/redirects.

### Repetição técnica

Implementações pequenas ou utilitárias semelhantes em serviços diferentes
também não são refatoradas automaticamente. Isso é uma melhoria de arquitetura,
não uma correção de duplicidade fatal.

`APP_VERSION` permanece `1.2.0`.
