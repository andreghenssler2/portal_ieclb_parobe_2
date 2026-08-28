-- Portal IECLB Parobé v0.27.0
-- Importador WordPress por módulo

CREATE TABLE IF NOT EXISTS wordpress_importacoes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    origem_url VARCHAR(500) NOT NULL,
    origem_hash CHAR(64) NOT NULL,
    modulo VARCHAR(30) NOT NULL,
    fase VARCHAR(30) NOT NULL,
    eventos_endpoint VARCHAR(500) NULL,
    modo VARCHAR(20) NOT NULL DEFAULT 'new',
    opcoes_json TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'aguardando',
    pagina_atual INT UNSIGNED NOT NULL DEFAULT 1,
    total_paginas_fase INT UNSIGNED NOT NULL DEFAULT 0,
    total_itens_fase INT UNSIGNED NOT NULL DEFAULT 0,
    processados INT UNSIGNED NOT NULL DEFAULT 0,
    criados INT UNSIGNED NOT NULL DEFAULT 0,
    atualizados INT UNSIGNED NOT NULL DEFAULT 0,
    ignorados INT UNSIGNED NOT NULL DEFAULT 0,
    erros INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_erro TEXT NULL,
    iniciado_em DATETIME NULL,
    finalizado_em DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wordpress_importacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_wp_import_status (status, created_at),
    INDEX idx_wp_import_origem (origem_hash, modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wordpress_import_map (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    origem_hash CHAR(64) NOT NULL,
    origem_url VARCHAR(500) NOT NULL,
    wp_id BIGINT UNSIGNED NOT NULL,
    wp_tipo VARCHAR(100) NOT NULL,
    wp_parent_id BIGINT UNSIGNED NULL,
    wp_slug VARCHAR(255) NULL,
    wp_modified DATETIME NULL,
    source_url TEXT NULL,
    local_id BIGINT UNSIGNED NOT NULL,
    local_tipo VARCHAR(100) NOT NULL,
    local_url TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_wp_import_origem_tipo_id (origem_hash, wp_tipo, wp_id),
    INDEX idx_wp_import_local (local_tipo, local_id),
    INDEX idx_wp_import_parent (origem_hash, wp_tipo, wp_parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wordpress_import_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    importacao_id BIGINT UNSIGNED NOT NULL,
    nivel VARCHAR(20) NOT NULL,
    wp_id BIGINT UNSIGNED NULL,
    mensagem TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wordpress_import_logs_importacao FOREIGN KEY (importacao_id) REFERENCES wordpress_importacoes(id) ON DELETE CASCADE,
    INDEX idx_wp_import_logs_job (importacao_id, id),
    INDEX idx_wp_import_logs_level (nivel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Importar WordPress','wordpress.importar','Ferramentas','Importar conteúdos e mídias de sites WordPress pela REST API.',86)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id, pe.id FROM perfis p JOIN permissoes pe ON pe.slug='wordpress.importar' WHERE p.slug='administrador';
