-- Portal IECLB Parobé v0.35.0
-- Documentos / Downloads

CREATE TABLE IF NOT EXISTS documento_categorias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    descricao TEXT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documento_categorias_slug (slug),
    KEY idx_documento_categorias_ativo_ordem (ativo,ordem,nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    autor_id INT UNSIGNED NULL,
    categoria_id INT UNSIGNED NULL,
    midia_id BIGINT UNSIGNED NULL,
    titulo VARCHAR(220) NOT NULL,
    slug VARCHAR(240) NOT NULL,
    descricao LONGTEXT NULL,
    status ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
    ordem INT NOT NULL DEFAULT 0,
    publicado_em DATETIME NULL,
    seo_titulo VARCHAR(220) NULL,
    seo_descricao VARCHAR(320) NULL,
    seo_noindex TINYINT(1) NOT NULL DEFAULT 0,
    downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_documentos_slug (slug),
    KEY idx_documentos_status_data (status,publicado_em),
    KEY idx_documentos_categoria (categoria_id,status),
    KEY idx_documentos_midia (midia_id),
    CONSTRAINT fk_documentos_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_documentos_categoria FOREIGN KEY (categoria_id) REFERENCES documento_categorias(id) ON DELETE SET NULL,
    CONSTRAINT fk_documentos_midia FOREIGN KEY (midia_id) REFERENCES midias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Gerenciar documentos','documentos.gerenciar','Conteúdo','Publicar e organizar documentos e downloads públicos.',48)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id, pe.id
FROM perfis p
JOIN permissoes pe ON pe.slug='documentos.gerenciar'
WHERE p.slug IN ('administrador','secretaria','comunicacao');

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('permalink_documento','documento','texto'),
('seo_sitemap_documentos','1','booleano')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);

INSERT INTO menu_itens (menu_id,tipo,titulo,url,ordem,ativo)
SELECT m.id,'link','Documentos','documentos',70,1
FROM menus m
WHERE m.localizacao='principal'
  AND NOT EXISTS (
      SELECT 1 FROM menu_itens mi
      WHERE mi.menu_id=m.id
        AND mi.tipo='link'
        AND mi.url IN ('documentos','/documentos','documentos.php')
  );
