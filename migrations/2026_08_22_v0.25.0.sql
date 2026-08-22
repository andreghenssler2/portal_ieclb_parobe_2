-- Portal IECLB Parobé v0.25.0 - Newsletter

CREATE TABLE IF NOT EXISTS newsletter_assinantes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    status ENUM('pendente','ativo','cancelado') NOT NULL DEFAULT 'pendente',
    token_confirmacao CHAR(64) NULL,
    token_cancelamento CHAR(64) NOT NULL,
    origem VARCHAR(80) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    confirmado_em DATETIME NULL,
    cancelado_em DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_newsletter_assinantes_status (status, created_at),
    INDEX idx_newsletter_assinantes_confirmacao (token_confirmacao),
    INDEX idx_newsletter_assinantes_cancelamento (token_cancelamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_campanhas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autor_id INT UNSIGNED NULL,
    assunto VARCHAR(220) NOT NULL,
    preheader VARCHAR(255) NULL,
    conteudo LONGTEXT NOT NULL,
    status ENUM('rascunho','enviando','enviado') NOT NULL DEFAULT 'rascunho',
    iniciado_em DATETIME NULL,
    enviado_em DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_newsletter_campanha_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_newsletter_campanhas_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS newsletter_envios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campanha_id BIGINT UNSIGNED NOT NULL,
    assinante_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL,
    status ENUM('enviado','falhou') NOT NULL,
    tentativas TINYINT UNSIGNED NOT NULL DEFAULT 1,
    enviado_em DATETIME NULL,
    erro TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_newsletter_envio_campanha FOREIGN KEY (campanha_id) REFERENCES newsletter_campanhas(id) ON DELETE CASCADE,
    CONSTRAINT fk_newsletter_envio_assinante FOREIGN KEY (assinante_id) REFERENCES newsletter_assinantes(id) ON DELETE CASCADE,
    UNIQUE KEY uk_newsletter_envio (campanha_id, assinante_id),
    INDEX idx_newsletter_envios_status (campanha_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem) VALUES
('Gerenciar newsletter','newsletter.gerenciar','Comunicação','Gerenciar assinantes, campanhas e configurações da newsletter.',49)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('newsletter_enabled','1','booleano'),
('newsletter_double_optin','1','booleano'),
('newsletter_from_name','Portal IECLB Parobé','texto'),
('newsletter_from_email','','texto'),
('newsletter_title','Receba nossas novidades','texto'),
('newsletter_description','Cadastre seu e-mail para receber notícias, agenda e informações da IECLB Parobé.','texto')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
