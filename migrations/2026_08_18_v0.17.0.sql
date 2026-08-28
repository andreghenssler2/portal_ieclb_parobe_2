-- Portal IECLB Parobé - v0.17.0
-- Sitemap Index + sub-sitemaps por tipo + extensão de imagens

INSERT INTO configuracoes (chave, valor, tipo) VALUES
('seo_sitemap_geral', '1', 'booleano'),
('seo_sitemap_tags', '1', 'booleano'),
('seo_sitemap_imagens', '1', 'booleano')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
