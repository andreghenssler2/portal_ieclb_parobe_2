CREATE TABLE IF NOT EXISTS admin_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    notification_key VARCHAR(190) NOT NULL,
    title VARCHAR(220) NOT NULL,
    message TEXT NULL,
    target_url VARCHAR(500) NULL,
    icon VARCHAR(80) NOT NULL DEFAULT 'bi-bell',
    level VARCHAR(30) NOT NULL DEFAULT 'primary',
    source_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at DATETIME NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_notification_user_key
        (user_id,notification_key),
    KEY idx_admin_notification_unread
        (user_id,is_read,resolved_at,updated_at),
    KEY idx_admin_notification_recent
        (user_id,updated_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
