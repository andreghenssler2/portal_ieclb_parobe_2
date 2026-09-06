# Portal IECLB Parobé — v1.1.0

## Saúde do Portal

A v1.1.0 inicia a série 1.1 com um painel operacional permanente, substituindo a
ideia de que o checklist serve apenas para o momento anterior ao lançamento.

### Novo painel

`Admin → Ferramentas → Saúde do Portal`

O painel mostra:

- estado geral;
- pontuação operacional;
- verificações aprovadas;
- avisos e bloqueadores;
- todas as seções da Central Operacional;
- histórico de snapshots;
- tendência entre os dois últimos registros.

### Snapshots

O botão **Registrar snapshot** grava um arquivo JSON em:

`storage/health/portal/`

Não existe nova tabela no banco.

São mantidos automaticamente até 120 snapshots. O histórico serve para comparar
mudanças de configuração ou ambiente ao longo do tempo.

### Segurança

A página exige `configuracoes.gerenciar`.

A gravação manual utiliza CSRF.

Os snapshots não armazenam senhas ou credenciais; registram apenas o resumo
produzido pela Central Operacional.

### Teste

```bash
php tests/portal-health.php
```
