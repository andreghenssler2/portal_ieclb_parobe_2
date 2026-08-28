-- Portal IECLB Parobé v0.22.0 - correção/expansão de Categorias de Posts
-- Permite categorias hierárquicas (Categoria ascendente / subcategorias).

ALTER TABLE categorias
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao;

CREATE INDEX idx_categorias_parent_id ON categorias (parent_id);

ALTER TABLE categorias
    ADD CONSTRAINT fk_categorias_parent
    FOREIGN KEY (parent_id) REFERENCES categorias(id)
    ON DELETE SET NULL
    ON UPDATE CASCADE;
