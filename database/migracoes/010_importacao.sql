-- 010: vínculo entre registros importados de outro sistema (ex.: ManWinWin) e os registros criados aqui.
-- Permite reimportar sem duplicar e sem sobrescrever o que foi editado no sistema.
CREATE TABLE IF NOT EXISTS importacao_mapa (
  origem VARCHAR(20) NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  chave VARCHAR(150) NOT NULL,
  entidade_id INT NOT NULL,
  importado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (origem, tipo, chave),
  INDEX idx_imp_entidade (tipo, entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
