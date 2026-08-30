CREATE TABLE IF NOT EXISTS security_csp_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint CHAR(64) NOT NULL,
    document_uri VARCHAR(500) NULL,
    effective_directive VARCHAR(120) NULL,
    violated_directive VARCHAR(255) NULL,
    blocked_uri VARCHAR(500) NULL,
    source_file VARCHAR(500) NULL,
    line_number INT UNSIGNED NULL,
    column_number INT UNSIGNED NULL,
    disposition VARCHAR(30) NULL,
    status_code INT NULL,
    occurrences INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_security_csp_fingerprint (fingerprint),
    KEY idx_security_csp_last_seen (last_seen_at),
    KEY idx_security_csp_directive (effective_directive,last_seen_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
