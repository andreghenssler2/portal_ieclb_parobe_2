CREATE TABLE IF NOT EXISTS midia_integridade_relatorios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    origem VARCHAR(30) NOT NULL DEFAULT 'scheduler',
    status VARCHAR(20) NOT NULL DEFAULT 'ok',
    mensagem VARCHAR(1000) NULL,
    arquivos_analisados INT UNSIGNED NOT NULL DEFAULT 0,
    scan_parcial TINYINT(1) NOT NULL DEFAULT 0,
    originais_ausentes INT UNSIGNED NOT NULL DEFAULT 0,
    tamanhos_divergentes INT UNSIGNED NOT NULL DEFAULT 0,
    variantes_ausentes INT UNSIGNED NOT NULL DEFAULT 0,
    arquivos_orfaos INT UNSIGNED NOT NULL DEFAULT 0,
    derivados_orfaos INT UNSIGNED NOT NULL DEFAULT 0,
    registros_variantes_removidos INT UNSIGNED NOT NULL DEFAULT 0,
    derivados_removidos INT UNSIGNED NOT NULL DEFAULT 0,
    bytes_liberados BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_midia_integridade_created (created_at),
    KEY idx_midia_integridade_status (status,created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
