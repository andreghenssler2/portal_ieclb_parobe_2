-- Portal IECLB Parobé v0.44.0
-- Padrões reutilizáveis de blocos.

CREATE TABLE IF NOT EXISTS conteudo_padroes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nome VARCHAR(160) NOT NULL,
    descricao VARCHAR(500) NULL,
    escopo VARCHAR(20) NOT NULL DEFAULT 'geral',
    blocos_json LONGTEXT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conteudo_padroes_escopo (escopo,ativo,nome)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
