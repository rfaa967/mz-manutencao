<?php
declare(strict_types=1);

/* ======================================================================
 * IP real do cliente
 * ====================================================================== */

/** Faixas oficiais da Cloudflare (https://www.cloudflare.com/ips/), conferidas em nov/2026. */
const CLOUDFLARE_IPS = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
    '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

function ip_na_faixa(string $ip, string $cidr): bool
{
    [$rede, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $a = @inet_pton($ip); $b = @inet_pton($rede);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = $bits === null ? strlen($a) * 8 : (int)$bits;
    $bytes = intdiv($bits, 8); $resto = $bits % 8;
    if (strncmp($a, $b, $bytes) !== 0) return false;
    if ($resto === 0) return true;
    $mask = chr((0xFF << (8 - $resto)) & 0xFF);
    return ($a[$bytes] & $mask) === ($b[$bytes] & $mask);
}

function ip_em_lista(string $ip, array $faixas): bool
{
    foreach ($faixas as $f) if (ip_na_faixa($ip, (string)$f)) return true;
    return false;
}

/**
 * IP do cliente. Só aceita cabeçalhos de proxy quando a conexão vem de um proxy confiável
 * (senão qualquer um forjaria o cabeçalho para escapar do rate limit / bloqueio de login).
 */
function ip_cliente(): string
{
    static $ip = null;
    if ($ip !== null) return $ip;
    $remoto = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $ip = $remoto;
    $modo = (string)cfg('proxy', '');
    if ($modo === 'cloudflare' && ip_em_lista($remoto, CLOUDFLARE_IPS)) {
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($cf, FILTER_VALIDATE_IP)) $ip = $cf;
    } elseif ($modo === 'confiavel' && ip_em_lista($remoto, (array)cfg('proxies_confiaveis', []))) {
        // percorre X-Forwarded-For da direita para a esquerda, pulando os proxies confiáveis
        $cadeia = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($cadeia as $c) {
            if (!filter_var($c, FILTER_VALIDATE_IP)) break;
            $ip = $c;
            if (!ip_em_lista($c, (array)cfg('proxies_confiaveis', []))) break;
        }
    }
    return $ip;
}

/* ======================================================================
 * Rate limiting (janela fixa por minuto/hora, contadores no banco)
 * ====================================================================== */

/**
 * Registra um evento e informa se ainda está dentro do limite.
 * @return array{0: bool, 1: int} [permitido, segundos até liberar]
 */
function rate_limit(string $regra, string $identificador, int $max, int $janelaSeg = 60): array
{
    if ($max <= 0 || !cfg('rate_limit.ativo', true)) return [true, 0];
    $agora = time();
    $janela = intdiv($agora, $janelaSeg);
    $chave = hash('sha256', "$regra|$identificador|$janelaSeg");
    try {
        qx('INSERT INTO rate_limites (chave, janela, contagem, expira_em) VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE contagem = contagem + 1', [$chave, $janela, ($janela + 1) * $janelaSeg]);
        $n = (int)qv('SELECT contagem FROM rate_limites WHERE chave = ? AND janela = ?', [$chave, $janela]);
        if (random_int(1, 200) === 1) qx('DELETE FROM rate_limites WHERE expira_em < ?', [$agora - 60]); // limpeza ocasional
    } catch (PDOException $ex) {
        error_log('[MZManut] rate limit indisponível: ' . $ex->getMessage());
        return [true, 0];                    // falha do contador não derruba o sistema
    }
    return [$n <= $max, ($janela + 1) * $janelaSeg - $agora];
}

