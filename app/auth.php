<?php
declare(strict_types=1);

function sessao_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
    session_name('MZMANUT');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function usuario(): ?array { return $_SESSION['usuario'] ?? null; }

/*
 * Matriz de permissões
 *   admin       → tudo (inclui usuários e configurações)
 *   gestor      → gerir cadastros, ativos, planos, aprovar pedidos, estoque, relatórios
 *   tecnico     → executar OTs (mão de obra, materiais, checklist), leituras de contador, ver ativos
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
        view_footer();
        exit;
    }
}

function login(string $email, string $senha): bool
{
    $u = q1('SELECT * FROM usuarios WHERE email = ? AND ativo = 1', [mb_strtolower(trim($email))]);
    if (!$u || !password_verify($senha, $u['senha_hash'])) {
        usleep(300000); // atrasa força bruta
        return false;
    }
    if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
        qx('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($senha, PASSWORD_DEFAULT), $u['id']]);
    }
    session_regenerate_id(true);
    $_SESSION['usuario'] = ['id' => (int)$u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'perfil' => $u['perfil'], 'tecnico_id' => $u['tecnico_id'] ? (int)$u['tecnico_id'] : null];
    qx('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?', [$u['id']]);
    auditar('login', 'usuarios', (int)$u['id']);
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
