-- Portal IECLB Parobé - v0.6.0
-- Menus administráveis e configurações gerais/SEO.

CREATE TABLE IF NOT EXISTS menus (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    localizacao VARCHAR(80) NOT NULL UNIQUE,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS menu_itens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    pagina_id INT UNSIGNED NULL,
    tipo ENUM('link','pagina') NOT NULL DEFAULT 'link',
    titulo VARCHAR(160) NOT NULL,
    url VARCHAR(500) NULL,
    nova_aba TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_menu_itens_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_itens_parent FOREIGN KEY (parent_id) REFERENCES menu_itens(id) ON DELETE SET NULL,
    CONSTRAINT fk_menu_itens_pagina FOREIGN KEY (pagina_id) REFERENCES paginas(id) ON DELETE SET NULL,
    INDEX idx_menu_itens_menu_ordem (menu_id, ordem),
    INDEX idx_menu_itens_parent (parent_id),
    INDEX idx_menu_itens_pagina (pagina_id)
) ENGINE=InnoDB;

INSERT INTO menus (nome, slug, localizacao, ativo)
VALUES ('Menu Principal', 'menu-principal', 'principal', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), ativo = VALUES(ativo);

INSERT INTO permissoes (nome, slug, grupo, descricao, ordem) VALUES
('Gerenciar menus', 'menus.gerenciar', 'Estrutura', 'Administrar os menus e links públicos do portal.', 80),
('Gerenciar configurações', 'configuracoes.gerenciar', 'Administração', 'Alterar identidade, contatos, redes sociais e SEO do portal.', 90)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao), ordem = VALUES(ordem);

-- Administrador recebe as novas permissões explicitamente para manter a tabela consistente.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'administrador' AND pe.slug IN ('menus.gerenciar','configuracoes.gerenciar');

-- Secretaria pode administrar menus e configurações.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'secretaria' AND pe.slug IN ('menus.gerenciar','configuracoes.gerenciar');

-- Comunicação pode administrar o menu público.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'comunicacao' AND pe.slug = 'menus.gerenciar';

INSERT INTO configuracoes (chave, valor, tipo) VALUES
('site_endereco', '', 'texto'),
('site_logo_id', '', 'numero'),
('site_favicon_id', '', 'numero'),
('hero_titulo', 'IECLB Parobé', 'texto'),
('hero_subtitulo', 'Notícias, cultos, eventos e informações das comunidades da Paróquia de Parobé.', 'texto'),
('footer_texto', 'Paróquia Evangélica de Confissão Luterana de Parobé', 'texto'),
('seo_titulo', 'IECLB Parobé', 'texto'),
('seo_descricao', 'Portal da IECLB Parobé', 'texto'),
('seo_keywords', 'IECLB, Parobé, igreja luterana, cultos, eventos', 'texto'),
('seo_og_image_id', '', 'numero')
ON DUPLICATE KEY UPDATE tipo = VALUES(tipo);

-- Itens padrão do menu principal, somente se ainda não existirem.
INSERT INTO menu_itens (menu_id, tipo, titulo, url, ordem, ativo)
SELECT m.id, 'link', 'Início', '/', 10, 1
FROM menus m
WHERE m.localizacao = 'principal'
  AND NOT EXISTS (SELECT 1 FROM menu_itens mi WHERE mi.menu_id = m.id AND mi.tipo='link' AND mi.url='/');

INSERT INTO menu_itens (menu_id, tipo, titulo, url, ordem, ativo)
SELECT m.id, 'link', 'Agenda', 'agenda.php', 20, 1
FROM menus m
WHERE m.localizacao = 'principal'
  AND NOT EXISTS (SELECT 1 FROM menu_itens mi WHERE mi.menu_id = m.id AND mi.tipo='link' AND mi.url='agenda.php');

INSERT INTO menu_itens (menu_id, tipo, titulo, url, ordem, ativo)
SELECT m.id, 'link', 'Comunidades', 'comunidades.php', 30, 1
FROM menus m
WHERE m.localizacao = 'principal'
  AND NOT EXISTS (SELECT 1 FROM menu_itens mi WHERE mi.menu_id = m.id AND mi.tipo='link' AND mi.url='comunidades.php');

-- Migra páginas que já estavam marcadas para aparecer no menu.
INSERT INTO menu_itens (menu_id, pagina_id, tipo, titulo, ordem, ativo)
SELECT m.id, p.id, 'pagina', p.titulo, 100 + p.ordem, 1
FROM menus m
CROSS JOIN paginas p
WHERE m.localizacao = 'principal'
  AND p.exibir_menu = 1
  AND NOT EXISTS (
      SELECT 1 FROM menu_itens mi
      WHERE mi.menu_id = m.id AND mi.pagina_id = p.id
  );
