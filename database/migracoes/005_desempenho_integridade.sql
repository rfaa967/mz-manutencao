-- 005: índices de desempenho, custos desnormalizados da OT e ajustes de integridade
-- Compatível com MySQL 8.0+ e MariaDB 10.5+ (reaplicação tolerada pelo runner: 1060/1061/1826/1091).

ALTER TABLE ordens_trabalho ADD INDEX idx_ot_status_prev (status, data_prevista);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_status_fim (status, data_fim);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_plano_status (plano_id, status);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_ativo_status (ativo_id, status, data_fim);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_resp_status (responsavel_id, status, data_prevista);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_abertura (data_abertura);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_causa (causa_falha);
-- um pedido gera no máximo uma OT
ALTER TABLE ordens_trabalho ADD UNIQUE INDEX uq_ot_pedido (pedido_id);

ALTER TABLE ot_mao_obra ADD INDEX idx_mo_ot_custo (ot_id, horas, custo_hora);
ALTER TABLE ot_mao_obra ADD INDEX idx_mo_data (data, tecnico_id);
ALTER TABLE ot_materiais ADD INDEX idx_om_ot_custo (ot_id, baixado, quantidade, custo_unitario, artigo_id);
ALTER TABLE movimentos_estoque ADD INDEX idx_mov_art_data (artigo_id, data, id);
ALTER TABLE pedidos ADD INDEX idx_ped_solic_status (solicitante_id, status, criado_em);
ALTER TABLE fila_emails ADD INDEX idx_fila_erro (erro(32));

-- custos desnormalizados (mantidos por recalcular_custos_ot() no código)
ALTER TABLE ordens_trabalho ADD COLUMN custo_mo DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER custo_externo;
ALTER TABLE ordens_trabalho ADD COLUMN custo_mat DECIMAL(14,4) NOT NULL DEFAULT 0 AFTER custo_mo;
UPDATE ordens_trabalho o
  LEFT JOIN (SELECT ot_id, SUM(horas * custo_hora) v FROM ot_mao_obra GROUP BY ot_id) mo ON mo.ot_id = o.id
  LEFT JOIN (SELECT ot_id, SUM(quantidade * custo_unitario) v FROM ot_materiais WHERE baixado = 1 GROUP BY ot_id) mt ON mt.ot_id = o.id
  SET o.custo_mo = COALESCE(mo.v, 0), o.custo_mat = COALESCE(mt.v, 0);

-- integridade: contador usado por plano e artigo com kardex não podem ser apagados
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE planos_manutencao DROP FOREIGN KEY fk_pl_cont;
ALTER TABLE planos_manutencao ADD CONSTRAINT fk_pl_cont FOREIGN KEY (contador_id) REFERENCES contadores(id) ON DELETE RESTRICT;
ALTER TABLE movimentos_estoque DROP FOREIGN KEY fk_mov_art;
ALTER TABLE movimentos_estoque ADD CONSTRAINT fk_mov_art FOREIGN KEY (artigo_id) REFERENCES artigos(id) ON DELETE RESTRICT;
SET FOREIGN_KEY_CHECKS = 1;

ALTER TABLE ordens_trabalho DROP INDEX idx_ot_status;
