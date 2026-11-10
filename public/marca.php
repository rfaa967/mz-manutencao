<?php
// Logo, favicon e CSS do tema (configurados em Configurações › Aparência). Público: usados também na tela de login.
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
limitar_cfg('geral_ip', ip_chave());

header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
header('Cross-Origin-Resource-Policy: same-origin');
$t = is_string($_GET['t'] ?? null) ? $_GET['t'] : '';
// cache longo só quando a versão pedida é a atual (senão alguém envenenaria o cache de uma CDN com uma versão futura)
$cache = fn(string $atual) => header((string)($_GET['v'] ?? '') === $atual ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: public, max-age=300');

if ($t === 'css') {
    $css = tema_css() ?? '';
    header('Content-Type: text/css; charset=utf-8');
    $cache((string)tema_versao());
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
    $cache((string)(int)($m['v'] ?? 0));
    readfile($arq);
    exit;
}
http_response_code(404);
