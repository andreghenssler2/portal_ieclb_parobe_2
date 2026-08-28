-- Portal IECLB Parobé - migração v0.1.0 -> v0.2.0
-- Execute uma única vez no banco já existente.

CREATE TABLE midias (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho VARCHAR(500) NOT NULL UNIQUE,
    mime_type VARCHAR(150) NOT NULL,
    extensao VARCHAR(20) NOT NULL,
    tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
    largura INT UNSIGNED NULL,
    altura INT UNSIGNED NULL,
    titulo VARCHAR(180) NULL,
    alt_text VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_midias_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_midias_mime (mime_type),
    INDEX idx_midias_created (created_at)
) ENGINE=InnoDB;

ALTER TABLE posts
    ADD COLUMN imagem_capa_id BIGINT UNSIGNED NULL AFTER imagem_capa,
    ADD INDEX idx_posts_imagem_capa (imagem_capa_id),
    ADD CONSTRAINT fk_posts_imagem_capa FOREIGN KEY (imagem_capa_id) REFERENCES midias(id) ON DELETE SET NULL;
