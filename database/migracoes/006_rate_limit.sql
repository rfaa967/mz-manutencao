-- 006: contadores do rate limit (janela fixa)
CREATE TABLE IF NOT EXISTS rate_limites (
  chave CHAR(64) NOT NULL,
  janela INT UNSIGNED NOT NULL,
  contagem INT UNSIGNED NOT NULL DEFAULT 0,
  expira_em INT UNSIGNED NOT NULL,
  PRIMARY KEY (chave, janela),
  INDEX idx_rl_expira (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
