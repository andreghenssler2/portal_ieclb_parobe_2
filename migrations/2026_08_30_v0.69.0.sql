CREATE TABLE IF NOT EXISTS usuario_admin_atalhos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    rota VARCHAR(255) NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    favorito TINYINT(1) NOT NULL DEFAULT 0,
    acessos INT UNSIGNED NOT NULL DEFAULT 1,
    ultimo_acesso_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_admin_atalho (usuario_id, rota),
    KEY idx_usuario_admin_favorito (usuario_id, favorito, updated_at),
    KEY idx_usuario_admin_recente (usuario_id, ultimo_acesso_em)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
