-- Portal IECLB Parobé - base para instalação nova
-- Base histórica equivalente à v0.2.0, preparada para instalação idempotente.

CREATE TABLE IF NOT EXISTS perfis (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    perfil_id INT UNSIGNED NOT NULL,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    senha VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_usuarios_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS midias (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    nome_original VARCHAR(255) NOT NULL,
    nome_arquivo VARCHAR(255) NOT NULL,
    caminho VARCHAR(500) NOT NULL UNIQUE,
    mime_type VARCHAR(150) NOT NULL,
    extensao VARCHAR(20) NOT NULL,
    tamanho BIGINT UNSIGNED NOT NULL DEFAULT 0,
    largura INT UNSIGNED NULL,
    altura INT UNSIGNED NULL,
    titulo VARCHAR(180) NULL,
    alt_text VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_midias_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_midias_mime (mime_type),
    INDEX idx_midias_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comunidades (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    descricao TEXT NULL,
    endereco VARCHAR(255) NULL,
    cidade VARCHAR(120) NULL,
    uf CHAR(2) NULL,
    imagem VARCHAR(255) NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    descricao VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autor_id INT UNSIGNED NOT NULL,
    comunidade_id INT UNSIGNED NULL,
    categoria_id INT UNSIGNED NULL,
    titulo VARCHAR(220) NOT NULL,
    slug VARCHAR(240) NOT NULL UNIQUE,
    resumo TEXT NULL,
    conteudo LONGTEXT NOT NULL,
    imagem_capa VARCHAR(255) NULL,
    imagem_capa_id BIGINT UNSIGNED NULL,
    status ENUM('rascunho','agendado','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
    destaque TINYINT(1) NOT NULL DEFAULT 0,
    publicado_em DATETIME NULL,
    visualizacoes INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_posts_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
    CONSTRAINT fk_posts_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidades(id) ON DELETE SET NULL,
    CONSTRAINT fk_posts_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL,
    CONSTRAINT fk_posts_imagem_capa FOREIGN KEY (imagem_capa_id) REFERENCES midias(id) ON DELETE SET NULL,
    INDEX idx_posts_status_data (status, publicado_em),
    INDEX idx_posts_comunidade (comunidade_id),
    INDEX idx_posts_imagem_capa (imagem_capa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configuracoes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chave VARCHAR(120) NOT NULL UNIQUE,
    valor LONGTEXT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'texto',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NULL,
    acao VARCHAR(120) NOT NULL,
    entidade VARCHAR(100) NULL,
    entidade_id BIGINT UNSIGNED NULL,
    detalhes TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_logs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO perfis (nome,slug) VALUES
('Administrador','administrador'),
('Secretaria','secretaria'),
('Comunicação','comunicacao'),
('Pastor','pastor'),
('Moderador','moderador')
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO comunidades (nome,slug,cidade,uf,ordem) VALUES
('Parobé','parobe','Parobé','RS',1),
('Entrepelado','entrepelado','Taquara','RS',2),
('Fazenda Fialho','fazenda-fialho','Taquara','RS',3),
('Santa Cruz do Pinhal','santa-cruz-do-pinhal','Taquara','RS',4),
('Passo dos Ferreiros','passo-dos-ferreiros','Parobé','RS',5)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),cidade=VALUES(cidade),uf=VALUES(uf),ordem=VALUES(ordem);

INSERT INTO categorias (nome,slug) VALUES
('Notícias','noticias'),
('Cultos','cultos'),
('Eventos','eventos'),
('Juventude','juventude'),
('OASE','oase')
ON DUPLICATE KEY UPDATE nome=VALUES(nome);

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('site_nome','Paróquia Evangélica de Confissão Luterana de Parobé','texto'),
('site_descricao','Portal da IECLB Parobé','texto'),
('site_email','','email'),
('site_telefone','','texto'),
('site_instagram','','url'),
('site_youtube','','url'),
('site_facebook','','url')
ON DUPLICATE KEY UPDATE valor=VALUES(valor),tipo=VALUES(tipo);
