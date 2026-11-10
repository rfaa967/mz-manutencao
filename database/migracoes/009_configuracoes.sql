-- 009: configurações editáveis pelo administrador (sobrepõem config/config.php nas chaves permitidas).
-- A tabela já existia no schema (sem uso); aqui ganha as colunas de auditoria.
CREATE TABLE IF NOT EXISTS configuracoes (
  chave VARCHAR(64) NOT NULL PRIMARY KEY,
  valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE configuracoes MODIFY chave VARCHAR(64) NOT NULL;
ALTER TABLE configuracoes ADD COLUMN usuario_id INT NULL;
ALTER TABLE configuracoes ADD COLUMN atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