/** Aplica a regra; se estourar, responde 429 e encerra. */
function limitar(string $regra, string $identificador, int $max, int $janelaSeg = 60, string $msg = ''): void
{
    [$ok, $espera] = rate_limit($regra, $identificador, $max, $janelaSeg);
    if ($ok) return;
    auditar('rate_limit', $regra, null, $identificador);
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(429);
    header('Retry-After: ' . max(1, $espera));
    $msg = $msg ?: 'Muitas requisições em pouco tempo. Aguarde ' . max(1, $espera) . ' segundo(s) e tente novamente.';
    if (str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') || get('_parcial')) {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['erro' => $msg], JSON_UNESCAPED_UNICODE));
    }
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><title>Aguarde</title><div style="font-family:sans-serif;padding:2rem;max-width:560px">'
       . '<h2>Calma aí 🙂</h2><p>' . e($msg) . '</p><p><a href="javascript:history.back()">Voltar</a></p></div>');
}

/** Limite por regra configurada em rate_limit.<regra> (60 s ou 3600 s se o nome terminar em _hora). */
function limitar_cfg(string $regra, string $identificador): void
{
    $max = (int)cfg("rate_limit.$regra", 0);
    limitar($regra, $identificador, $max, str_ends_with($regra, '_hora') ? 3600 : 60);
}

/* ======================================================================
 * Cloudflare Turnstile
 * ====================================================================== */

function turnstile_exigido(string $formulario): bool
{
    if (!cfg('turnstile.ativo', false) || !cfg('turnstile.site_key') || !cfg('turnstile.secret_key')) return false;
    if (!in_array($formulario, (array)cfg('turnstile.formularios', ['login']), true)) return false;
    if ($formulario === 'login' && cfg('turnstile.modo_login', 'sempre') === 'apos_falhas') {
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        return (int)qv('SELECT COUNT(*) FROM login_falhas WHERE (email = ? OR ip = ?) AND em > NOW() - INTERVAL 15 MINUTE', [$email, ip_cliente()]) >= 3;
    }
    return true;
}

/** HTML do widget (o script é carregado com o nonce da CSP). */
function turnstile_widget(string $formulario, bool $forcar = false): string
{
    if (!cfg('turnstile.ativo', false) || !cfg('turnstile.site_key')) return '';
    if (!in_array($formulario, (array)cfg('turnstile.formularios', ['login']), true)) return '';
    if (!$forcar && !turnstile_exigido($formulario)) return '';
    $GLOBALS['__turnstile'] = true;
    return '<div class="cf-turnstile mb-3" data-sitekey="' . e(cfg('turnstile.site_key')) . '" data-language="pt-br" data-action="' . e($formulario) . '"></div>';
}

/** Consulta o siteverify da Cloudflare. Retorna a resposta decodificada ou null se a Cloudflare não respondeu. */
function turnstile_siteverify(string $secret, string $token): ?array
{
    $dados = http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => ip_cliente(), 'idempotency_key' => bin2hex(random_bytes(16))]);
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($dados) . "\r\n",
        'content' => $dados,
    ]]);
    $resp = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $j = $resp !== false ? json_decode($resp, true) : null;
    return is_array($j) ? $j : null;
}

/** Valida o token enviado pelo widget. Retorna null se ok, ou a mensagem de erro. */
function turnstile_validar(string $formulario): ?string
{
    if (!turnstile_exigido($formulario)) return null;
    $token = $_POST['cf-turnstile-response'] ?? '';
    if (!is_string($token) || $token === '' || strlen($token) > 2048) return 'Confirme que você não é um robô.';
    $j = turnstile_siteverify((string)cfg('turnstile.secret_key'), $token);
    if ($j === null) {
        error_log('[MZManut] Turnstile sem resposta da Cloudflare');
        return cfg('turnstile.falhar_aberto', false) ? null : 'Não foi possível validar a verificação de segurança agora. Tente novamente em instantes.';
    }
    if (empty($j['success'])) {
        auditar('turnstile_falha', $formulario, null, implode(',', (array)($j['error-codes'] ?? [])));
        return 'A verificação de segurança falhou ou expirou. Tente novamente.';
    }
    if (!empty($j['action']) && $j['action'] !== $formulario) return 'Verificação de segurança inválida para este formulário.';
    return null;
}

