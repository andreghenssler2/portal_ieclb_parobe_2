# Relatórios CSP — v0.76.0

A v0.76.0 transforma a CSP em Report-Only da v0.75 em uma etapa de homologação
mensurável dentro do próprio Portal.

## Coletor

O cabeçalho CSP passa a incluir:

```text
report-uri <URL DO PORTAL>/csp-report.php
```

quando a coleta estiver habilitada.

## Privacidade

O coletor não salva:

- endereço IP;
- query string;
- fragmento de URL;
- `script-sample`;
- cookies;
- corpo completo do relatório.

As URLs são normalizadas e eventos idênticos são deduplicados por fingerprint.

## Proteção contra volume

A tabela mantém no máximo 5.000 padrões únicos.

Eventos repetidos apenas incrementam `occurrences`.

Relatórios antigos são removidos conforme a retenção configurada.

## Tela

Abra:

```text
Admin > Configurações > Relatórios CSP
```

A página mostra:

- violações nas últimas 24 horas;
- padrões únicos;
- ocorrências totais;
- diretivas mais violadas;
- recursos bloqueados mais frequentes;
- últimos 100 padrões;
- limpeza de relatórios expirados;
- limpeza total.

## Configurações

Em:

```text
Admin > Configurações > Cabeçalhos HTTP
```

foram adicionados:

- coletar violações CSP;
- retenção dos relatórios (1 a 365 dias).

## Banco

Nova tabela:

```text
security_csp_reports
```

## Fluxo recomendado

1. mantenha `CSP = Report-Only`;
2. navegue por Home, Admin, formulários, mídia, Analytics e embeds;
3. consulte Relatórios CSP;
4. ajuste allowlists em futuras versões;
5. só depois promova CSP para `Enforce`.
