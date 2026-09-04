CREATE TABLE IF NOT EXISTS formulario_resposta_entradas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resposta_id BIGINT UNSIGNED NOT NULL,
    formulario_id INT UNSIGNED NOT NULL,
    mailbox_key CHAR(64) NOT NULL,
    message_uid BIGINT UNSIGNED NOT NULL,
    message_id VARCHAR(500) NULL,
    remetente VARCHAR(190) NOT NULL,
    assunto VARCHAR(500) NULL,
    mensagem MEDIUMTEXT NOT NULL,
    received_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_form_resp_in_mailbox_uid (mailbox_key,message_uid),
    KEY idx_form_resp_in_resposta (resposta_id,received_at),
    KEY idx_form_resp_in_formulario (formulario_id,received_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
