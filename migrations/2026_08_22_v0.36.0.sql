-- Portal IECLB Parobé v0.36.0
-- Equipe / Pastores & Lideranças

CREATE TABLE IF NOT EXISTS liderancas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    autor_id INT UNSIGNED NULL,
    foto_id BIGINT UNSIGNED NULL,
    comunidade_id INT UNSIGNED NULL,
    grupo_id BIGINT UNSIGNED NULL,
    nome VARCHAR(180) NOT NULL,
    slug VARCHAR(220) NOT NULL,
    tipo ENUM('pastoral','presbiterio','lideranca','equipe','outro') NOT NULL DEFAULT 'lideranca',
    funcao VARCHAR(180) NULL,
    resumo VARCHAR(500) NULL,
    biografia LONGTEXT NULL,
    email VARCHAR(190) NULL,
    telefone VARCHAR(40) NULL,
    whatsapp VARCHAR(40) NULL,
    instagram VARCHAR(500) NULL,
    facebook VARCHAR(500) NULL,
    exibir_email TINYINT(1) NOT NULL DEFAULT 0,
    exibir_telefone TINYINT(1) NOT NULL DEFAULT 0,
    exibir_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    seo_titulo VARCHAR(220) NULL,
    seo_descricao VARCHAR(320) NULL,
    seo_noindex TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_liderancas_slug (slug),
    KEY idx_liderancas_ativo_ordem (ativo,ordem,nome),
    KEY idx_liderancas_tipo (tipo,ativo),
    KEY idx_liderancas_comunidade (comunidade_id,ativo),
    KEY idx_liderancas_grupo (grupo_id,ativo),
    KEY idx_liderancas_foto (foto_id),
    CONSTRAINT fk_liderancas_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_liderancas_foto FOREIGN KEY (foto_id) REFERENCES midias(id) ON DELETE SET NULL,
    CONSTRAINT fk_liderancas_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidades(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Gerenciar lideranças','liderancas.gerenciar','Conteúdo','Administrar equipe, pastores e lideranças públicas do portal.',49)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id,pe.id
FROM perfis p
JOIN permissoes pe ON pe.slug='liderancas.gerenciar'
WHERE p.slug IN ('administrador','secretaria','comunicacao','pastor');

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('permalink_lideranca','lideranca','texto'),
('seo_sitemap_liderancas','1','booleano')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);

INSERT INTO menu_itens (menu_id,tipo,titulo,url,ordem,ativo)
SELECT m.id,'link','Lideranças','liderancas',65,1
FROM menus m
WHERE m.localizacao='principal'
  AND NOT EXISTS (
      SELECT 1 FROM menu_itens mi
      WHERE mi.menu_id=m.id
        AND mi.tipo='link'
        AND mi.url IN ('liderancas','/liderancas','liderancas.php')
  );
