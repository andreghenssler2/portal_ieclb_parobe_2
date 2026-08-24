-- Portal IECLB Parobé v0.43.0
-- Blocos de conteúdo + subpáginas.

ALTER TABLE paginas
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER autor_id;

CREATE INDEX idx_paginas_parent
    ON paginas (parent_id,ordem,id);

ALTER TABLE paginas
    ADD CONSTRAINT fk_paginas_parent
    FOREIGN KEY (parent_id) REFERENCES paginas(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS conteudo_blocos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_conteudo VARCHAR(20) NOT NULL,
    conteudo_id BIGINT UNSIGNED NOT NULL,
    tipo_bloco VARCHAR(30) NOT NULL,
    dados_json LONGTEXT NOT NULL,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conteudo_blocos_item
        (tipo_conteudo,conteudo_id,ordem,id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
