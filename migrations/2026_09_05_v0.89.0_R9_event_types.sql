-- Portal IECLB Parobé - Hotfix v0.89.0 R9
-- Tipos oficiais da Agenda:
-- Cultos, Festas, Atividades e Reuniões.

ALTER TABLE eventos
    MODIFY COLUMN tipo
    ENUM('culto','evento','festa','atividade','reuniao')
    NOT NULL DEFAULT 'atividade';

UPDATE eventos
SET tipo = 'atividade'
WHERE tipo = 'evento';

ALTER TABLE eventos
    MODIFY COLUMN tipo
    ENUM('culto','festa','atividade','reuniao')
    NOT NULL DEFAULT 'atividade';
