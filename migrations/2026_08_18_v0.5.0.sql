-- Portal IECLB Parobé - v0.5.0
-- Usuários, perfis e permissões por módulo.

CREATE TABLE IF NOT EXISTS permissoes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    grupo VARCHAR(80) NOT NULL DEFAULT 'Geral',
    descricao VARCHAR(255) NULL,
    ordem INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_permissoes_grupo_ordem (grupo, ordem)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS perfil_permissoes (
    perfil_id INT UNSIGNED NOT NULL,
    permissao_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (perfil_id, permissao_id),
    CONSTRAINT fk_perfil_permissoes_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE,
    CONSTRAINT fk_perfil_permissoes_permissao FOREIGN KEY (permissao_id) REFERENCES permissoes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO permissoes (nome, slug, grupo, descricao, ordem) VALUES
('Gerenciar notícias', 'noticias.gerenciar', 'Conteúdo', 'Criar, editar e administrar notícias.', 10),
('Gerenciar páginas', 'paginas.gerenciar', 'Conteúdo', 'Criar, editar e administrar páginas institucionais.', 20),
('Gerenciar eventos e cultos', 'eventos.gerenciar', 'Agenda', 'Criar e editar eventos, cultos e agenda.', 30),
('Gerenciar mídia', 'midias.gerenciar', 'Conteúdo', 'Enviar, editar e excluir itens da Biblioteca de Mídia.', 40),
('Gerenciar comunidades', 'comunidades.gerenciar', 'Estrutura', 'Cadastrar e editar comunidades da paróquia.', 50),
('Gerenciar usuários', 'usuarios.gerenciar', 'Administração', 'Criar, editar, ativar e desativar usuários.', 60),
('Gerenciar perfis e permissões', 'permissoes.gerenciar', 'Administração', 'Definir os módulos disponíveis para cada perfil.', 70)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao), ordem = VALUES(ordem);

-- Administrador: todas as permissões.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe WHERE pf.slug = 'administrador';

-- Secretaria: conteúdo, agenda, mídia e comunidades.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'secretaria'
  AND pe.slug IN ('noticias.gerenciar','paginas.gerenciar','eventos.gerenciar','midias.gerenciar','comunidades.gerenciar');

-- Comunicação: conteúdo, agenda e mídia.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'comunicacao'
  AND pe.slug IN ('noticias.gerenciar','paginas.gerenciar','eventos.gerenciar','midias.gerenciar');

-- Pastor: conteúdo, agenda e mídia.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'pastor'
  AND pe.slug IN ('noticias.gerenciar','paginas.gerenciar','eventos.gerenciar','midias.gerenciar');

-- Moderador: notícias e agenda.
INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id FROM perfis pf CROSS JOIN permissoes pe
WHERE pf.slug = 'moderador'
  AND pe.slug IN ('noticias.gerenciar','eventos.gerenciar');
