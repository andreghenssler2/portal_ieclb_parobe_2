-- Portal IECLB Parobé v0.12.0 - Configurações avançadas
-- Nenhuma tabela nova: apenas novas chaves em configuracoes.
INSERT INTO configuracoes (chave,valor,tipo) VALUES
('site_timezone','America/Sao_Paulo','texto'),
('date_format','d/m/Y','texto'),
('time_format','H:i','texto'),
('writing_default_category','','numero'),
('writing_default_status','rascunho','texto'),
('writing_excerpt_length','180','numero'),
('reading_home_posts','9','numero'),
('reading_home_events','6','numero'),
('reading_home_galleries','3','numero'),
('reading_home_communities','10','numero'),
('media_upload_max_mb','10','numero'),
('media_organize_year_month','1','booleano'),
('media_allow_documents','1','booleano'),
('media_delete_file_on_delete','1','booleano'),
('permalink_noticia','noticia','texto'),
('permalink_pagina','pagina','texto'),
('permalink_evento','evento','texto'),
('permalink_galeria','galeria','texto'),
('permalink_formulario','formulario','texto'),
('privacy_page_id','','numero'),
('privacy_footer_link','1','booleano'),
('privacy_allow_search_engines','1','booleano')
ON DUPLICATE KEY UPDATE chave=VALUES(chave);
