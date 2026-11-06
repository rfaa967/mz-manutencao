<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header_remove('X-Powered-By');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 0');                      // filtro legado dos navegadores desligado: a proteção é a CSP
header('Referrer-Policy: same-origin');
header('Cross-Origin-Opener-Policy: same-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()');
// CSP: scripts só do próprio domínio, do jsDelivr (com SRI) e blocos com o nonce desta requisição.
// Sem 'unsafe-inline' para scripts e sem atributos on* (script-src-attr 'none'): um XSS injetado não executa.
header('Content-Security-Policy: ' . csp_politica());
if (https_ativo()) header('Strict-Transport-Security: max-age=31536000');

// Modo manutenção (durante atualizações): crie o arquivo storage/MANUTENCAO; apague ao terminar.
if (is_file(APP_ROOT . '/storage/MANUTENCAO')) {
    http_response_code(503); header('Retry-After: 300'); header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Em manutenção</title>'
       . '<div style="font-family:sans-serif;padding:2rem;text-align:center"><h2>Sistema em atualização</h2><p>Voltamos em alguns minutos.</p></div>');
}

sessao_iniciar();
ob_start(); // permite descartar a saída parcial se ocorrer um erro

// envio maior que post_max_size: o PHP descarta todo o POST (inclusive o token CSRF) — avisa em vez de dar "sessão expirada"
if (is_post() && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('O envio é maior que o limite do servidor (' . ini_get('post_max_size') . '). Reduza o arquivo ou peça ao administrador para aumentar post_max_size.', 'danger');
    $volta = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
    header('Location: ' . (($volta['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '-') ? ($volta['path'] ?? '/') . (isset($volta['query']) ? '?' . $volta['query'] : '') : url('dashboard')));
    exit;
}

$rota = is_string($_GET['r'] ?? null) ? $_GET['r'] : 'dashboard';
[$modulo, $acao] = array_pad(explode('/', $rota, 2), 2, 'index');
$modulo = preg_replace('/[^a-z_]/', '', $modulo);
$acao   = preg_replace('/[^a-z_]/', '', $acao) ?: 'index';

$modulos = ['login', 'logout', 'dashboard', 'ativos', 'cadastros', 'pedidos', 'ots', 'planos', 'contadores',
            'estoque', 'calendario', 'relatorios', 'usuarios', 'perfil', 'cron', 'm'];

if (!in_array($modulo, $modulos, true)) { http_response_code(404); exit('Página não encontrada'); }

// Rate limit: por IP em qualquer página e por usuário em envios de formulário
limitar_cfg('geral_ip', ip_cliente());

if (!in_array($modulo, ['login', 'cron'], true) && !usuario()) {
    // guarda o destino (ex.: QR Code lido sem sessão) para voltar após o login
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') { http_response_code(401); header('Content-Type: application/json'); exit('{"erro":"Sessão expirada. Entre novamente."}'); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') $_SESSION['destino'] = ['r' => "$modulo/$acao"] + array_diff_key($_GET, ['r' => 1]);
    redirect('login');
}

if (is_post() && usuario()) limitar_cfg('post_usuario', 'u' . usuario()['id']);

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
