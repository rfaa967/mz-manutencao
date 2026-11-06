<?php
// Gatilho HTTP do gerador de preventivas: /index.php?r=cron&token=SEU_TOKEN
$token = (string)cfg('cron_token', '');
limitar_cfg('cron_ip', ip_cliente());
$enviado = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
if ($token !== '' && strlen($token) < 24) error_log('[MZManut] cron_token com menos de 24 caracteres: chamadas por URL estão bloqueadas. Gere um novo com: php -r "echo bin2hex(random_bytes(32));"');
if ($token === '' || strlen($token) < 24 || !is_string($enviado) || !hash_equals($token, $enviado)) { http_response_code(403); exit('forbidden'); }
$g = gerar_preventivas();
if (get('resumo', '1') === '1') enviar_resumo_diario($g);
email_processar_fila(100);
header('Content-Type: text/plain; charset=utf-8');
echo date('c') . ' — ' . count($g) . " OT(s) gerada(s)\n";
foreach ($g as $x) echo " - plano #{$x['plano']['id']} {$x['plano']['titulo']} → OT #{$x['ot_id']}\n";
