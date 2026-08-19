-- Portal IECLB Parobé v0.20.0
-- Auditoria ampliada e segurança administrativa.

ALTER TABLE logs
    ADD COLUMN IF NOT EXISTS nivel VARCHAR(20) NOT NULL DEFAULT 'info' AFTER ip,
    ADD COLUMN IF NOT EXISTS metodo VARCHAR(10) NULL AFTER nivel,
    ADD COLUMN IF NOT EXISTS rota VARCHAR(255) NULL AFTER metodo,
    ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL AFTER rota,
    ADD COLUMN IF NOT EXISTS request_id VARCHAR(64) NULL AFTER user_agent;

CREATE INDEX IF NOT EXISTS idx_logs_nivel_created ON logs (nivel, created_at);
CREATE INDEX IF NOT EXISTS idx_logs_usuario_created ON logs (usuario_id, created_at);
CREATE INDEX IF NOT EXISTS idx_logs_acao_created ON logs (acao, created_at);

CREATE TABLE IF NOT EXISTS login_tentativas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(45) NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_tentativas_email_data (email, created_at),
    INDEX idx_login_tentativas_ip_data (ip, created_at),
    INDEX idx_login_tentativas_sucesso_data (sucesso, created_at)
) ENGINE=InnoDB;

INSERT INTO permissoes (nome, slug, grupo, descricao, ordem) VALUES
('Visualizar auditoria', 'auditoria.visualizar', 'Administração', 'Consultar e exportar registros de auditoria e segurança.', 75),
('Gerenciar segurança', 'seguranca.gerenciar', 'Administração', 'Configurar sessão, bloqueio de login e retenção da auditoria.', 76)
ON DUPLICATE KEY UPDATE
    nome=VALUES(nome), grupo=VALUES(grupo), descricao=VALUES(descricao), ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id
FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug='administrador'
  AND pe.slug IN ('auditoria.visualizar','seguranca.gerenciar');

INSERT INTO configuracoes (chave, valor, tipo) VALUES
('security_session_timeout_minutes','60','numero'),
('security_max_login_attempts','5','numero'),
('security_lockout_minutes','15','numero'),
('security_audit_retention_days','180','numero'),
('security_log_failed_logins','1','booleano')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
