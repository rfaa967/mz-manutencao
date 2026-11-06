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

/** URL absoluta (QR Code, e-mails). Usa app_url do config; sem ele, o host da requisição. */
function url_abs(string $rota = '', array $params = []): string
{
    $base = rtrim((string)cfg('app_url', ''), '/');
    if ($base === '') {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        // SERVER_NAME vem da configuração do servidor web (não do cabeçalho Host, que o cliente controla)
        $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
        $porta = (int)($_SERVER['SERVER_PORT'] ?? 80);
        if (!in_array($porta, [80, 443], true) && !str_contains($host, ':')) $host .= ':' . $porta;
        $base = ($https ? 'https' : 'http') . '://' . $host;
    }
    return $base . url($rota, $params);
}

/** Link curto codificado no QR Code do ativo. */
function url_qr(string $codigo): string
{
    $abs = url_abs();                                  // .../index.php
    return substr($abs, 0, -strlen('index.php')) . 'q.php?c=' . rawurlencode($codigo);
}

function asset(string $path): string { return rtrim((string)cfg('base_url', ''), '/') . '/assets/' . ltrim($path, '/'); }

function redirect(string $rota, array $params = []): never
{
    header('Location: ' . url($rota, $params));
    exit;
}

/** Formulários da interface mobile enviam _m=1; devolve o usuário para a tela mobile equivalente. */
function redirect_m(string $rotaMobile, array $params = []): void
{
    if (post('_m') === '1') redirect($rotaMobile, $params);
}

