<?php
// Rodar diariamente via cron:
//   15 5 * * * /usr/bin/php /caminho/mzmanut/cron/gerar_preventivas.php >> /caminho/mzmanut/storage/cron.log 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$g = gerar_preventivas();
echo date('c') . ' — ' . count($g) . " OT(s) preventiva(s) gerada(s)\n";
foreach ($g as $x) echo "  plano #{$x['plano']['id']} {$x['plano']['titulo']} -> OT #{$x['ot_id']}\n";
