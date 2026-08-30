# Atualização e Deploy

## Antes de qualquer atualização

1. Faça backup do banco.
2. Faça backup dos arquivos.
3. Confirme que o Portal atual está funcionando.
4. Execute:

```bash
php tests/run.php
```

5. Confirme `git status`.

## Atualizadores de versão

Os pacotes de atualização fornecidos para versões específicas normalmente
contêm um arquivo PHP executável pela linha de comando.

Execute sempre a partir da raiz do Portal:

```bash
php atualizar_vX.Y.Z.php
```

Quando houver uma correção R2/R3 específica, siga as instruções do pacote e
não repita a atualização original se ela tiver sido parcialmente aplicada.

## Depois da atualização

1. Leia toda a saída do atualizador.
2. Confirme a versão exibida no painel.
3. Execute novamente:

```bash
php tests/run.php
```

4. Faça testes funcionais das áreas alteradas.
5. Faça `Ctrl+F5` quando houver alteração de CSS/JS.

## Git

Fluxo recomendado:

```bash
git status
git add .
git commit -m "Portal vX.Y.Z"
git push
```

Depois confira o GitHub Actions **Portal PHP Quality**.

## Produção

Antes do deploy:

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- dependências instaladas;
- `config/config.php` protegido;
- uploads/storage graváveis;
- backups recentes;
- tarefas agendadas funcionando;
- Central de Diagnóstico sem erros críticos.

## Rollback

Cada atualizador deve, quando possível, criar backup em:

`storage/update-backups/`

Nunca remova o backup imediatamente após a atualização. Mantenha ao menos até
a versão nova ser validada em produção.
