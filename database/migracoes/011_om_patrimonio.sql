-- 011: código patrimonial do ativo e dados das OMs não preventivas (mecânica/elétrica/civil)
ALTER TABLE ativos ADD COLUMN patrimonio VARCHAR(40) NULL AFTER codigo;
ALTER TABLE ativos ADD UNIQUE INDEX uq_at_patrimonio (patrimonio);
ALTER TABLE ordens_trabalho ADD COLUMN area ENUM('mecanica','eletrica','civil','outros') NULL AFTER tipo;
ALTER TABLE ordens_trabalho ADD COLUMN numero_sm VARCHAR(20) NULL AFTER numero;
ALTER TABLE ordens_trabalho ADD COLUMN empresa VARCHAR(60) NULL AFTER area;
ALTER TABLE ordens_trabalho ADD COLUMN solicitante VARCHAR(100) NULL AFTER empresa;
ALTER TABLE ordens_trabalho ADD COLUMN equipamento VARCHAR(150) NULL AFTER ativo_id;
ALTER TABLE ordens_trabalho ADD COLUMN patrimonio VARCHAR(40) NULL AFTER equipamento;
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_area (area, status);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_patrimonio (patrimonio);
ALTER TABLE ordens_trabalho ADD INDEX idx_ot_empresa (empresa)
