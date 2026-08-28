# Portal IECLB Parobé v0.2.0

CMS próprio em PHP + MySQL, inspirado no fluxo de administração do WordPress.

## Requisitos
- PHP 8.2 ou 8.3
- MySQL 8+ ou MariaDB compatível
- PDO MySQL e Fileinfo
- Apache (XAMPP/cPanel)

## Instalação nova
1. Copie a pasta para `C:\xampp\htdocs\portal_ieclb_parobe`.
2. Importe `database.sql` no phpMyAdmin.
3. Ajuste `config/config.php`.
4. Abra `http://localhost/portal_ieclb_parobe`.
5. Painel: `http://localhost/portal_ieclb_parobe/admin/login.php`.

## Atualização da v0.1.0
1. Faça backup do banco e da pasta atual.
2. Substitua os arquivos do portal pelos da v0.2.0, preservando seu `config/config.php` se ele já estiver configurado.
3. Execute uma única vez `migrations/2026_08_18_v0.2.0.sql` no banco existente.
4. Garanta permissão de gravação na pasta `uploads/`.

## Primeiro acesso
- E-mail: `admin@ieclbparobe.com.br`
- Senha: `Admin@123`

Troque a senha antes de colocar em produção.

## Novidades da v0.2.0
- Biblioteca de Mídia no painel
- Upload múltiplo de arquivos
- Imagens JPG, PNG, WEBP e GIF
- PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX e TXT
- Validação do MIME real do arquivo
- Limite padrão de 10 MB por arquivo
- Organização automática em `uploads/AAAA/MM/`
- Nomes aleatórios para arquivos no servidor
- Bloqueio de execução de scripts dentro de `uploads`
- Edição de título e texto alternativo da mídia
- Exclusão de mídia
- URL copiável da mídia
- Seleção de imagem destacada na notícia
- Upload direto de uma nova imagem destacada no formulário da notícia
- Exibição das imagens na página inicial e na notícia
- Contador de mídias no dashboard
- Registro básico das ações de mídia/notícia em `logs`
- Migração SQL da v0.1.0 para v0.2.0

## Próximos módulos sugeridos
1. Páginas institucionais
2. Eventos e cultos
3. Usuários e permissões granulares
4. Menus dinâmicos
5. Configurações pelo painel
6. Galerias
7. SEO, sitemap e Open Graph
8. Logs administrativos com tela de consulta
9. Instalador web
