-- Portal IECLB Parobé - migração v0.10.0
-- SEO individual + Sitemap + Robots

ALTER TABLE posts ADD COLUMN seo_titulo VARCHAR(180) NULL AFTER resumo,
                  ADD COLUMN seo_descricao VARCHAR(320) NULL AFTER seo_titulo,
                  ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_descricao;
ALTER TABLE paginas ADD COLUMN seo_titulo VARCHAR(180) NULL AFTER resumo,
                    ADD COLUMN seo_descricao VARCHAR(320) NULL AFTER seo_titulo,
                    ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_descricao;
ALTER TABLE eventos ADD COLUMN seo_titulo VARCHAR(180) NULL AFTER resumo,
                    ADD COLUMN seo_descricao VARCHAR(320) NULL AFTER seo_titulo,
                    ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_descricao;
ALTER TABLE galerias ADD COLUMN seo_titulo VARCHAR(180) NULL AFTER descricao,
                     ADD COLUMN seo_descricao VARCHAR(320) NULL AFTER seo_titulo,
                     ADD COLUMN seo_noindex TINYINT(1) NOT NULL DEFAULT 0 AFTER seo_descricao;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem) VALUES
('Gerenciar SEO','seo.gerenciar','Configuração','Gerenciar metadados, redes sociais, sitemap e robots.',70)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);


INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug IN ('administrador','comunicacao') AND pe.slug='seo.gerenciar';

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('seo_title_separator','-','texto'),
('seo_append_site_name','1','booleano'),
('seo_robots_index','1','booleano'),
('seo_robots_follow','1','booleano'),
('seo_social_title','','texto'),
('seo_social_description','','texto'),
('seo_open_graph_ativo','1','booleano'),
('seo_twitter_card_ativo','1','booleano'),
('seo_twitter_site','','texto'),
('seo_sitemap_ativo','1','booleano'),
('seo_sitemap_posts','1','booleano'),
('seo_sitemap_paginas','1','booleano'),
('seo_sitemap_eventos','1','booleano'),
('seo_sitemap_galerias','1','booleano'),
('seo_sitemap_formularios','0','booleano'),
('seo_sitemap_ultima_geracao','','datahora')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
