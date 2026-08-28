-- Portal IECLB Parobé v0.26.0 - melhoria: múltiplas categorias por Post/Notícia

CREATE TABLE IF NOT EXISTS post_categorias (
    post_id INT UNSIGNED NOT NULL,
    categoria_id INT UNSIGNED NOT NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (post_id, categoria_id),
    INDEX idx_post_categorias_categoria (categoria_id, post_id),
    INDEX idx_post_categorias_principal (post_id, principal),
    CONSTRAINT fk_post_categorias_post
        FOREIGN KEY (post_id) REFERENCES posts(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_post_categorias_categoria
        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra a categoria antiga de cada notícia para a relação muitos-para-muitos.
INSERT IGNORE INTO post_categorias (post_id, categoria_id, principal)
SELECT id, categoria_id, 1
FROM posts
WHERE categoria_id IS NOT NULL;
