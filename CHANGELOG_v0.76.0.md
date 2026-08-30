# Portal IECLB Parobé v0.76.0

## Relatórios CSP

- Novo `CspReportService`.
- Novo endpoint público `csp-report.php`.
- CSP passa a incluir `report-uri` quando a coleta está habilitada.
- Nova tabela `security_csp_reports`.
- Deduplicação por fingerprint e contador de ocorrências.
- Limite de 5.000 padrões únicos.
- Retenção configurável de 1 a 365 dias.
- URLs armazenadas sem query string ou fragmento.
- Nenhum IP ou `script-sample` é armazenado.
- Nova tela `Admin > Configurações > Relatórios CSP`.
