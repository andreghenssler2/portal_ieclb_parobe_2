-- Portal IECLB Parobé v0.38.1
-- Remoção da otimização de imagens

DELETE FROM tarefas_agendadas
WHERE slug='otimizar_midias_pendentes';

DELETE FROM configuracoes
WHERE chave IN (
    'media_optimize_enabled',
    'media_generate_webp',
    'media_variant_widths',
    'media_image_quality'
);

-- Os arquivos derivados devem ser removidos pelo atualizador PHP antes desta operação.
DROP TABLE IF EXISTS midia_variantes;
