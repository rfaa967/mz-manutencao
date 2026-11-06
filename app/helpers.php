<?php
declare(strict_types=1);

/* ---------- Saída / HTML ---------- */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function url(string $rota = '', array $params = []): string
{
    $base = rtrim((string)cfg('base_url', ''), '/');
    $qs = $rota !== '' ? ['r' => $rota] + $params : $params;
    return $base . '/index.php' . ($qs ? '?' . http_build_query($qs) : '');
}

function asset(string $path): string { return rtrim((string)cfg('base_url', ''), '/') . '/assets/' . ltrim($path, '/'); }

function redirect(string $rota, array $params = []): never
{
    header('Location: ' . url($rota, $params));
    exit;
}

function flash(?string $msg = null, string $tipo = 'success')
{
    if ($msg !== null) { $_SESSION['flash'][] = [$tipo, $msg]; return null; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Entrada ---------- */
function get(string $k, $padrao = null) { return isset($_GET[$k]) && $_GET[$k] !== '' ? $_GET[$k] : $padrao; }
function post(string $k, $padrao = null) { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $padrao; }
function post_int(string $k): ?int { $v = post($k); return ($v === null || $v === '') ? null : (int)$v; }
function post_dec(string $k): ?float
{
    $v = post($k);
    if ($v === null || $v === '') return null;
    $v = str_replace(' ', '', (string)$v);
    if (str_contains($v, ',')) $v = str_replace(['.', ','], ['', '.'], $v);          // 1.234,56 -> 1234.56
    elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $v)) $v = str_replace('.', '', $v); // 1.500 -> 1500
    if (!is_numeric($v)) return null;
    return (float)$v;
}
function post_date(string $k): ?string { $v = post($k); return ($v && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) ? $v : null; }
function post_datetime(string $k): ?string
{
    $v = post($k);
    if (!$v) return null;
    $v = str_replace('T', ' ', $v);
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $v) ? substr($v, 0, 16) . ':00' : null;
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!is_post()) return;
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(419);
        exit('Sessão expirada ou requisição inválida (CSRF). Volte e tente novamente.');
    }
}

/* ---------- Banco ---------- */
function q(string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function q1(string $sql, array $p = []): ?array { $s = db()->prepare($sql); $s->execute($p); $r = $s->fetch(); return $r ?: null; }
function qv(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); $r = $s->fetchColumn(); return $r === false ? null : $r; }
function qx(string $sql, array $p = []): int { $s = db()->prepare($sql); $s->execute($p); return $s->rowCount(); }

function insert(string $tabela, array $dados): int
{
    $cols = array_keys($dados);
    $sql = sprintf('INSERT INTO `%s` (`%s`) VALUES (%s)', $tabela, implode('`,`', $cols), implode(',', array_fill(0, count($cols), '?')));
    qx($sql, array_values($dados));
    return (int)db()->lastInsertId();
}

function update(string $tabela, array $dados, int $id): int
{
    $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($dados)));
    return qx("UPDATE `$tabela` SET $set WHERE id = ?", [...array_values($dados), $id]);
}

function opcoes(string $sql, array $p = []): array
{
    $r = [];
    foreach (q($sql, $p) as $l) { $v = array_values($l); $r[$v[0]] = $v[1] ?? $v[0]; }
    return $r;
}

function proximo_numero(string $tabela, string $prefixo): string
{
    $ano = date('Y');
    $ult = qv("SELECT numero FROM `$tabela` WHERE numero LIKE ? ORDER BY id DESC LIMIT 1", ["$prefixo$ano-%"]);
    $seq = $ult ? ((int)substr($ult, strrpos($ult, '-') + 1)) + 1 : 1;
    return sprintf('%s%s-%05d', $prefixo, $ano, $seq);
}

function auditar(string $acao, string $entidade, ?int $id = null, string $detalhe = ''): void
{
    try {
        insert('auditoria', [
            'usuario_id' => usuario()['id'] ?? null, 'acao' => $acao, 'entidade' => $entidade,
            'entidade_id' => $id, 'detalhe' => mb_substr($detalhe, 0, 500), 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        ]);
    } catch (Throwable) { /* auditoria nunca deve quebrar o fluxo */ }
}

