-- Portal IECLB Parobé - migração v0.8.0
-- Formulários públicos + respostas

CREATE TABLE IF NOT EXISTS formularios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    autor_id INT UNSIGNED NOT NULL,
    titulo VARCHAR(220) NOT NULL,
    slug VARCHAR(240) NOT NULL UNIQUE,
    descricao TEXT NULL,
    mensagem_sucesso VARCHAR(500) NULL,
    status ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    publicado_em DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_formularios_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id),
    INDEX idx_formularios_status (status, ativo, publicado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formulario_campos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    formulario_id INT UNSIGNED NOT NULL,
    tipo ENUM('texto','email','telefone','numero','data','textarea','select','checkbox') NOT NULL DEFAULT 'texto',
    rotulo VARCHAR(180) NOT NULL,
    nome VARCHAR(120) NOT NULL,
    placeholder VARCHAR(255) NULL,
    opcoes TEXT NULL,
    obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_formulario_campos_formulario FOREIGN KEY (formulario_id) REFERENCES formularios(id) ON DELETE CASCADE,
    UNIQUE KEY uk_formulario_campo_nome (formulario_id, nome),
    INDEX idx_formulario_campos_ordem (formulario_id, ativo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formulario_respostas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    formulario_id INT UNSIGNED NOT NULL,
    status ENUM('nova','lida','arquivada') NOT NULL DEFAULT 'nova',
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    origem VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_formulario_respostas_formulario FOREIGN KEY (formulario_id) REFERENCES formularios(id) ON DELETE CASCADE,
    INDEX idx_formulario_respostas_status (formulario_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formulario_resposta_valores (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resposta_id BIGINT UNSIGNED NOT NULL,
    campo_id BIGINT UNSIGNED NOT NULL,
    valor LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_form_resp_val_resposta FOREIGN KEY (resposta_id) REFERENCES formulario_respostas(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_resp_val_campo FOREIGN KEY (campo_id) REFERENCES formulario_campos(id) ON DELETE CASCADE,
    UNIQUE KEY uk_resposta_campo (resposta_id, campo_id),
    INDEX idx_form_resp_val_campo (campo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome, slug, grupo, descricao, ordem) VALUES
('Gerenciar formulários', 'formularios.gerenciar', 'Conteúdo', 'Criar formulários públicos, consultar e exportar respostas.', 47)
ON DUPLICATE KEY UPDATE nome=VALUES(nome), grupo=VALUES(grupo), descricao=VALUES(descricao), ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug IN ('administrador','secretaria','comunicacao') AND pe.slug='formularios.gerenciar';

-- Cria um formulário de contato inicial apenas se ainda não existir.
INSERT INTO formularios (autor_id,titulo,slug,descricao,mensagem_sucesso,status,ativo,publicado_em)
SELECT u.id,
       'Contato',
       'contato',
       'Entre em contato com a Paróquia Evangélica de Confissão Luterana de Parobé.',
       'Sua mensagem foi enviada com sucesso. Agradecemos o contato!',
       'publicado',1,NOW()
FROM usuarios u
INNER JOIN perfis p ON p.id=u.perfil_id
WHERE p.slug='administrador'
  AND u.ativo=1
  AND NOT EXISTS (SELECT 1 FROM formularios f WHERE f.slug='contato')
ORDER BY u.id ASC
LIMIT 1;

INSERT INTO formulario_campos (formulario_id,tipo,rotulo,nome,placeholder,obrigatorio,ordem)
SELECT f.id,'texto','Nome','nome','Seu nome completo',1,10 FROM formularios f
WHERE f.slug='contato' AND NOT EXISTS (SELECT 1 FROM formulario_campos c WHERE c.formulario_id=f.id AND c.nome='nome');
INSERT INTO formulario_campos (formulario_id,tipo,rotulo,nome,placeholder,obrigatorio,ordem)
SELECT f.id,'email','E-mail','email','seu@email.com',1,20 FROM formularios f
WHERE f.slug='contato' AND NOT EXISTS (SELECT 1 FROM formulario_campos c WHERE c.formulario_id=f.id AND c.nome='email');
INSERT INTO formulario_campos (formulario_id,tipo,rotulo,nome,placeholder,obrigatorio,ordem)
SELECT f.id,'telefone','Telefone','telefone','(51) 99999-9999',0,30 FROM formularios f
WHERE f.slug='contato' AND NOT EXISTS (SELECT 1 FROM formulario_campos c WHERE c.formulario_id=f.id AND c.nome='telefone');
INSERT INTO formulario_campos (formulario_id,tipo,rotulo,nome,placeholder,obrigatorio,ordem)
SELECT f.id,'texto','Assunto','assunto','Assunto da mensagem',1,40 FROM formularios f
WHERE f.slug='contato' AND NOT EXISTS (SELECT 1 FROM formulario_campos c WHERE c.formulario_id=f.id AND c.nome='assunto');
INSERT INTO formulario_campos (formulario_id,tipo,rotulo,nome,placeholder,obrigatorio,ordem)
SELECT f.id,'textarea','Mensagem','mensagem','Escreva sua mensagem',1,50 FROM formularios f
WHERE f.slug='contato' AND NOT EXISTS (SELECT 1 FROM formulario_campos c WHERE c.formulario_id=f.id AND c.nome='mensagem');
