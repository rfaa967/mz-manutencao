-- 003: sequências atômicas de numeração e registro de falhas de login
CREATE TABLE IF NOT EXISTS sequencias (
  serie VARCHAR(10) NOT NULL,
  ano INT NOT NULL,
  ultimo INT NOT NULL,
  PRIMARY KEY (serie, ano)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO sequencias (serie, ano, ultimo)
  SELECT 'OT', CAST(SUBSTRING(numero, 3, 4) AS UNSIGNED), MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED))
  FROM ordens_trabalho WHERE numero LIKE 'OT____-%' GROUP BY CAST(SUBSTRING(numero, 3, 4) AS UNSIGNED);

INSERT IGNORE INTO sequencias (serie, ano, ultimo)
  SELECT 'PT', CAST(SUBSTRING(numero, 3, 4) AS UNSIGNED), MAX(CAST(SUBSTRING_INDEX(numero, '-', -1) AS UNSIGNED))
  FROM pedidos WHERE numero LIKE 'PT____-%' GROUP BY CAST(SUBSTRING(numero, 3, 4) AS UNSIGNED);

CREATE TABLE IF NOT EXISTS login_falhas (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_lf_email (email, em),
  INDEX idx_lf_ip (ip, em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
