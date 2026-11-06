<?php
// Gatilho HTTP do gerador de preventivas: /index.php?r=cron&token=SEU_TOKEN
$token = (string)cfg('cron_token', '');
if ($token === '' || !hash_equals($token, (string)get('token', ''))) { http_response_code(403); exit('forbidden'); }
$g = gerar_preventivas();
header('Content-Type: text/plain; charset=utf-8');
echo date('c') . ' — ' . count($g) . " OT(s) gerada(s)\n";
foreach ($g as $x) echo " - plano #{$x['plano']['id']} {$x['plano']['titulo']} → OT #{$x['ot_id']}\n";
