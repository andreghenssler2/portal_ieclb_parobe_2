-- Portal IECLB Parobé v0.16.0
-- Revisões e Lixeira para Notícias e Páginas

CREATE TABLE IF NOT EXISTS revisoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('post','pagina') NOT NULL,
  conteudo_id INT UNSIGNED NOT NULL,
  autor_id INT UNSIGNED NULL,
  dados LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_revisoes_tipo_conteudo (tipo, conteudo_id, id),
  INDEX idx_revisoes_autor (autor_id),
  CONSTRAINT fk_revisoes_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE posts
  MODIFY COLUMN status ENUM('rascunho','agendado','publicado','arquivado','lixeira') NOT NULL DEFAULT 'rascunho',
  ADD COLUMN status_anterior VARCHAR(20) NULL AFTER status,
  ADD COLUMN lixeira_em DATETIME NULL AFTER status_anterior;

ALTER TABLE paginas
  MODIFY COLUMN status ENUM('rascunho','agendado','publicado','arquivado','lixeira') NOT NULL DEFAULT 'rascunho',
  ADD COLUMN status_anterior VARCHAR(20) NULL AFTER status,
  ADD COLUMN lixeira_em DATETIME NULL AFTER status_anterior;

INSERT INTO configuracoes (chave, valor, tipo)
VALUES ('writing_revision_limit', '30', 'numero')
ON DUPLICATE KEY UPDATE chave = VALUES(chave);
