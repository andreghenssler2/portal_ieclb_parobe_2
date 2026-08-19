-- Portal IECLB Parobé v0.19.0 - Editor de Temas
INSERT INTO permissoes (nome, slug, grupo, descricao, ordem)
VALUES ('Editor de Temas', 'tema_editor.gerenciar', 'Aparência', 'Editar arquivos permitidos dos temas e restaurar backups.', 86)
ON DUPLICATE KEY UPDATE
    nome = VALUES(nome),
    grupo = VALUES(grupo),
    descricao = VALUES(descricao),
    ordem = VALUES(ordem);
