-- 008: marca as leituras de troca/zeragem do contador (separa as escalas antiga e nova no histórico)
ALTER TABLE leituras_contador ADD COLUMN zeragem TINYINT(1) NOT NULL DEFAULT 0 AFTER valor;
UPDATE leituras_contador SET zeragem = 1 WHERE observacao LIKE 'Troca/zeragem%';
