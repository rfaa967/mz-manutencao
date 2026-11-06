<?php
// URL curta usada no QR Code das etiquetas: /q.php?c=CODIGO → ficha mobile do ativo
require dirname(__DIR__) . '/app/bootstrap.php';
$c = mb_strtoupper(trim((string)($_GET['c'] ?? '')));
$c = preg_replace('/[^\p{L}\p{N}\-_.\/ ]/u', '', $c);
header('Location: ' . url('m/ativo', ['c' => $c]), true, 302);
