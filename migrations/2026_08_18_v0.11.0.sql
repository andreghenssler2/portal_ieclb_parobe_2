-- Portal IECLB Parobé v0.11.0 - Aparência, temas e widgets
CREATE TABLE IF NOT EXISTS widgets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    area VARCHAR(40) NOT NULL DEFAULT 'home',
    tipo VARCHAR(40) NOT NULL,
    titulo VARCHAR(180) NULL,
    conteudo LONGTEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    configuracao LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_widgets_area_ativo_ordem (area, ativo, ordem)
) ENGINE=InnoDB;

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('active_theme','ieclb','texto'),
('aparencia_cor_primaria','#0b5d4b','texto'),
('aparencia_cor_secundaria','#6c757d','texto'),
('aparencia_cor_fundo','#ffffff','texto'),
('aparencia_cor_texto','#1f2937','texto'),
('aparencia_cor_rodape','#f8f9fa','texto'),
('aparencia_cor_rodape_texto','#495057','texto'),
('aparencia_container_max','1140','numero'),
('aparencia_cabecalho_sticky','0','booleano'),
('aparencia_mostrar_nome_com_logo','0','booleano'),
('aparencia_bordas_arredondadas','16','numero')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);

INSERT INTO permissoes (nome,slug,grupo,ordem)
SELECT 'Gerenciar aparência','aparencia.gerenciar','Aparência',80
WHERE NOT EXISTS (SELECT 1 FROM permissoes WHERE slug='aparencia.gerenciar');

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id,p.id FROM perfis pf CROSS JOIN permissoes p
WHERE p.slug='aparencia.gerenciar' AND pf.slug IN ('administrador','comunicacao');

INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','banners','',NULL,1,10,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='banners');
INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','apresentacao','',NULL,1,20,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='apresentacao');
INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','destaque','',NULL,1,30,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='destaque');
INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','agenda','Próximos cultos e eventos',NULL,1,40,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='agenda');
INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','noticias','Últimas notícias',NULL,1,50,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='noticias');
INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','galerias','Galerias de fotos',NULL,1,60,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='galerias');
INSERT INTO widgets (area,tipo,titulo,conteudo,ativo,ordem,configuracao)
SELECT 'home','comunidades','Nossas comunidades',NULL,1,70,NULL WHERE NOT EXISTS (SELECT 1 FROM widgets WHERE area='home' AND tipo='comunidades');
