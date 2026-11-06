-- 002: fila de e-mails e preferência de notificação por usuário
CREATE TABLE IF NOT EXISTS fila_emails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  para VARCHAR(150) NOT NULL,
  assunto VARCHAR(200) NOT NULL,
  corpo_html MEDIUMTEXT NOT NULL,
  evento VARCHAR(40) NULL,
  status ENUM('pendente','enviado','erro') NOT NULL DEFAULT 'pendente',
  tentativas TINYINT NOT NULL DEFAULT 0,
  erro VARCHAR(500) NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_em DATETIME NULL,
  INDEX idx_fila_status (status, tentativas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE usuarios ADD COLUMN receber_emails TINYINT(1) NOT NULL DEFAULT 1 AFTER ativo;
