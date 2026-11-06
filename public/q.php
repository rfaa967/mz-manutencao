<?php
// URL curta usada no QR Code das etiquetas: /q.php?c=CODIGO → ficha mobile do ativo
require dirname(__DIR__) . '/app/bootstrap.php';
limitar_cfg('qr_ip', ip_cliente());
$c = mb_strtoupper(trim((string)($_GET['c'] ?? '')));
$c = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $c), 0, 40);
header('Location: ' . url('m/ativo', ['c' => $c]), true, 302);
