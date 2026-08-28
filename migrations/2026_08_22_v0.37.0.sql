-- Portal IECLB Parobé v0.37.0
-- Tarefas Agendadas / Cron

CREATE TABLE IF NOT EXISTS tarefas_agendadas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(120) NOT NULL,
    nome VARCHAR(180) NOT NULL,
    descricao VARCHAR(500) NULL,
    intervalo_minutos INT UNSIGNED NOT NULL DEFAULT 60,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    prioridade SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    ultima_execucao_em DATETIME NULL,
    proxima_execucao_em DATETIME NULL,
    ultimo_status VARCHAR(20) NULL,
    ultima_mensagem TEXT NULL,
    total_execucoes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    total_erros BIGINT UNSIGNED NOT NULL DEFAULT 0,
    erros_consecutivos INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tarefas_agendadas_slug (slug),
    KEY idx_tarefas_agendadas_due (ativa,proxima_execucao_em,prioridade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tarefas_execucoes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tarefa_id BIGINT UNSIGNED NULL,
    tarefa_slug VARCHAR(120) NOT NULL,
    origem VARCHAR(20) NOT NULL DEFAULT 'cron',
    status VARCHAR(20) NOT NULL DEFAULT 'executando',
    mensagem TEXT NULL,
    iniciada_em DATETIME NOT NULL,
    finalizada_em DATETIME NULL,
    duracao_ms INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tarefas_execucoes_tarefa (tarefa_id,id),
    KEY idx_tarefas_execucoes_status (status,created_at),
    CONSTRAINT fk_tarefas_execucoes_tarefa
        FOREIGN KEY (tarefa_id) REFERENCES tarefas_agendadas(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES (
    'Gerenciar tarefas agendadas',
    'tarefas.gerenciar',
    'Ferramentas',
    'Configurar e executar rotinas automáticas do Portal.',
    75
)
ON DUPLICATE KEY UPDATE
    nome=VALUES(nome),
    grupo=VALUES(grupo),
    descricao=VALUES(descricao),
    ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id,pe.id
FROM perfis p
JOIN permissoes pe ON pe.slug='tarefas.gerenciar'
WHERE p.slug='administrador';
