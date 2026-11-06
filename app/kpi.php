<?php
declare(strict_types=1);

const TIPOS_PLANEJADOS = "'preventiva','preditiva','inspecao','lubrificacao','calibracao'";

/** Custo total de uma OT em SQL (alias o). */
const SQL_CUSTO_OT = '(SELECT COALESCE(SUM(horas*custo_hora),0) FROM ot_mao_obra WHERE ot_id=o.id)';
const SQL_CUSTO_MAT = '(SELECT COALESCE(SUM(quantidade*custo_unitario),0) FROM ot_materiais WHERE ot_id=o.id)';

/**
 * Indicadores de manutenção para o período [ini, fim] (datas Y-m-d).
 * $filtroAtivos: lista de IDs (null = todos)
 */
function kpis(string $ini, string $fim, ?array $filtroAtivos = null): array
{
    $fa = $filtroAtivos ? ' AND o.ativo_id IN (' . implode(',', array_map('intval', $filtroAtivos)) . ')' : '';
    // base de horas: ativo raiz do filtro (descendentes param junto com ele) ou todos os ativos de 1º nível
    $faA = $filtroAtivos ? ' AND a.id = ' . (int)$filtroAtivos[0] : ' AND a.parent_id IS NULL';
    $p = [$ini . ' 00:00:00', $fim . ' 23:59:59'];

    $k = [];
    $k['abertas'] = (int)qv("SELECT COUNT(*) FROM ordens_trabalho o WHERE status NOT IN ('concluida','cancelada') $fa");
    $k['atrasadas'] = (int)qv("SELECT COUNT(*) FROM ordens_trabalho o WHERE status NOT IN ('concluida','cancelada') AND data_prevista < CURDATE() $fa");
    $k['pedidos_abertos'] = (int)qv("SELECT COUNT(*) FROM pedidos WHERE status = 'aberto'");

    $c = q1("SELECT COUNT(*) total,
               SUM(tipo IN (" . TIPOS_PLANEJADOS . ")) planejadas,
               SUM(tipo = 'corretiva') corretivas,
               AVG(CASE WHEN tipo='corretiva' AND data_inicio IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, data_inicio, data_fim)/60 END) mttr,
               SUM(CASE WHEN tipo='corretiva' THEN horas_parada ELSE 0 END) parada_corr,
               SUM(horas_parada) parada,
               SUM(" . SQL_CUSTO_OT . ") c_mo, SUM(" . SQL_CUSTO_MAT . ") c_mat, SUM(custo_externo) c_ext
             FROM ordens_trabalho o WHERE status = 'concluida' AND data_fim BETWEEN ? AND ? $fa", $p);
    $k['concluidas'] = (int)$c['total'];
    $k['corretivas'] = (int)$c['corretivas'];
    $k['planejadas'] = (int)$c['planejadas'];
    $k['pct_planejada'] = $c['total'] ? $c['planejadas'] / $c['total'] * 100 : null;
    $k['mttr'] = $c['mttr'] !== null ? (float)$c['mttr'] : null;
    $k['horas_parada'] = (float)$c['parada'];
    $k['custo_mo'] = (float)$c['c_mo']; $k['custo_mat'] = (float)$c['c_mat']; $k['custo_ext'] = (float)$c['c_ext'];
    $k['custo_total'] = $k['custo_mo'] + $k['custo_mat'] + $k['custo_ext'];

    // Cumprimento do plano preventivo: OTs planejadas com previsão no período, concluídas até a data prevista
    $cp = q1("SELECT COUNT(*) prev, SUM(status='concluida' AND DATE(data_fim) <= data_prevista) no_prazo
              FROM ordens_trabalho o WHERE tipo IN (" . TIPOS_PLANEJADOS . ") AND status <> 'cancelada' AND data_prevista BETWEEN ? AND ? $fa", [$ini, $fim]);
    $k['cumprimento'] = $cp['prev'] ? $cp['no_prazo'] / $cp['prev'] * 100 : null;

    // Disponibilidade e MTBF (base: horas de operação/dia dos ativos ativos)
    $dias = (strtotime($fim) - strtotime($ini)) / 86400 + 1;
    $horasOp = (float)qv("SELECT COALESCE(SUM(horas_operacao_dia),0) FROM ativos a WHERE a.status <> 'desativado' $faA") * $dias;
    $k['horas_operacao'] = $horasOp;
    $k['disponibilidade'] = $horasOp > 0 ? max(0, ($horasOp - $k['horas_parada']) / $horasOp * 100) : null;
    $k['mtbf'] = $k['corretivas'] > 0 ? ($horasOp - (float)$c['parada_corr']) / $k['corretivas'] : null;
    return $k;
}

/** Série mensal (últimos N meses até $fim) de OTs concluídas por grupo e custo. */
function serie_mensal(string $fim, int $meses = 12): array
{
    $ini = date('Y-m-01', strtotime("$fim -" . ($meses - 1) . ' months'));
    $rows = q("SELECT DATE_FORMAT(data_fim,'%Y-%m') m,
                 SUM(tipo='corretiva') corr, SUM(tipo IN (" . TIPOS_PLANEJADOS . ")) plan, SUM(tipo='melhoria') melh,
                 SUM(" . SQL_CUSTO_OT . " + " . SQL_CUSTO_MAT . " + custo_externo) custo
               FROM ordens_trabalho o WHERE status='concluida' AND data_fim BETWEEN ? AND ? GROUP BY m", [$ini, "$fim 23:59:59"]);
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

function chart_js(): string { return '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>'; }
