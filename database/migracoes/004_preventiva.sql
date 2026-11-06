-- 004: correções do motor de preventivas
-- dia_ancora: dia do mês original do plano (evita a deriva 31 → 28 → 28...)
ALTER TABLE planos_manutencao ADD COLUMN dia_ancora TINYINT NULL AFTER proxima_data;
UPDATE planos_manutencao SET dia_ancora = DAY(proxima_data) WHERE proxima_data IS NOT NULL AND dia_ancora IS NULL;
-- valor_previsto: valor do contador que disparou a OT (reprogramação idempotente)
ALTER TABLE ordens_trabalho ADD COLUMN valor_previsto DECIMAL(14,2) NULL AFTER leitura_contador;
-- leitura gerada pela conclusão de uma OT (evita leitura duplicada ao reconcluir)
ALTER TABLE leituras_contador ADD COLUMN ot_id INT NULL AFTER usuario_id;
ALTER TABLE leituras_contador ADD INDEX idx_leit_ot (ot_id);
