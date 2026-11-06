<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header_remove('X-Powered-By');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
// Scripts só do próprio domínio e do jsDelivr (Bootstrap, Chart.js, FullCalendar, QR).
// 'unsafe-inline' ainda é necessário para os scripts embutidos das páginas.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
     . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' data: https://cdn.jsdelivr.net; img-src 'self' data: blob:; "
     . "media-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
if (https_ativo()) header('Strict-Transport-Security: max-age=31536000');

sessao_iniciar();
ob_start(); // permite descartar a saída parcial se ocorrer um erro

$rota = (string)($_GET['r'] ?? 'dashboard');
[$modulo, $acao] = array_pad(explode('/', $rota, 2), 2, 'index');
$modulo = preg_replace('/[^a-z_]/', '', $modulo);
$acao   = preg_replace('/[^a-z_]/', '', $acao) ?: 'index';

$modulos = ['login', 'logout', 'dashboard', 'ativos', 'cadastros', 'pedidos', 'ots', 'planos', 'contadores',
            'estoque', 'calendario', 'relatorios', 'usuarios', 'perfil', 'cron', 'm'];

if (!in_array($modulo, $modulos, true)) { http_response_code(404); exit('Página não encontrada'); }

if (!in_array($modulo, ['login', 'cron'], true) && !usuario()) {
    // guarda o destino (ex.: QR Code lido sem sessão) para voltar após o login
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') $_SESSION['destino'] = ['r' => "$modulo/$acao"] + array_diff_key($_GET, ['r' => 1]);
    redirect('login');
}

csrf_check();

try {
    require APP_ROOT . '/app/modulos/' . $modulo . '.php';
} catch (Throwable $ex) {
    if (db_conectado() && db()->inTransaction()) db()->rollBack();
    error_log('[MZManut] ' . $ex);
    while (ob_get_level() > 0) ob_end_clean();
    if (!headers_sent()) http_response_code(500);
    // detalhes técnicos só para administradores e com debug ligado
    $detalhe = cfg('debug') && ($GLOBALS['__usuario']['perfil'] ?? '') === 'admin';
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;padding:2rem"><h3>Erro ao processar a solicitação</h3>';
    echo $detalhe ? '<pre>' . e($ex) . '</pre>' : '<p>O erro foi registrado no log do servidor. Se persistir, informe o administrador.</p>';
    echo '<p><a href="' . e(url('dashboard')) . '">Voltar ao início</a></p></div>';
}
