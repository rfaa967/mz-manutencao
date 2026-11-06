<?php
declare(strict_types=1);

const TIPOS_PLANEJADOS = "'preventiva','preditiva','inspecao','lubrificacao','calibracao'";

/** Custos de uma OT (alias o): colunas desnormalizadas mantidas por recalcular_custos_ot(). Materiais só contam depois de baixados. */
const SQL_CUSTO_OT  = 'o.custo_mo';
const SQL_CUSTO_MAT = 'o.custo_mat';

/** Mapa id → id do ativo raiz (topo da árvore). */
function mapa_raiz(): array
{
    static $mapa = null;
    if ($mapa !== null) return $mapa;
    $pai = [];
    foreach (q('SELECT id, parent_id FROM ativos') as $a) $pai[(int)$a['id']] = $a['parent_id'] ? (int)$a['parent_id'] : null;
    $mapa = [];
    foreach ($pai as $id => $_) {
        $r = $id; $g = 0;
        while ($pai[$r] ?? null) { $r = $pai[$r]; if (++$g > 50) break; }
        $mapa[$id] = $r;
    }
    return $mapa;
}

/**
 * Horas de parada no período, unindo intervalos sobrepostos por chave (ex.: ativo raiz ou o próprio ativo)
 * e recortando pelo período. Cada OT concluída com parada vira o intervalo [data_fim - horas_parada, data_fim].
 * @return array chave => horas
 */
function paradas_no_periodo(string $ini, string $fim, ?array $ids, callable $chave, bool $soCorretiva = false): array
{
    $tIni = strtotime("$ini 00:00:00"); $tFim = strtotime("$fim 23:59:59") + 1;
    $w = $ids ? ' AND ativo_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '';
    // teto: uma parada que termina depois de fim + maior parada registrada não pode cruzar o período
    static $maxParada = null;
    $maxParada ??= (int)ceil((float)qv("SELECT COALESCE(MAX(horas_parada), 0) FROM ordens_trabalho WHERE status = 'concluida'"));
    $rows = q("SELECT ativo_id, data_fim, horas_parada FROM ordens_trabalho
               WHERE status = 'concluida' AND horas_parada > 0 AND ativo_id IS NOT NULL" . ($soCorretiva ? " AND tipo = 'corretiva'" : '') . "
                 AND data_fim >= ? AND data_fim <= ? + INTERVAL $maxParada HOUR
                 AND DATE_SUB(data_fim, INTERVAL ROUND(horas_parada * 60) MINUTE) <= ? $w",
              ["$ini 00:00:00", "$fim 23:59:59", "$fim 23:59:59"]);
    $porChave = [];
    foreach ($rows as $r) {
        $k = $chave((int)$r['ativo_id']);
        if ($k === null) continue;
        $b = strtotime($r['data_fim']); $a = $b - (int)round((float)$r['horas_parada'] * 3600);
        $a = max($a, $tIni); $b = min($b, $tFim);
        if ($b > $a) $porChave[$k][] = [$a, $b];
    }
    $res = [];
    foreach ($porChave as $k => $ints) {
        usort($ints, fn($x, $y) => $x[0] <=> $y[0]);
        $tot = 0; [$ca, $cb] = $ints[0];
        foreach (array_slice($ints, 1) as [$a, $b]) {
            if ($a <= $cb) { $cb = max($cb, $b); continue; }
            $tot += $cb - $ca; [$ca, $cb] = [$a, $b];
        }
        $res[$k] = ($tot + $cb - $ca) / 3600;
    }
    return $res;
}

/**
 * Indicadores de manutenção para o período [ini, fim] (datas Y-m-d).
 * $filtroAtivos: [raiz, ...descendentes] (como devolvido por descendentes()), ou null para todos.
 */