function flash(?string $msg = null, string $tipo = 'success')
{
    if ($msg !== null) {
        $_SESSION['flash'][] = [$tipo, $msg];
        // erro em formulário: guarda o que foi digitado para reexibir (exceto senhas e token)
        if (in_array($tipo, ['danger', 'warning'], true) && is_post()) {
            $_SESSION['old'] = array_filter($_POST, fn($v, $k) => is_scalar($v) && !in_array($k, ['_csrf', 'senha', 'senha_atual', 'senha_nova', 'senha_conf'], true), ARRAY_FILTER_USE_BOTH);
        }
        return null;
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- Dados do formulário reenviados após erro ---------- */
function old_all(): array
{
    if (!array_key_exists('__old', $GLOBALS)) { $GLOBALS['__old'] = $_SESSION['old'] ?? []; unset($_SESSION['old']); }
    // números digitados no formato brasileiro voltam como número (para o num() do formulário)
    return array_map(fn($v) => (is_string($v) && str_contains($v, ',') && ($d = dec_br($v)) !== null) ? $d : $v, $GLOBALS['__old']);
}
function old(string $k, $padrao = null) { $o = old_all(); return array_key_exists($k, $o) ? $o[$k] : $padrao; }
/** Mescla os dados reenviados sobre o registro carregado do banco. */
function com_old(?array $registro): array { return array_merge($registro ?? [], old_all()); }

/* ---------- Entrada ---------- */
function get(string $k, $padrao = null) { return isset($_GET[$k]) && $_GET[$k] !== '' ? $_GET[$k] : $padrao; }
function post(string $k, $padrao = null) { return isset($_POST[$k]) ? (is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k]) : $padrao; }
function post_int(string $k): ?int { $v = post($k); return ($v === null || $v === '') ? null : (int)$v; }
/**
 * Converte número digitado (pt-BR ou com ponto decimal) em float; null se inválido.
 *   "1.234,56" → 1234.56 · "1,5" → 1.5 · "1.500" → 1500 · "0.500" → 0.5 · "10.5" → 10.5
 * Rejeita notação científica, formato americano misto ("1,234.56") e texto.
 */
function dec_br($v): ?float
{
    if ($v === null || is_array($v)) return null;
    $v = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim((string)$v));
    if ($v === '') return null;
    if (preg_match('/^-?[1-9]\d{0,2}(\.\d{3})+(,\d+)?$/', $v)) return (float)str_replace(['.', ','], ['', '.'], $v); // 1.234 / 1.234,5
    if (preg_match('/^-?\d+,\d+$/', $v)) return (float)str_replace(',', '.', $v);                                       // 12,5
    if (preg_match('/^-?\d+(\.\d+)?$/', $v)) return (float)$v;                                                        // 12 / 12.5 / 0.500
    return null;
}
function post_dec(string $k): ?float { return dec_br($_POST[$k] ?? null); }
/** Igual a post_dec, mas negativo é tratado como inválido (null). */
function post_dec_pos(string $k): ?float { $v = post_dec($k); return ($v !== null && $v >= 0) ? $v : null; }
/** Indica se o campo foi preenchido mas não é um número >= 0 válido. */
function dec_invalido(string $k): bool { $r = $_POST[$k] ?? ''; return is_string($r) && trim($r) !== '' && post_dec_pos($k) === null; }
function post_date(string $k): ?string
{
    $v = post($k);
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return $v;
}
function post_datetime(string $k): ?string
{
    $v = post($k);
    if (!$v) return null;
    $v = str_replace('T', ' ', $v);
    if (!is_string($v) || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || $m[4] > 23 || $m[5] > 59) return null;
    return substr($v, 0, 16) . ':00';
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

/** Status de OT ainda em aberto (IN usa índice; NOT IN fazia varredura completa). */
const STATUS_PENDENTES = "'aberta','planejada','em_execucao','aguardando_pecas'";

/**
 * Próximo número da série (OT2026-00001). Atômico (UPDATE ... LAST_INSERT_ID) e sem varrer a tabela:
 * a semente a partir do maior número existente só roda na primeira numeração da série/ano.
 */
function proximo_numero(string $tabela, string $prefixo): string
{
    $ano = (int)date('Y');
    $up = fn() => qx('UPDATE sequencias SET ultimo = LAST_INSERT_ID(ultimo + 1) WHERE serie = ? AND ano = ?', [$prefixo, $ano]);
    if ($up() === 0) {
        qx('INSERT IGNORE INTO sequencias (serie, ano, ultimo) SELECT ?, ?, COALESCE(MAX(CAST(SUBSTRING_INDEX(numero, \'-\', -1) AS UNSIGNED)), 0) FROM `' . $tabela . '` WHERE numero LIKE ?',
           [$prefixo, $ano, "$prefixo$ano-%"]);
        $up();
    }
    return sprintf('%s%d-%05d', $prefixo, $ano, (int)qv('SELECT LAST_INSERT_ID()'));
}

/** Recalcula os custos desnormalizados da OT (mão de obra e materiais baixados). Chamar após qualquer alteração. */
function recalcular_custos_ot(int $otId): void
{
    qx('UPDATE ordens_trabalho o SET
          custo_mo  = (SELECT COALESCE(SUM(horas * custo_hora), 0) FROM ot_mao_obra WHERE ot_id = o.id),
          custo_mat = (SELECT COALESCE(SUM(quantidade * custo_unitario), 0) FROM ot_materiais WHERE ot_id = o.id AND baixado = 1)
        WHERE o.id = ?', [$otId]);
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
/** "1 OT gerada" / "3 OTs geradas" */
function plural(int $n, string $singular, string $plural): string { return $n . ' ' . ($n === 1 ? $singular : $plural); }
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

/** Neutraliza fórmulas (CSV injection): prefixa ' em textos iniciados por = + - @ tab ou CR. */
function csv_seguro($v)
{
    if (!is_string($v) || $v === '' || preg_match('/^-?[\d.,]+$/', $v)) return $v;
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

function csv_download(string $nome, array $cabecalho, array $linhas): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nome . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM p/ Excel
    fputcsv($out, $cabecalho, ';');
    foreach ($linhas as $l) fputcsv($out, array_map('csv_seguro', array_values($l)), ';');
    fclose($out);
    exit;
}

/* ---------- Status do ativo ---------- */
/**
 * Recalcula o status de um ativo a partir das OTs abertas com ativo parado e dos pedidos abertos
 * com "equipamento parado" feitos pela equipe (pedidos de solicitantes só param o ativo após a aprovação).
 */
function atualizar_status_ativo(?int $ativoId): void
{
    if (!$ativoId) return;
    $st = qv('SELECT status FROM ativos WHERE id = ?', [$ativoId]);
    if ($st === null || $st === 'desativado') return;
    $emExec = (int)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE ativo_id=? AND ativo_parado=1 AND status IN ('em_execucao','aguardando_pecas')", [$ativoId]);
    $parado = (int)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE ativo_id=? AND ativo_parado=1 AND status IN ('aberta','planejada')", [$ativoId])
            + (int)qv("SELECT COUNT(*) FROM pedidos p JOIN usuarios u ON u.id=p.solicitante_id WHERE p.ativo_id=? AND p.ativo_parado=1
                       AND p.status IN ('aberto','aprovado') AND u.perfil <> 'solicitante'", [$ativoId]);
    $novo = $emExec ? 'em_manutencao' : ($parado ? 'parado' : 'operacional');
    if ($novo !== $st) qx('UPDATE ativos SET status = ? WHERE id = ?', [$novo, $ativoId]);
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
    if (!in_array($tabela, ['ativos', 'localizacoes'], true)) throw new InvalidArgumentException('tabela');
    // UNION (sem ALL) interrompe eventuais ciclos
    $ids = array_map('intval', array_column(q("WITH RECURSIVE d AS (SELECT id FROM `$tabela` WHERE id = ? UNION SELECT t.id FROM `$tabela` t JOIN d ON t.parent_id = d.id) SELECT id FROM d", [$id]), 'id'));
    return $ids ?: [$id];
}

function caminho_localizacao(?int $id): string
{
    static $locs = null;
    if ($locs === null) { $locs = []; foreach (q('SELECT id, parent_id, nome FROM localizacoes') as $l) $locs[(int)$l['id']] = $l; }
    $partes = []; $guard = 0;
    while ($id && isset($locs[$id]) && $guard++ < 20) {
        array_unshift($partes, $locs[$id]['nome']);
        $id = $locs[$id]['parent_id'] ? (int)$locs[$id]['parent_id'] : null;
    }
    return implode(' › ', $partes);
}

/* ---------- Layout ---------- */
function view_header(string $titulo, string $menuAtivo = ''): void { $GLOBALS['__titulo'] = $titulo; $GLOBALS['__menu'] = $menuAtivo; require APP_ROOT . '/app/views/header.php'; }
function view_footer(): void { require APP_ROOT . '/app/views/footer.php'; }

/** Renderiza em SVG todo elemento [data-qr] (biblioteca qrcode-generator, MIT). */
function qr_js(): string
{
    return '<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>'
         . '<script>document.querySelectorAll("[data-qr]").forEach(function(el){var q=qrcode(0,"M");q.addData(el.dataset.qr);q.make();el.innerHTML=q.createSvgTag({cellSize:4,margin:2,scalable:true});});</script>';
}

function json_out($dados): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}
