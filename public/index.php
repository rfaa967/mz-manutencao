<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

sessao_iniciar();

$rota = (string)($_GET['r'] ?? 'dashboard');
[$modulo, $acao] = array_pad(explode('/', $rota, 2), 2, 'index');
$modulo = preg_replace('/[^a-z_]/', '', $modulo);
$acao   = preg_replace('/[^a-z_]/', '', $acao) ?: 'index';

$modulos = ['login', 'logout', 'dashboard', 'ativos', 'cadastros', 'pedidos', 'ots', 'planos', 'contadores',
            'estoque', 'calendario', 'relatorios', 'usuarios', 'perfil', 'cron'];

if (!in_array($modulo, $modulos, true)) { http_response_code(404); exit('Página não encontrada'); }

if (!in_array($modulo, ['login', 'cron'], true) && !usuario()) redirect('login');

csrf_check();

try {
    require APP_ROOT . '/app/modulos/' . $modulo . '.php';
} catch (Throwable $ex) {
    error_log('[MZManut] ' . $ex);
    http_response_code(500);
    if (!headers_sent() || ob_get_level() === 0) {
        echo '<div style="font-family:sans-serif;padding:2rem"><h3>Erro ao processar a solicitação</h3>';
        echo cfg('debug') ? '<pre>' . e($ex) . '</pre>' : '<p>O erro foi registrado no log do servidor.</p>';
        echo '<p><a href="' . e(url('dashboard')) . '">Voltar ao início</a></p></div>';
    }
}
