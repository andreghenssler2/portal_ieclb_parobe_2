# Portal IECLB Parobé v0.21.0

## Ferramentas

Novo grupo **Ferramentas** no painel administrativo:

- Backups
- Manutenção
- Limpeza

## Backups

- Criação de backup SQL do banco diretamente via PDO, sem depender de `mysqldump`.
- Compactação GZIP quando a extensão zlib estiver disponível.
- Lista de backups com data, tamanho e SHA-256.
- Download protegido pelo painel administrativo.
- Política configurável de retenção dos backups mais recentes.
- Exclusão manual.
- Restauração com confirmação textual `RESTAURAR`.
- Backup de segurança automático imediatamente antes de uma restauração.
- Pasta `storage/backups` protegida contra acesso direto pela web.

> Nesta versão os backups abrangem o banco de dados. A pasta `uploads/` não é duplicada no backup.

## Modo manutenção

- Ativação/desativação pelo painel.
- Visitantes recebem HTTP `503 Service Unavailable`.
- `Retry-After`, `noindex` e `no-store` durante manutenção.
- Título e mensagem personalizados.
- Previsão opcional de retorno.
- Administradores podem continuar visualizando o portal público.
- Lista de IPs liberados.
- Painel administrativo permanece acessível.
- Dashboard mostra aviso enquanto a manutenção estiver ativa.

## Limpeza

- Aplicação manual da retenção da auditoria.
- Limpeza de tentativas de login antigas.
- Retenção configurável dos backups do Editor de Temas.
- Aplicação da retenção dos backups do banco.
- Limpeza geral em uma única ação.

## Permissões

- `backups.gerenciar`
- `manutencao.gerenciar`

As duas permissões são concedidas somente ao Administrador por padrão.
