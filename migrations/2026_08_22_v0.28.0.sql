-- Portal IECLB Parobé v0.28.0 - Página inicial modular

CREATE TABLE IF NOT EXISTS home_secoes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    titulo VARCHAR(160) NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'carousel',
    origem VARCHAR(30) NOT NULL DEFAULT 'posts',
    categoria_id INT UNSIGNED NULL,
    link_texto VARCHAR(80) NULL,
    link_url VARCHAR(500) NULL,
    limite TINYINT UNSIGNED NOT NULL DEFAULT 8,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 10,
    configuracao_json TEXT NULL,
    usuario_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_home_secoes_ativo_ordem (ativo, ordem),
    KEY idx_home_secoes_categoria (categoria_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Gerenciar Página Inicial','home.gerenciar','Aparência','Adicionar, remover, ordenar e configurar as seções da página inicial.',44)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id, pe.id FROM perfis p JOIN permissoes pe ON pe.slug='home.gerenciar' WHERE p.slug='administrador';
