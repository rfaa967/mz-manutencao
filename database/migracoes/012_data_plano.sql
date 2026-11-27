-- 012: data original da ocorrência do plano na OT preventiva (adiar a data prevista não desloca o calendário do plano)
ALTER TABLE ordens_trabalho ADD COLUMN data_plano DATE NULL AFTER data_prevista;
UPDATE ordens_trabalho o JOIN planos_manutencao p ON p.id = o.plano_id
   SET o.data_plano = o.data_prevista
 WHERE o.data_plano IS NULL AND p.gatilho = 'tempo' AND o.status IN ('aberta','planejada','em_execucao','aguardando_pecas')
