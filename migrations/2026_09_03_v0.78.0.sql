CREATE TABLE IF NOT EXISTS formulario_resposta_replicas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    resposta_id BIGINT UNSIGNED NOT NULL,
    formulario_id INT UNSIGNED NOT NULL,
    usuario_id INT NULL,
    usuario_nome VARCHAR(190) NULL,
    destinatario VARCHAR(190) NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    mensagem MEDIUMTEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'enviado',
    erro VARCHAR(2000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_form_resp_rep_resposta (resposta_id,created_at),
    KEY idx_form_resp_rep_formulario (formulario_id,created_at),
    KEY idx_form_resp_rep_status (status,created_at)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
