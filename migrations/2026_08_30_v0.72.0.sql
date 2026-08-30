CREATE TABLE IF NOT EXISTS midia_variantes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    midia_id INT NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    caminho VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    largura INT NULL,
    altura INT NULL,
    tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_midia_variante (midia_id,tipo),
    KEY idx_midia_variante_tipo (tipo),
    KEY idx_midia_variante_midia (midia_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
