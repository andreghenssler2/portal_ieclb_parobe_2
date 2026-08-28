-- Portal IECLB Parobé v0.31.0
-- Cache e Performance

INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
VALUES ('Gerenciar performance','performance.gerenciar','Configurações','Configurar cache do Portal e limpar arquivos de cache.',92)
ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem);

INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
SELECT p.id, pe.id
FROM perfis p
JOIN permissoes pe ON pe.slug='performance.gerenciar'
WHERE p.slug='administrador';

INSERT INTO configuracoes (chave,valor,tipo) VALUES
('performance_cache_enabled','1','booleano'),
('performance_page_cache_enabled','1','booleano'),
('performance_cache_ttl_seconds','300','numero'),
('performance_page_cache_ttl_seconds','120','numero')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
