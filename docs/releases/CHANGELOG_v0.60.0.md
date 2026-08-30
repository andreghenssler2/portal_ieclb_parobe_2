# Portal IECLB Parobé v0.60.0

## Segurança e consolidação

A v0.60.0 concentra a evolução do acesso administrativo.

### Novidades

- Autenticação em dois fatores TOTP opcional por usuário.
- Compatibilidade com Google Authenticator, Microsoft Authenticator, 2FAS e apps RFC 6238.
- Segunda etapa de login sem criar sessão administrativa antes da validação.
- Códigos de recuperação de uso único.
- Proteção contra reutilização do mesmo código TOTP.
- Segredo TOTP criptografado em repouso.
- Chave privada armazenada em `storage/private/totp.key`.
- Ativação/desativação do 2FA exige a senha atual.
- Tela `Minha conta > Autenticação em dois fatores`.
- Indicador de contas protegidas por 2FA em `Configurações > Segurança`.
- Auditoria de solicitação, falha, ativação, desativação e regeneração de códigos.
- Atualização do número de versão para 0.60.0.

### Estrutura de banco

Novos campos em `usuarios`:

- `totp_secret`
- `totp_enabled_at`
- `totp_last_used_step`

Nova tabela:

- `usuario_2fa_recovery_codes`

### Compatibilidade

Contas que não ativarem 2FA continuam usando o fluxo tradicional de e-mail + senha.

O bloqueio temporário por tentativas, auditoria e expiração por inatividade já existentes continuam funcionando.
