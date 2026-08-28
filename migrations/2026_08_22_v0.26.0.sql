-- Portal IECLB Parobé v0.26.0
-- E-mail/SMTP centralizado

CREATE TABLE IF NOT EXISTS email_envios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    transport ENUM('mail','smtp') NOT NULL,
    destinatario VARCHAR(190) NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    status ENUM('enviado','falhou') NOT NULL,
    erro TEXT NULL,
    message_id VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_email_envios_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_email_envios_status_data (status,created_at),
    INDEX idx_email_envios_destinatario (destinatario),
    INDEX idx_email_envios_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Gerenciar e-mail','email.gerenciar','Configurações','Configurar transporte de e-mail, SMTP e testes de envio.',91)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id, pe.id FROM perfis p JOIN permissoes pe ON pe.slug='email.gerenciar' WHERE p.slug='administrador';

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('mail_transport','mail','texto'),
('mail_from_name','Portal IECLB Parobé','texto'),
('mail_from_email','','texto'),
('mail_reply_to','','texto'),
('mail_smtp_host','','texto'),
('mail_smtp_port','587','numero'),
('mail_smtp_encryption','tls','texto'),
('mail_smtp_auth','1','booleano'),
('mail_smtp_username','','texto'),
('mail_smtp_password','','secreto'),
('mail_smtp_verify_peer','1','booleano'),
('mail_timeout_seconds','15','numero'),
('mail_log_retention_days','90','numero')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
