-- Portal IECLB Parobé v0.15.0
-- Comentários e moderação

CREATE TABLE IF NOT EXISTS comentarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id INT UNSIGNED NOT NULL,
  autor_nome VARCHAR(150) NOT NULL,
  autor_email VARCHAR(190) NOT NULL,
  conteudo TEXT NOT NULL,
  status ENUM('pendente','aprovado','spam','lixeira') NOT NULL DEFAULT 'pendente',
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  moderado_por INT UNSIGNED NULL,
  moderado_em DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_comentarios_post_status (post_id, status, created_at),
  INDEX idx_comentarios_status_created (status, created_at),
  CONSTRAINT fk_comentarios_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_comentarios_moderador FOREIGN KEY (moderado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Para bancos já existentes, o atualizar_v0.15.0.php adiciona esta coluna de forma idempotente.
ALTER TABLE posts ADD COLUMN comentarios_ativos TINYINT(1) NOT NULL DEFAULT 1 AFTER destaque;
