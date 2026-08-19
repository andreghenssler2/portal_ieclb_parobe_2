# Portal IECLB Parobé v0.20.0

## Auditoria e Segurança

- Nova tela **Auditoria** com filtros por usuário, ação, nível, data e busca.
- Exportação CSV dos registros filtrados.
- Logs ampliados com nível, método HTTP, rota, IP, navegador e request ID.
- Registro de login bem-sucedido, falha, bloqueio temporário, logout e acesso negado.
- Nova tela **Configurações > Segurança**.
- Expiração de sessão administrativa por inatividade.
- Limite configurável de tentativas de login e bloqueio temporário.
- Retenção configurável da auditoria e limpeza automática.
- Novas permissões `auditoria.visualizar` e `seguranca.gerenciar`, concedidas somente ao Administrador por padrão.
