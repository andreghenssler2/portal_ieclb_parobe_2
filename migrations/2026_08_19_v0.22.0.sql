-- Portal IECLB Parobé v0.22.0
-- Backup completo: não há novas tabelas. Apenas novas configurações.

INSERT INTO configuracoes (chave, valor, tipo) VALUES
('backup_full_retention_count', '5', 'numero'),
('backup_full_include_uploads', '1', 'booleano'),
('backup_full_include_themes', '1', 'booleano')
ON DUPLICATE KEY UPDATE chave = VALUES(chave);
