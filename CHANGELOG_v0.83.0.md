# Portal IECLB Parobé v0.83.0

## Sessões e Acessos

- Nova tabela `user_sessions`.
- Cada login administrativo passa a ser registrado separadamente.
- Nova tela `Minhas sessões`.
- Nova tela administrativa `Configurações > Sessões ativas`.
- Mostra dispositivo, IP, início e último acesso.
- Encerramento remoto de sessão.
- Ação para encerrar todas as outras sessões.
- Administrador pode encerrar sessões de outro usuário.
- Troca de senha encerra automaticamente as outras sessões.
- Token de sessão armazenado somente como SHA-256.
- `session.use_strict_mode` e `session.use_only_cookies`.
- Cookie `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS/proxy HTTPS.
- Regeneração periódica do ID PHP a cada 15 minutos.
- Expiração integrada ao timeout de inatividade já configurado no Portal.
