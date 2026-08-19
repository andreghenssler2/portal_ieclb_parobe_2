-- Portal IECLB Parobé v0.21.0
-- Backups, manutenção e limpeza.

INSERT INTO permissoes (nome, slug, grupo, descricao, ordem)
VALUES
('Gerenciar backups', 'backups.gerenciar', 'Administração', 'Criar, baixar, excluir e restaurar backups do banco de dados.', 77),
('Gerenciar manutenção', 'manutencao.gerenciar', 'Administração', 'Ativar modo manutenção e executar rotinas de limpeza.', 78)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ordem = VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id)
SELECT pf.id, pe.id
FROM perfis pf
CROSS JOIN permissoes pe
WHERE pf.slug = 'administrador'
  AND pe.slug IN ('backups.gerenciar', 'manutencao.gerenciar');

INSERT INTO configuracoes (chave, valor, tipo) VALUES
('backup_retention_count', '10', 'numero'),
('maintenance_enabled', '0', 'booleano'),
('maintenance_title', 'Portal temporariamente em manutenção', 'texto'),
('maintenance_message', 'Estamos realizando melhorias. Tente novamente em alguns instantes.', 'texto'),
('maintenance_expected_end', '', 'texto'),
('maintenance_allow_admins', '1', 'booleano'),
('maintenance_allowed_ips', '', 'texto'),
('maintenance_enabled_at', '', 'texto'),
('tools_theme_backup_retention_days', '90', 'numero')
ON DUPLICATE KEY UPDATE chave = VALUES(chave);
