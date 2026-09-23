# Portal IECLB Parobé — v1.1.3

## Consolidação estável

A v1.1.3 consolida as correções validadas em produção após a v1.1.2:

- agendamento público correto;
- status `Agendado` automático;
- workflow administrativo;
- busca da Agenda sem PDO `HY093`;
- menu do Rodapé;
- Google Maps com sandbox controlado;
- remoção do conflito `allow` / `allowfullscreen`;
- editor compatível com ModSecurity usando transporte base64url.

A R1 de consolidação também garante que o `TrustedEmbedService` final esteja
instalado antes da promoção para `APP_VERSION = 1.1.3`.

Sem migração de banco.