/* ======================================================================
 * Proteção contra XSS: CSP com nonce, SRI das bibliotecas e JSON seguro
 * ====================================================================== */

/** Nonce aleatório desta requisição (autoriza só os <script> gerados pelo próprio sistema). */
function csp_nonce(): string
{
    static $n = null;
    return $n ??= base64_encode(random_bytes(18));
}

function csp_politica(bool $turnstile = false): string
{
    $ts = ($turnstile || cfg('turnstile.ativo', false)) ? ' https://challenges.cloudflare.com' : '';
    return implode('; ', [
        "default-src 'self'",
        // 'strict-dynamic': navegadores atuais só executam scripts com o nonce (e os carregados por eles);
        // 'self' e o host da CDN valem apenas para navegadores antigos (CSP nível 2), que ignoram strict-dynamic.
        "script-src 'nonce-" . csp_nonce() . "' 'strict-dynamic' 'self' https://cdn.jsdelivr.net$ts",
        "script-src-attr 'none'",                          // bloqueia onclick=, onerror= etc. (vetor clássico de XSS)
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        "font-src 'self' data: https://cdn.jsdelivr.net",
        "img-src 'self' data: blob:",
        "media-src 'self' blob:",
        "connect-src 'self'",
        "frame-src " . ($ts ? trim($ts) : "'none'"),
        "worker-src 'self'",
        "manifest-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]);
}

/** Bibliotecas de CDN com hash de integridade (SRI): se o arquivo da CDN for alterado, o navegador recusa. */
const CDN_SRI = [
    'bootstrap@5.3.3/dist/css/bootstrap.min.css'            => 'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
    'bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js'        => 'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
    'bootstrap-icons@1.11.3/font/bootstrap-icons.min.css'    => 'sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+',
    'chart.js@4.4.4/dist/chart.umd.min.js'                   => 'sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt',
    'fullcalendar@6.1.15/index.global.min.js'                => 'sha384-B1OFx8Gy9GjPu8UbUyXbGQpzll9ubAUQ9agInFJ8NnD7nYG1u/CLR+Sqr5yifl4q',
    '@fullcalendar/core@6.1.15/locales/pt-br.global.min.js'  => 'sha384-5ZKV+/cv//O+XOm9Hm6MvziRN8uPpyTzw+CpJ/rLYmcpTwagAELqBitMMNBRcqDk',
    'qrcode-generator@1.4.4/qrcode.min.js'                   => 'sha384-lQXOAyZwHXE55JFyrOMB7nY2Wv+m5ZWNtJcHrd1rceRQXAYNLak8ukN5TjBTcIwz',
    'html5-qrcode@2.3.8/html5-qrcode.min.js'                 => 'sha384-c9d8RFSL+u3exBOJ4Yp3HUJXS4znl9f+z66d1y54ig+ea249SpqR+w1wyvXz/lk+',
];

/** <script src> da CDN com SRI e nonce. */
function cdn_js(string $pacote): string
{
    if (!isset(CDN_SRI[$pacote])) throw new InvalidArgumentException("Biblioteca sem SRI: $pacote");
    return '<script src="https://cdn.jsdelivr.net/npm/' . e($pacote) . '" integrity="' . CDN_SRI[$pacote] . '" crossorigin="anonymous" nonce="' . e(csp_nonce()) . '"></script>';
}
function cdn_css(string $pacote): string
{
    if (!isset(CDN_SRI[$pacote])) throw new InvalidArgumentException("Biblioteca sem SRI: $pacote");
    return '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/' . e($pacote) . '" integrity="' . CDN_SRI[$pacote] . '" crossorigin="anonymous">';
}

/** <script src> local (assets) com nonce e cache-busting pela data do arquivo. */
function js_local(string $arquivo): string
{
    $v = @filemtime(APP_ROOT . '/public/assets/' . $arquivo) ?: 1;
    return '<script src="' . e(asset($arquivo)) . '?v=' . $v . '" nonce="' . e(csp_nonce()) . '"></script>';
}
