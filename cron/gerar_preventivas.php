<?php
// Rodar diariamente via cron (gera as OTs preventivas devidas e envia o resumo diário aos gestores):
//   15 5 * * * /usr/bin/php /caminho/mzmanut/cron/gerar_preventivas.php >> /caminho/mzmanut/storage/cron.log 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
$g = gerar_preventivas();
@touch(APP_ROOT . '/storage/cron_preventivas.ok'); // exibido em Configurações › Sistema
echo date('c') . ' — ' . count($g) . " OT(s) preventiva(s) gerada(s)\n";
foreach ($g as $x) echo "  plano #{$x['plano']['id']} {$x['plano']['titulo']} -> OT #{$x['ot_id']}\n";
if (!in_array('--sem-resumo', $argv, true)) {
    $n = enviar_resumo_diario($g);
    if ($n) echo "  resumo diário enfileirado para $n gestor(es)\n";
    email_processar_fila(200);
}
