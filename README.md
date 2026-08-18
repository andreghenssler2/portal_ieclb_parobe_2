# Portal IECLB Parobé

Base inicial de um CMS próprio, inspirado no fluxo de administração do WordPress, desenvolvido em PHP + MySQL.

## Requisitos

- PHP 8.2 ou 8.3
- MySQL 8+ ou MariaDB compatível
- Extensão PDO MySQL
- Apache (XAMPP/cPanel funciona normalmente)

## Instalação local

1. Copie a pasta para `C:\xampp\htdocs\portal_ieclb_parobe`.
2. Crie/importar o banco executando `database.sql` no phpMyAdmin.
3. Copie `config/config.example.php` para `config/config.php`.
4. Ajuste `BASE_URL`, `DB_NAME`, `DB_USER` e `DB_PASS`.
5. Abra `http://localhost/portal_ieclb_parobe`.
6. Acesse `http://localhost/portal_ieclb_parobe/admin/login.php`.

## Primeiro acesso

- E-mail: `admin@ieclbparobe.com.br`
- Senha: `Admin@123`

**Troque a senha antes de colocar o portal em produção.**

## O que já existe

- Portal público inicial
- Login administrativo
- Sessão segura e CSRF
- Dashboard
- Cadastro/edição de comunidades
- Cadastro/edição de notícias
- Categorias iniciais
- Notícia paroquial ou vinculada a uma comunidade
- Rascunho, agendamento, publicação e arquivamento
- Destaque na página inicial
- Editor visual TinyMCE
- Slug único automático
- Contagem de visualizações
- Estrutura inicial de perfis, configurações e logs

## Próximos módulos sugeridos

1. Biblioteca de mídia e upload de imagem destacada
2. Páginas institucionais
3. Eventos e cultos
4. Usuários e permissões granulares
5. Menus dinâmicos
6. Configurações pelo painel
7. Galerias
8. SEO, sitemap e Open Graph
9. Logs administrativos
10. Instalador web
