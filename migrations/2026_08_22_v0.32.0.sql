-- Portal IECLB Parobé v0.32.0 - otimização de imagens

CREATE TABLE IF NOT EXISTS midia_variantes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    midia_id BIGINT UNSIGNED NOT NULL,
    largura INT UNSIGNED NOT NULL,
    altura INT UNSIGNED NOT NULL,
    formato VARCHAR(12) NOT NULL,
    mime_type VARCHAR(80) NOT NULL,
    caminho VARCHAR(500) NOT NULL,
    tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
    qualidade TINYINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_midia_variante (midia_id, largura, formato),
    UNIQUE KEY uq_midia_variante_caminho (caminho),
    KEY idx_midia_variantes_midia (midia_id),
    CONSTRAINT fk_midia_variantes_midia FOREIGN KEY (midia_id) REFERENCES midias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('media_optimize_enabled','1','booleano'),
('media_generate_webp','1','booleano'),
('media_variant_widths','320,640,1024,1600','texto'),
('media_image_quality','82','numero')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
