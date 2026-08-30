-- Portal IECLB Parobé v0.61.0
-- Fluxo editorial de Notícias.
--
-- O atualizar_v0.61.0.php aplica esta estrutura de forma idempotente.

ALTER TABLE posts
    ADD COLUMN workflow_status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
    ADD COLUMN workflow_enviado_por INT NULL,
    ADD COLUMN workflow_enviado_em DATETIME NULL,
    ADD COLUMN workflow_revisado_por INT NULL,
    ADD COLUMN workflow_revisado_em DATETIME NULL,
    ADD COLUMN workflow_hash CHAR(64) NULL,
    ADD COLUMN workflow_observacao TEXT NULL;

CREATE INDEX idx_posts_workflow_status
    ON posts (workflow_status);

INSERT INTO permissoes
    (nome, slug, grupo, descricao, ordem)
VALUES
    (
        'Revisar notícias',
        'noticias.revisar',
        'Conteúdo',
        'Aprovar notícias ou solicitar ajustes no fluxo editorial.',
        31
    ),
    (
        'Publicar notícias',
        'noticias.publicar',
        'Conteúdo',
        'Publicar ou agendar notícias aprovadas.',
        32
    )
ON DUPLICATE KEY UPDATE
    nome=VALUES(nome),
    grupo=VALUES(grupo),
    descricao=VALUES(descricao),
    ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes
    (perfil_id, permissao_id)
SELECT
    pf.id,
    pe.id
FROM perfis pf
CROSS JOIN permissoes pe
WHERE pf.slug='administrador'
  AND pe.slug IN (
      'noticias.revisar',
      'noticias.publicar'
  );

INSERT INTO configuracoes
    (chave, valor, tipo)
VALUES
    (
        'writing_require_review',
        '1',
        'booleano'
    )
ON DUPLICATE KEY UPDATE
    chave=VALUES(chave);
