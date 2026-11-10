<?php
// Envia a fila de e-mails. Agende a cada 5 minutos:
//   */5 * * * * /usr/bin/php /caminho/mzmanut/cron/enviar_emails.php >> /caminho/mzmanut/storage/cron.log 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
[$ok, $err] = email_processar_fila(200);
@touch(APP_ROOT . '/storage/cron_emails.ok'); // exibido em Configurações › Sistema
qx("DELETE FROM fila_emails WHERE status = 'enviado' AND enviado_em < NOW() - INTERVAL 30 DAY");
if ($ok || $err) echo date('c') . " — e-mails enviados: $ok, falhas: $err\n";
