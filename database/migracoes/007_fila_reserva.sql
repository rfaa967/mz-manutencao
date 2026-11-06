-- 007: data da reserva na fila de e-mails (libera reservas presas sem reenviar mensagens em andamento)
ALTER TABLE fila_emails ADD COLUMN reservado_em DATETIME NULL AFTER erro;
