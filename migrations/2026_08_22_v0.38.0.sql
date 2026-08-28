-- Portal IECLB Parobé v0.38.0
-- Notificações de formulários por e-mail

ALTER TABLE formularios
    ADD COLUMN IF NOT EXISTS notificar_email TINYINT(1) NOT NULL DEFAULT 0 AFTER publicado_em,
    ADD COLUMN IF NOT EXISTS emails_notificacao TEXT NULL AFTER notificar_email,
    ADD COLUMN IF NOT EXISTS assunto_notificacao VARCHAR(255) NULL AFTER emails_notificacao,
    ADD COLUMN IF NOT EXISTS resposta_automatica TINYINT(1) NOT NULL DEFAULT 0 AFTER assunto_notificacao,
    ADD COLUMN IF NOT EXISTS campo_email_resposta_id BIGINT UNSIGNED NULL AFTER resposta_automatica,
    ADD COLUMN IF NOT EXISTS assunto_resposta_automatica VARCHAR(255) NULL AFTER campo_email_resposta_id,
    ADD COLUMN IF NOT EXISTS mensagem_resposta_automatica TEXT NULL AFTER assunto_resposta_automatica;

CREATE TABLE IF NOT EXISTS formulario_notificacoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    formulario_id INT UNSIGNED NOT NULL,
    resposta_id BIGINT UNSIGNED NULL,
    tipo ENUM('administrador','resposta_automatica') NOT NULL,
    destinatario VARCHAR(190) NULL,
    status ENUM('enviado','erro','ignorado') NOT NULL,
    erro TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_form_notificacoes_formulario (formulario_id,id),
    KEY idx_form_notificacoes_resposta (resposta_id,id),
    KEY idx_form_notificacoes_status (status,created_at),
    CONSTRAINT fk_form_notificacoes_formulario
        FOREIGN KEY (formulario_id) REFERENCES formularios(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_notificacoes_resposta
        FOREIGN KEY (resposta_id) REFERENCES formulario_respostas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
