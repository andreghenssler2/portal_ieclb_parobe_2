-- Portal IECLB Parobé v0.29.0 - consolidação da Home modular

CREATE TABLE IF NOT EXISTS home_post_categorias (
    post_id BIGINT UNSIGNED NOT NULL,
    categoria_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, categoria_id),
    KEY idx_home_post_categorias_categoria (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO home_post_categorias (post_id, categoria_id)
SELECT id, categoria_id FROM posts
WHERE categoria_id IS NOT NULL AND categoria_id > 0;
