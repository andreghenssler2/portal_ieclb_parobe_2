-- Portal IECLB Parobé - migração v0.7.0
-- Galerias de Fotos + Banners da Home

CREATE TABLE IF NOT EXISTS galerias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autor_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(220) NOT NULL,
    slug VARCHAR(240) NOT NULL UNIQUE,
    descricao TEXT NULL,
    imagem_capa_id BIGINT UNSIGNED NULL,
    status ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
    publicado_em DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_galerias_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
    CONSTRAINT fk_galerias_capa FOREIGN KEY (imagem_capa_id) REFERENCES midias(id) ON DELETE SET NULL,
    INDEX idx_galerias_status_data (status, publicado_em),
    INDEX idx_galerias_capa (imagem_capa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS galeria_midias (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    galeria_id INT UNSIGNED NOT NULL,
    midia_id BIGINT UNSIGNED NOT NULL,
    legenda VARCHAR(255) NULL,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_galeria_midias_galeria FOREIGN KEY (galeria_id) REFERENCES galerias(id) ON DELETE CASCADE,
    CONSTRAINT fk_galeria_midias_midia FOREIGN KEY (midia_id) REFERENCES midias(id) ON DELETE CASCADE,
    UNIQUE KEY uk_galeria_midia (galeria_id, midia_id),
    INDEX idx_galeria_midias_ordem (galeria_id, ordem),
    INDEX idx_galeria_midias_midia (midia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(180) NULL,
    subtitulo VARCHAR(500) NULL,
    imagem_id BIGINT UNSIGNED NOT NULL,
    url_link VARCHAR(500) NULL,
    texto_botao VARCHAR(80) NULL,
    nova_aba TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    data_inicio DATETIME NULL,
    data_fim DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_banners_imagem FOREIGN KEY (imagem_id) REFERENCES midias(id) ON DELETE CASCADE,
    INDEX idx_banners_exibicao (ativo, data_inicio, data_fim, ordem),
    INDEX idx_banners_imagem (imagem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome, slug, grupo, descricao, ordem) VALUES
('Gerenciar galerias', 'galerias.gerenciar', 'Conteúdo', 'Criar, editar e publicar galerias de fotos.', 45),
('Gerenciar banners', 'banners.gerenciar', 'Conteúdo', 'Administrar os banners e destaques da página inicial.', 46)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao), ordem = VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug IN ('administrador','secretaria','comunicacao') AND pe.slug='galerias.gerenciar';

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug IN ('administrador','comunicacao') AND pe.slug='banners.gerenciar';
