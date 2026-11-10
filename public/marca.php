<?php
// Logo, favicon e CSS do tema (configurados em Configurações › Aparência). Público: usados também na tela de login.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
$t = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
$versionado = isset($_GET['v']);
$cache = fn() => header($versionado ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: public, max-age=300');

if ($t === 'css') {
    $css = tema_css() ?? '';
    header('Content-Type: text/css; charset=utf-8');
    $cache();
    exit($css);
}
if ($t === 'logo' || $t === 'favicon') {
    $m = cfg("marca.$t");
    $arq = is_array($m) ? MARCA_DIR . '/' . basename((string)($m['arq'] ?? '')) : '';
    $mime = is_array($m) ? (string)($m['mime'] ?? '') : '';
    if ($arq === '' || !is_file($arq) || !in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
        if ($t === 'favicon') { header('Location: ' . asset('icone.svg'), true, 302); exit; }
        http_response_code(404); exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($arq));
    $cache();
    readfile($arq);
    exit;
}
http_response_code(404);
