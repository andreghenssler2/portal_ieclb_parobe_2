# Portal IECLB Parobé — v1.1.3 R3 R1

Reparo do instalador R3.

O R3 original parava ao localizar `maintenanceSettings()` por um padrão
excessivamente rígido. A R1 localiza a função pelos seus limites reais e só
grava arquivos depois de validar todas as alterações em memória.

Funcionalidade instalada:

- Data/hora de início da manutenção;
- Data/hora final da manutenção;
- início automático;
- fim automático;
- início em branco = imediato;
- fim em branco = desligamento manual;
- compatibilidade com `maintenance_expected_end`;
- sem dependência de cron.

APP_VERSION permanece 1.1.3.
Sem migração de banco.
