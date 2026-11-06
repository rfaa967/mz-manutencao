<?php
declare(strict_types=1);

const SESSAO_OCIOSA_SEG = 4 * 3600;   // expira após 4 h sem uso
const SESSAO_MAX_SEG    = 16 * 3600;  // e no máximo 16 h após o login
const LOGIN_MAX_FALHAS  = 8;          // por e-mail ou IP, em 15 minutos

function https_ativo(): bool
{
    return (bool)cfg('forcar_https', false) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}

function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('MZMANUT');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => https_ativo()]);
    session_start();
}

/** Impressão digital da senha: trocar a senha invalida as outras sessões do usuário. */
function senha_fp(string $hash): string { return substr(hash('sha256', $hash), 0, 16); }

/**
 * Usuário logado, revalidado no banco a cada requisição: se foi desativado, mudou de perfil
 * ou trocou a senha, a sessão reflete isso na hora. Aplica também os timeouts.
 */
function usuario(): ?array
{
    if (array_key_exists('__usuario', $GLOBALS)) return $GLOBALS['__usuario'];
    $cache = &$GLOBALS['__usuario'];
    $s = $_SESSION['usuario'] ?? null;
    if (!$s) return $cache = null;
    $agora = time();
    if ($agora - ($_SESSION['ult'] ?? 0) > SESSAO_OCIOSA_SEG || $agora - ($_SESSION['ini'] ?? 0) > SESSAO_MAX_SEG) {
        logout(); return $cache = null;
    }
    $d = q1('SELECT id, nome, email, perfil, tecnico_id, ativo, senha_hash FROM usuarios WHERE id = ?', [$s['id']]);
    if (!$d || !$d['ativo'] || !hash_equals($_SESSION['fp'] ?? '', senha_fp($d['senha_hash']))) { logout(); return $cache = null; }
    $_SESSION['ult'] = $agora;
    return $cache = ['id' => (int)$d['id'], 'nome' => $d['nome'], 'email' => $d['email'], 'perfil' => $d['perfil'], 'tecnico_id' => $d['tecnico_id'] ? (int)$d['tecnico_id'] : null];
}
function usuario_reset_cache(): void { unset($GLOBALS['__usuario']); }

/*
 * Matriz de permissões
 *   admin       → tudo (inclui usuários)
 *   gestor      → gerir cadastros, ativos, planos, aprovar pedidos, estoque, relatórios
 *   tecnico     → executar OTs (mão de obra própria, materiais, checklist), leituras de contador, ver ativos
 *   solicitante → abrir e acompanhar os próprios pedidos
 */
const PERMISSOES = [
    'admin'     => ['admin'],
    'gerir'     => ['admin', 'gestor'],
    'executar'  => ['admin', 'gestor', 'tecnico'],
    'ver'       => ['admin', 'gestor', 'tecnico'],
    'solicitar' => ['admin', 'gestor', 'tecnico', 'solicitante'],
];

function pode(string $permissao): bool
{
    $u = usuario();
    return $u && in_array($u['perfil'], PERMISSOES[$permissao] ?? [], true);
}

function exigir(string $permissao): void
{
    if (!usuario()) redirect('login');
    if (!pode($permissao)) {
        http_response_code(403);
        view_header('Acesso negado');
        echo '<div class="alert alert-danger">Você não tem permissão para acessar esta área.</div>';
        echo '<a href="' . e(url(pode('ver') ? 'dashboard' : 'm')) . '">Voltar ao início</a>';
        view_footer();
        exit;
    }
}

function login_bloqueado(string $email): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return (int)qv('SELECT COUNT(*) FROM login_falhas WHERE (email = ? OR ip = ?) AND em > NOW() - INTERVAL 15 MINUTE', [$email, $ip]) >= LOGIN_MAX_FALHAS;
}

/** @return string|null mensagem de erro, ou null em caso de sucesso */
function login(string $email, string $senha): ?string
{
    $email = mb_strtolower(trim($email));
    if (login_bloqueado($email)) return 'Muitas tentativas. Aguarde 15 minutos e tente novamente.';
    $u = q1('SELECT * FROM usuarios WHERE email = ? AND ativo = 1', [$email]);
    if (!$u || !password_verify($senha, $u['senha_hash'])) {
        insert('login_falhas', ['email' => mb_substr($email, 0, 150), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
        auditar('login_falha', 'usuarios', $u ? (int)$u['id'] : null, $email);
        usleep(300000);
        return 'E-mail ou senha inválidos.';
    }
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        $u['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
        qx('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [$u['senha_hash'], $u['id']]);
    }
    qx('DELETE FROM login_falhas WHERE email = ?', [$email]);
    qx('DELETE FROM login_falhas WHERE em < NOW() - INTERVAL 1 DAY');
    session_regenerate_id(true);
    $destino = $_SESSION['destino'] ?? null;
    $_SESSION = ['destino' => $destino];                 // nada da sessão anônima sobrevive (inclui o token CSRF)
    $_SESSION['usuario'] = ['id' => (int)$u['id']];
    $_SESSION['fp'] = senha_fp($u['senha_hash']);
    $_SESSION['ini'] = $_SESSION['ult'] = time();
    qx('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?', [$u['id']]);
    usuario_reset_cache();
    auditar('login', 'usuarios', (int)$u['id']);
    return null;
}

/** Atualiza a impressão digital da sessão atual após o próprio usuário trocar a senha. */
function sessao_renovar_senha(string $novoHash): void
{
    $_SESSION['fp'] = senha_fp($novoHash);
    session_regenerate_id(true);
}

function logout(): void
{
    $GLOBALS['__usuario'] = null;
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
        session_destroy();
    }
}
