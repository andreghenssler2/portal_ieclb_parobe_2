CREATE TABLE IF NOT EXISTS content_autosaves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    content_type VARCHAR(20) NOT NULL,
    content_id INT UNSIGNED NULL,
    draft_key VARCHAR(80) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_content_autosave_user_key (user_id,draft_key),
    KEY idx_content_autosave_updated (updated_at),
    KEY idx_content_autosave_content
        (content_type,content_id,user_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
