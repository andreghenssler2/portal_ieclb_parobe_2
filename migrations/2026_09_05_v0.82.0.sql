ALTER TABLE paginas
    ADD COLUMN exibir_imagem_capa TINYINT(1) NOT NULL DEFAULT 0
    AFTER imagem_capa_id;
