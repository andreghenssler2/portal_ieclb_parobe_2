-- Portal IECLB Parobé v0.60.0
-- Autenticação em dois fatores TOTP.
--
-- Este arquivo é fornecido para referência/instalação manual.
-- O atualizar_v0.60.0.php aplica a mesma estrutura de forma idempotente.

ALTER TABLE usuarios
    ADD COLUMN totp_secret TEXT NULL,
    ADD COLUMN totp_enabled_at DATETIME NULL,
    ADD COLUMN totp_last_used_step BIGINT NULL;

CREATE TABLE usuario_2fa_recovery_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    codigo_hash VARCHAR(255) NOT NULL,
    usado_em DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_2fa_recovery_user (usuario_id, usado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
