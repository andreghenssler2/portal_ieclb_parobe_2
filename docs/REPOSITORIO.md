# Organização do Repositório

## Arquivos que devem ser versionados

- código PHP;
- CSS/JS de origem;
- migrações;
- documentação;
- testes;
- workflows do GitHub;
- `composer.json` e lockfile quando aplicável;
- exemplos de configuração sem segredos.

## Arquivos que não devem ser versionados

- `config/config.php`;
- senhas e chaves;
- uploads dos usuários;
- backups;
- logs;
- cache;
- dependências instaladas;
- ZIPs de atualização;
- arquivos temporários;
- dumps locais de banco.

## Documentação de versões

Use:

- `CHANGELOG.md` para o histórico consolidado;
- `docs/releases/` para notas antigas/específicas.

Evite adicionar novos `CHANGELOG_vX.Y.Z.md` na raiz.

## Pastas canônicas

- `admin/documentos/` é a interface administrativa de documentos.
- `app/Services/DocumentService.php` é o serviço canônico de documentos.

Pastas antigas duplicadas como `admin/admin/documentos/` e
`admin/app/Services/DocumentService.php` não devem voltar ao repositório.

## Atualizadores

Os instaladores de atualização são artefatos de deploy e podem permanecer
fora do Git quando já aplicados.

O `.gitignore` ignora `_update_*`, pacotes ZIP e scripts de atualização
versionados na raiz.
