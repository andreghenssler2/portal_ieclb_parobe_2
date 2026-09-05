# Portal IECLB Parobé — v0.92.0

## Auditoria de Perfis e Permissões

A v0.92.0 adiciona uma revisão operacional das permissões administrativas sem
alterar os vínculos já configurados.

### Admin

Novo caminho:

`Admin → Usuários → Auditoria de Permissões`

A tela apresenta:

- quantidade de perfis, permissões e usuários;
- quantidade de usuários ativos por perfil;
- matriz completa Perfil × Permissão;
- exportação CSV da matriz;
- auditoria estática das páginas em `admin/`;
- indicação de `requirePermission`, `requireLogin` e páginas públicas;
- permissões referenciadas pelo código mas inexistentes no banco;
- vínculos órfãos e duplicados;
- permissões cadastradas não localizadas na análise estática;
- avisos de perfis com usuários ativos e nenhuma permissão.

### Segurança

A auditoria é somente leitura.

Nenhum perfil, usuário ou vínculo em `perfil_permissoes` é alterado
automaticamente.

### CLI

Novo teste:

`php tests/permissions.php`

Novo diagnóstico:

`php diagnosticar_v0.92.0.php`

### Observação

A análise de rotas é estática. Arquivos incluídos por outros fluxos podem gerar
aviso de ausência de `requireLogin` ou `requirePermission` mesmo quando a
proteção existe na página chamadora. Esses casos são avisos e não erros
automáticos.
