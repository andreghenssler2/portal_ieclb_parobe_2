CREATE TABLE IF NOT EXISTS evento_categorias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    descricao VARCHAR(255) NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_evento_categorias_ativa_ordem (ativa, ordem)
) ENGINE=InnoDB;

ALTER TABLE eventos
    ADD COLUMN categoria_evento_id INT UNSIGNED NULL AFTER comunidade_id,
    ADD INDEX idx_eventos_categoria (categoria_evento_id),
    ADD CONSTRAINT fk_eventos_categoria
        FOREIGN KEY (categoria_evento_id) REFERENCES evento_categorias(id) ON DELETE SET NULL;

INSERT IGNORE INTO evento_categorias (nome, slug, descricao, ativa, ordem) VALUES
('Juventude', 'juventude', 'Encontros, ações e atividades da juventude.', 1, 10),
('Crianças', 'criancas', 'Atividades, cultos e encontros com crianças.', 1, 20),
('Famílias', 'familias', 'Encontros e atividades voltadas às famílias.', 1, 30),
('Formação', 'formacao', 'Cursos, estudos, palestras e momentos formativos.', 1, 40),
('Reuniões', 'reunioes', 'Reuniões de grupos, lideranças e conselhos.', 1, 50),
('Festas e confraternizações', 'festas-e-confraternizacoes', 'Festas, almoços e momentos de convivência.', 1, 60),
('Ação Social', 'acao-social', 'Campanhas, arrecadações e ações solidárias.', 1, 70);