/* ---------- Formatação ---------- */
function dt($v, bool $hora = false): string
{
    if (!$v) return '';
    $t = strtotime((string)$v);
    return $t ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '';
}
function moeda($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function num($v, int $casas = 2): string { return number_format((float)$v, $casas, ',', '.'); }
function input_dt($v): string { return $v ? str_replace(' ', 'T', substr((string)$v, 0, 16)) : ''; }

const ROTULOS = [
    'ot_status' => [
        'aberta' => ['Aberta', 'secondary'], 'planejada' => ['Planejada', 'info'], 'em_execucao' => ['Em execução', 'primary'],
        'aguardando_pecas' => ['Aguard. peças', 'warning'], 'concluida' => ['Concluída', 'success'], 'cancelada' => ['Cancelada', 'dark'],
    ],
    'prioridade' => [
        'baixa' => ['Baixa', 'secondary'], 'media' => ['Média', 'info'], 'alta' => ['Alta', 'warning'], 'urgente' => ['Urgente', 'danger'],
    ],
    'ped_status' => [
        'aberto' => ['Aberto', 'warning'], 'aprovado' => ['Aprovado', 'info'], 'rejeitado' => ['Rejeitado', 'danger'], 'convertido' => ['Convertido em OT', 'success'],
    ],
    'ativo_status' => [
        'operacional' => ['Operacional', 'success'], 'parado' => ['Parado', 'danger'], 'em_manutencao' => ['Em manutenção', 'warning'], 'desativado' => ['Desativado', 'secondary'],
    ],
    'tipo_ot' => [
        'corretiva' => ['Corretiva', 'danger'], 'preventiva' => ['Preventiva', 'success'], 'preditiva' => ['Preditiva', 'info'],
        'inspecao' => ['Inspeção', 'primary'], 'lubrificacao' => ['Lubrificação', 'warning'], 'calibracao' => ['Calibração', 'secondary'], 'melhoria' => ['Melhoria', 'dark'],
    ],
    'perfil' => ['admin' => ['Administrador', 'danger'], 'gestor' => ['Gestor', 'primary'], 'tecnico' => ['Técnico', 'info'], 'solicitante' => ['Solicitante', 'secondary']],
];

function rotulo(string $grupo, ?string $v): string { return ROTULOS[$grupo][$v][0] ?? (string)$v; }
function badge(string $grupo, ?string $v): string
{
    [$t, $c] = ROTULOS[$grupo][$v] ?? [(string)$v, 'secondary'];
    return '<span class="badge text-bg-' . $c . '">' . e($t) . '</span>';
}
function lista_rotulos(string $grupo): array { return array_map(fn($x) => $x[0], ROTULOS[$grupo]); }

/* ---------- Componentes de formulário ---------- */
function sel(string $nome, array $opcoes, $atual = null, bool $vazio = true, string $attrs = '', string $rotuloVazio = '— selecione —'): string
{
    $cls = str_contains($attrs, 'class=') ? '' : 'class="form-select" ';
    $h = '<select ' . $cls . 'name="' . e($nome) . '" id="f_' . e($nome) . '" ' . $attrs . '>';
    if ($vazio) $h .= '<option value="">' . e($rotuloVazio) . '</option>';
    foreach ($opcoes as $k => $v) {
        $h .= '<option value="' . e($k) . '"' . ((string)$k === (string)$atual ? ' selected' : '') . '>' . e($v) . '</option>';
    }
    return $h . '</select>';
}

function paginar(string $sqlBase, array $params, int $porPagina = 25): array
{
    $pag = max(1, (int)get('pag', 1));
    $total = (int)qv("SELECT COUNT(*) FROM ($sqlBase) t", $params);
    $linhas = q($sqlBase . ' LIMIT ' . $porPagina . ' OFFSET ' . (($pag - 1) * $porPagina), $params);
    return ['linhas' => $linhas, 'total' => $total, 'pag' => $pag, 'paginas' => max(1, (int)ceil($total / $porPagina))];
}

function paginacao_html(array $p): string
{
    if ($p['paginas'] <= 1) return '<div class="small text-muted">' . $p['total'] . ' registro(s)</div>';
    $qs = $_GET;
    $h = '<div class="d-flex justify-content-between align-items-center"><div class="small text-muted">' . $p['total'] . ' registro(s)</div><ul class="pagination pagination-sm mb-0">';
    $ini = max(1, $p['pag'] - 3); $fim = min($p['paginas'], $p['pag'] + 3);
    for ($i = $ini; $i <= $fim; $i++) {
        $qs['pag'] = $i;
        $h .= '<li class="page-item' . ($i === $p['pag'] ? ' active' : '') . '"><a class="page-link" href="?' . e(http_build_query($qs)) . '">' . $i . '</a></li>';
    }
    return $h . '</ul></div>';
}

function csv_download(string $nome, array $cabecalho, array $linhas): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
    fputcsv($out, $cabecalho, ';');
    foreach ($linhas as $l) fputcsv($out, array_values($l), ';');
    fclose($out);
    exit;
}

/* ---------- Árvores ---------- */
function arvore_opcoes(string $tabela, string $rotulo = 'nome', ?int $excluir = null): array
{
    $todos = q("SELECT id, parent_id, $rotulo AS r" . ($tabela === 'ativos' ? ", codigo" : '') . " FROM `$tabela` ORDER BY $rotulo");
    $filhos = [];
    foreach ($todos as $t) $filhos[(int)($t['parent_id'] ?? 0)][] = $t;
    $res = [];
    $andar = function (int $pai, int $nivel) use (&$andar, &$res, $filhos, $excluir) {
        foreach ($filhos[$pai] ?? [] as $n) {
            if ($excluir !== null && (int)$n['id'] === $excluir) continue; // evita ciclo
            $res[$n['id']] = str_repeat('— ', $nivel) . (isset($n['codigo']) ? $n['codigo'] . ' · ' : '') . $n['r'];
            $andar((int)$n['id'], $nivel + 1);
        }
    };
    $andar(0, 0);
    return $res;
}

function descendentes(string $tabela, int $id): array
{
    $ids = [$id]; $fila = [$id];
    while ($fila) {
        $atual = array_shift($fila);
        foreach (q("SELECT id FROM `$tabela` WHERE parent_id = ?", [$atual]) as $f) { $ids[] = (int)$f['id']; $fila[] = (int)$f['id']; }
    }
    return $ids;
}

function caminho_localizacao(?int $id): string
{
    $partes = []; $guard = 0;
    while ($id && $guard++ < 20) {
        $l = q1('SELECT id, parent_id, nome FROM localizacoes WHERE id = ?', [$id]);
        if (!$l) break;
        array_unshift($partes, $l['nome']);
        $id = $l['parent_id'] ? (int)$l['parent_id'] : null;
    }
    return implode(' › ', $partes);
}

/* ---------- Layout ---------- */
function view_header(string $titulo, string $menuAtivo = ''): void { $GLOBALS['__titulo'] = $titulo; $GLOBALS['__menu'] = $menuAtivo; require APP_ROOT . '/app/views/header.php'; }
function view_footer(): void { require APP_ROOT . '/app/views/footer.php'; }

function json_out($dados): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}
