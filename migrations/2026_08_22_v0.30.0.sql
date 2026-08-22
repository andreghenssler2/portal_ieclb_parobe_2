-- Portal IECLB Parobé v0.30.0
-- Saúde do Portal e hardening administrativo.

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Visualizar Saúde do Portal','saude.visualizar','Ferramentas','Executar diagnósticos de servidor, banco, arquivos, segurança, URLs e e-mail.',87)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id, pe.id
FROM perfis p
JOIN permissoes pe ON pe.slug='saude.visualizar'
WHERE p.slug='administrador';