function kpis(string $ini, string $fim, ?array $filtroAtivos = null): array
{
    $fa = $filtroAtivos ? ' AND o.ativo_id IN (' . implode(',', array_map('intval', $filtroAtivos)) . ')' : '';
    $p = [$ini . ' 00:00:00', $fim . ' 23:59:59'];

    $k = [];
    $ab = q1("SELECT COUNT(*) abertas, COALESCE(SUM(data_prevista < CURDATE()), 0) atrasadas FROM ordens_trabalho o WHERE status IN (" . STATUS_PENDENTES . ") $fa");
    $k['abertas'] = (int)$ab['abertas']; $k['atrasadas'] = (int)$ab['atrasadas'];
    $k['pedidos_abertos'] = (int)qv("SELECT COUNT(*) FROM pedidos WHERE status = 'aberto'");

    $c = q1("SELECT COUNT(*) total,
               COALESCE(SUM(tipo IN (" . TIPOS_PLANEJADOS . ")), 0) planejadas,
               COALESCE(SUM(tipo = 'corretiva'), 0) corretivas,
               AVG(CASE WHEN tipo='corretiva' AND data_inicio IS NOT NULL AND data_fim >= data_inicio THEN TIMESTAMPDIFF(MINUTE, data_inicio, data_fim)/60 END) mttr,
               COALESCE(SUM(" . SQL_CUSTO_OT . "), 0) c_mo, COALESCE(SUM(" . SQL_CUSTO_MAT . "), 0) c_mat, COALESCE(SUM(custo_externo), 0) c_ext
             FROM ordens_trabalho o WHERE status = 'concluida' AND data_fim BETWEEN ? AND ? $fa", $p);
    $k['concluidas'] = (int)$c['total'];
    $k['corretivas'] = (int)$c['corretivas'];
    $k['planejadas'] = (int)$c['planejadas'];
    $k['pct_planejada'] = $c['total'] ? $c['planejadas'] / $c['total'] * 100 : null;
    $k['mttr'] = $c['mttr'] !== null ? (float)$c['mttr'] : null;
    $k['custo_mo'] = (float)$c['c_mo']; $k['custo_mat'] = (float)$c['c_mat']; $k['custo_ext'] = (float)$c['c_ext'];
    $k['custo_total'] = $k['custo_mo'] + $k['custo_mat'] + $k['custo_ext'];

    // Cumprimento: preventivas previstas no período que já venceram (ou foram concluídas) e foram feitas no prazo
    $cp = q1("SELECT COUNT(*) prev, COALESCE(SUM(status='concluida' AND DATE(data_fim) <= data_prevista), 0) no_prazo
              FROM ordens_trabalho o WHERE tipo IN (" . TIPOS_PLANEJADOS . ") AND status <> 'cancelada'
                AND data_prevista BETWEEN ? AND ? AND (status = 'concluida' OR data_prevista < CURDATE()) $fa", [$ini, $fim]);
    $k['cumprimento'] = $cp['prev'] ? $cp['no_prazo'] / $cp['prev'] * 100 : null;

    // Disponibilidade e MTBF: base = ativos raiz (ou o ativo filtrado); paradas de componentes contam
    // para a raiz, e intervalos sobrepostos (pai e componente parados juntos) são contados uma vez só.
    $ate = min($fim, date('Y-m-d'));                                   // não conta horas futuras
    $diasDecorridos = max(0, (strtotime($ate) - strtotime($ini)) / 86400 + 1);
    if ($filtroAtivos) {
        $raiz = (int)$filtroAtivos[0];
        $base = [$raiz => (float)qv("SELECT horas_operacao_dia FROM ativos WHERE id = ? AND status <> 'desativado'", [$raiz])];
        $chave = fn(int $id) => $raiz;
    } else {
        $base = [];
        foreach (q("SELECT id, horas_operacao_dia FROM ativos WHERE parent_id IS NULL AND status <> 'desativado'") as $a) $base[(int)$a['id']] = (float)$a['horas_operacao_dia'];
        $mapa = mapa_raiz();
        $chave = fn(int $id) => isset($base[$mapa[$id] ?? 0]) ? $mapa[$id] : null;
    }
    $horasOp = array_sum($base) * $diasDecorridos;
    $parada = $diasDecorridos > 0 ? array_sum(paradas_no_periodo($ini, $ate, $filtroAtivos, $chave)) : 0;
    $paradaCorr = $diasDecorridos > 0 ? array_sum(paradas_no_periodo($ini, $ate, $filtroAtivos, $chave, true)) : 0;
    $k['horas_operacao'] = $horasOp;
    $k['horas_parada'] = $horasOp > 0 ? min($parada, $horasOp) : $parada;
    $k['disponibilidade'] = $horasOp > 0 ? max(0, min(100, ($horasOp - $parada) / $horasOp * 100)) : null;
    $k['mtbf'] = $k['corretivas'] > 0 && $horasOp > 0 ? max(0, ($horasOp - $paradaCorr) / $k['corretivas']) : null;
    return $k;
}

/** Série mensal (N meses terminando no mês de $fim) de OTs concluídas por grupo e custo. */
function serie_mensal(string $fim, int $meses = 12): array
{
    $ini = date('Y-m-01', strtotime(date('Y-m-01', strtotime($fim)) . ' -' . ($meses - 1) . ' months'));
    $rows = q("SELECT DATE_FORMAT(data_fim,'%Y-%m') m,
                 SUM(tipo='corretiva') corr, SUM(tipo IN (" . TIPOS_PLANEJADOS . ")) plan, SUM(tipo='melhoria') melh,
                 SUM(" . SQL_CUSTO_OT . " + " . SQL_CUSTO_MAT . " + custo_externo) custo
               FROM ordens_trabalho o WHERE status='concluida' AND data_fim BETWEEN ? AND ? GROUP BY DATE_FORMAT(data_fim,'%Y-%m')", [$ini, "$fim 23:59:59"]);
    $idx = array_column($rows, null, 'm');
    $out = ['labels' => [], 'corr' => [], 'plan' => [], 'melh' => [], 'custo' => []];
    $mesesPt = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    for ($i = 0; $i < $meses; $i++) {
        $t = strtotime("$ini +$i months");
        $m = date('Y-m', $t);
        $out['labels'][] = $mesesPt[(int)date('n', $t) - 1] . '/' . date('y', $t);
        $out['corr'][] = (int)($idx[$m]['corr'] ?? 0);
        $out['plan'][] = (int)($idx[$m]['plan'] ?? 0);
        $out['melh'][] = (int)($idx[$m]['melh'] ?? 0);
        $out['custo'][] = round((float)($idx[$m]['custo'] ?? 0), 2);
    }
    return $out;
}

function chart_js(): string { return cdn_js('chart.js@4.4.4/dist/chart.umd.min.js'); }

/** Atributo data-grafico com a configuração do Chart.js (escapado para HTML; o app.js desenha). */
function grafico_attr(array $cfg): string { return 'data-grafico="' . e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"'; }
