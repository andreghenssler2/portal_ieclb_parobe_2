-- Portal IECLB Parobé v0.40.0
CREATE TABLE IF NOT EXISTS post_visualizacoes_diarias (
    post_id BIGINT UNSIGNED NOT NULL,
    data DATE NOT NULL,
    visualizacoes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (post_id,data),
    KEY idx_post_views_data (data),
    KEY idx_post_views_ranking (data,visualizacoes,post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
