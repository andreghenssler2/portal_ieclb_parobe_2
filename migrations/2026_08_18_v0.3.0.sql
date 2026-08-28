-- Portal IECLB Parobé v0.3.0
-- Execute uma única vez em instalações que já estejam na v0.2.0.

CREATE TABLE IF NOT EXISTS paginas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autor_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(220) NOT NULL,
    slug VARCHAR(240) NOT NULL UNIQUE,
    resumo TEXT NULL,
    conteudo LONGTEXT NOT NULL,
    imagem_capa_id BIGINT UNSIGNED NULL,
    status ENUM('rascunho','agendado','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
    exibir_menu TINYINT(1) NOT NULL DEFAULT 0,
    ordem INT NOT NULL DEFAULT 0,
    publicado_em DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_paginas_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
    CONSTRAINT fk_paginas_imagem_capa FOREIGN KEY (imagem_capa_id) REFERENCES midias(id) ON DELETE SET NULL,
    INDEX idx_paginas_status_data (status, publicado_em),
    INDEX idx_paginas_menu (exibir_menu, ordem),
    INDEX idx_paginas_imagem_capa (imagem_capa_id)
) ENGINE=InnoDB;
