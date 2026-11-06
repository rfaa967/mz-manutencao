<?php
exigir('gerir');

$ini = (string)get('de', date('Y-m-01', strtotime(date('Y-m-01') . ' -5 months')));
$fim = (string)get('ate', date('Y-m-d'));
$avisoPeriodo = null;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim) || $ini > $fim || strtotime($fim) - strtotime($ini) > 5 * 366 * 86400) {
    $avisoPeriodo = 'Período inválido (data inicial depois da final, formato incorreto ou mais de 5 anos). Mostrando o mês atual.';
    $ini = date('Y-m-01'); $fim = date('Y-m-d');
}
$ativoF = (int)get('ativo', 0);
$ids = $ativoF ? descendentes('ativos', $ativoF) : null;
$fa = $ids ? ' AND o.ativo_id IN (' . implode(',', $ids) . ')' : '';
$p = [$ini . ' 00:00:00', $fim . ' 23:59:59'];
$dias = (strtotime($fim) - strtotime($ini)) / 86400 + 1;

$k = kpis($ini, $fim, $ids);

$porAtivo = q("SELECT a.id, a.codigo, a.nome, a.criticidade, a.horas_operacao_dia,
      COUNT(o.id) ots, SUM(o.tipo='corretiva') falhas,
      AVG(CASE WHEN o.tipo='corretiva' AND o.data_inicio IS NOT NULL THEN TIMESTAMPDIFF(MINUTE,o.data_inicio,o.data_fim)/60 END) mttr,
      SUM(" . SQL_CUSTO_OT . ") mo, SUM(" . SQL_CUSTO_MAT . ") mat, SUM(o.custo_externo) ext,
      SUM(" . SQL_CUSTO_OT . " + " . SQL_CUSTO_MAT . " + o.custo_externo) total
    FROM ordens_trabalho o JOIN ativos a ON a.id=o.ativo_id
    WHERE o.status='concluida' AND o.data_fim BETWEEN ? AND ? $fa
    GROUP BY a.id, a.codigo, a.nome, a.criticidade, a.horas_operacao_dia ORDER BY total DESC", $p);
$paradaAtivo = paradas_no_periodo($ini, $fim, $ids, fn(int $id) => $id);
$paradaCorrAtivo = paradas_no_periodo($ini, $fim, $ids, fn(int $id) => $id, true);
$diasDec = max(0, (strtotime(min($fim, date('Y-m-d'))) - strtotime($ini)) / 86400 + 1);
foreach ($porAtivo as &$r) {
    $h = $diasDec * (float)$r['horas_operacao_dia'];
    $r['parada'] = $paradaAtivo[(int)$r['id']] ?? 0;
    $pc = $paradaCorrAtivo[(int)$r['id']] ?? 0;
    $r['custo'] = $r['mo'] + $r['mat'] + $r['ext'];
    $r['disp'] = $h > 0 ? max(0, min(100, ($h - $r['parada']) / $h * 100)) : null;
    $r['mtbf'] = $r['falhas'] > 0 && $h > 0 ? max(0, ($h - $pc) / $r['falhas']) : null;
}
unset($r);

$causas = q("SELECT COALESCE(causa_falha,'(não informada)') causa, COUNT(*) n, SUM(horas_parada) parada FROM ordens_trabalho o
             WHERE tipo='corretiva' AND status='concluida' AND data_fim BETWEEN ? AND ? $fa GROUP BY COALESCE(causa_falha,'(não informada)') ORDER BY n DESC LIMIT 15", $p);
$tecnicos = q("SELECT t.nome, SUM(m.horas) horas, SUM(m.horas*m.custo_hora) custo, COUNT(DISTINCT m.ot_id) ots
               FROM ot_mao_obra m JOIN tecnicos t ON t.id=m.tecnico_id JOIN ordens_trabalho o ON o.id=m.ot_id
               WHERE m.data BETWEEN ? AND ? $fa GROUP BY t.id, t.nome ORDER BY horas DESC", [$ini, $fim]);
$materiais = q("SELECT a.codigo, a.descricao, a.unidade, SUM(m.quantidade) qtd, SUM(m.quantidade*m.custo_unitario) custo
                FROM ot_materiais m JOIN artigos a ON a.id=m.artigo_id JOIN ordens_trabalho o ON o.id=m.ot_id
                WHERE m.baixado = 1 AND o.status='concluida' AND o.data_fim BETWEEN ? AND ? $fa GROUP BY a.id, a.codigo, a.descricao, a.unidade ORDER BY custo DESC LIMIT 20", $p);
$porTipo = q("SELECT tipo, COUNT(*) n FROM ordens_trabalho o WHERE status='concluida' AND data_fim BETWEEN ? AND ? $fa GROUP BY tipo", $p);
$semPlano = q("SELECT a.id, a.codigo, a.nome, a.criticidade FROM ativos a WHERE a.status <> 'desativado' AND a.criticidade IN ('A','B')
               AND NOT EXISTS (SELECT 1 FROM planos_manutencao p WHERE p.ativo_id=a.id AND p.ativo=1) ORDER BY a.criticidade, a.codigo LIMIT 30");
$backlog = q("SELECT o.tipo, COUNT(*) n, SUM(o.data_prevista < CURDATE()) atrasadas FROM ordens_trabalho o WHERE status IN (" . STATUS_PENDENTES . ") $fa GROUP BY o.tipo");

if ($csv = get('csv')) {
    match ($csv) {
        'ativos' => csv_download('custos_por_ativo', ['Código', 'Ativo', 'Crit.', 'OTs', 'Falhas', 'Horas parada', 'Disponibilidade %', 'MTBF h', 'MTTR h', 'Mão de obra', 'Materiais', 'Externo', 'Total'],
            array_map(fn($r) => [$r['codigo'], $r['nome'], $r['criticidade'], $r['ots'], $r['falhas'], num($r['parada']), $r['disp'] !== null ? num($r['disp'], 1) : '', $r['mtbf'] !== null ? num($r['mtbf'], 0) : '', $r['mttr'] !== null ? num($r['mttr'], 1) : '', num($r['mo']), num($r['mat']), num($r['ext']), num($r['custo'])], $porAtivo)),
        'causas' => csv_download('causas_falha', ['Causa', 'Ocorrências', 'Horas parada'], array_map(fn($r) => [$r['causa'], $r['n'], num($r['parada'])], $causas)),
        'tecnicos' => csv_download('horas_tecnicos', ['Técnico', 'Horas', 'OTs', 'Custo'], array_map(fn($r) => [$r['nome'], num($r['horas']), $r['ots'], num($r['custo'])], $tecnicos)),
        'materiais' => csv_download('consumo_materiais', ['Código', 'Descrição', 'Qtd', 'Unid.', 'Custo'], array_map(fn($r) => [$r['codigo'], $r['descricao'], num($r['qtd'], 3), $r['unidade'], num($r['custo'])], $materiais)),
        default => null,
    };
}

$serie = serie_mensal($fim, 12);
$qs = fn(string $t) => '?' . http_build_query(['r' => 'relatorios', 'de' => $ini, 'ate' => $fim, 'ativo' => $ativoF ?: null, 'csv' => $t]);
$totalCausas = max(1, array_sum(array_column($causas, 'n')));

view_header('Relatórios & KPIs', 'relatorios'); ?>
<?php if ($avisoPeriodo): ?><div class="alert alert-warning"><?= e($avisoPeriodo) ?></div><?php endif; ?>
<div class="page-title"><h1>Relatórios e indicadores</h1><button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button></div>
<form class="card card-body py-2 mb-3 no-print" method="get"><input type="hidden" name="r" value="relatorios"><div class="row g-2 align-items-end">
  <div class="col-md-2"><label class="form-label">De</label><input type="date" class="form-control form-control-sm" name="de" value="<?= e($ini) ?>"></div>
  <div class="col-md-2"><label class="form-label">Até</label><input type="date" class="form-control form-control-sm" name="ate" value="<?= e($fim) ?>"></div>
  <div class="col-md-5"><label class="form-label">Ativo (inclui componentes)</label><?= sel('ativo', arvore_opcoes('ativos'), $ativoF ?: null, true, 'class="form-select form-select-sm"', 'Todos os ativos') ?></div>
  <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Atualizar</button></div></div></form>

<div class="row g-3 mb-3">
<?php foreach ([
    ['Disponibilidade', $k['disponibilidade'] !== null ? num($k['disponibilidade'], 1) . '%' : '—', '(horas operação − parada) / horas operação'],
    ['MTBF', $k['mtbf'] !== null ? num($k['mtbf'], 0) . ' h' : '—', 'tempo médio entre falhas'],
    ['MTTR', $k['mttr'] !== null ? num($k['mttr'], 1) . ' h' : '—', 'tempo médio de reparo (corretivas)'],
    ['Horas de parada', num($k['horas_parada'], 1) . ' h', $k['corretivas'] . ' falha(s)'],
    ['OTs concluídas', $k['concluidas'], $k['planejadas'] . ' planejadas · ' . $k['corretivas'] . ' corretivas'],
    ['% Manutenção planejada', $k['pct_planejada'] !== null ? num($k['pct_planejada'], 1) . '%' : '—', 'meta de referência: ≥ 70%'],
    ['Cumprimento preventivas', $k['cumprimento'] !== null ? num($k['cumprimento'], 1) . '%' : '—', 'concluídas até a data prevista'],
    ['Custo total', moeda($k['custo_total']), 'MO ' . moeda($k['custo_mo']) . ' · Mat ' . moeda($k['custo_mat']) . ' · Ext ' . moeda($k['custo_ext'])],
] as [$r, $v, $s]): ?>
  <div class="col-6 col-md-3"><div class="card kpi h-100"><div class="card-body"><div class="rot"><?= $r ?></div><div class="valor fs-3"><?= $v ?></div><div class="small text-muted"><?= $s ?></div></div></div></div>
<?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card h-100"><div class="card-header bg-white fw-semibold">Custo de manutenção por mês (12 meses)</div><div class="card-body"><canvas id="gCusto" height="110"></canvas></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header bg-white fw-semibold">OTs por tipo no período</div><div class="card-body"><canvas id="gTipo"></canvas></div></div></div>
</div>

<div class="card mb-3"><div class="card-header bg-white fw-semibold d-flex justify-content-between">Indicadores e custos por ativo <a class="small no-print" href="<?= e($qs('ativos')) ?>">CSV</a></div><div class="table-responsive">
<table class="table table-sm table-hover mb-0"><thead><tr><th>Ativo</th><th>Crit.</th><th class="text-end">OTs</th><th class="text-end">Falhas</th><th class="text-end">Parada (h)</th><th class="text-end">Disp.</th><th class="text-end">MTBF</th><th class="text-end">MTTR</th><th class="text-end">Mão obra</th><th class="text-end">Materiais</th><th class="text-end">Externo</th><th class="text-end">Total</th></tr></thead><tbody>
<?php foreach ($porAtivo as $r): ?><tr data-href="<?= e(url('ativos/ver', ['id' => $r['id']])) ?>"><td><?= e($r['codigo'] . ' · ' . $r['nome']) ?></td><td><?= e($r['criticidade']) ?></td><td class="text-end"><?= $r['ots'] ?></td><td class="text-end"><?= (int)$r['falhas'] ?></td>
  <td class="text-end"><?= num($r['parada'], 1) ?></td><td class="text-end"><?= $r['disp'] !== null ? num($r['disp'], 1) . '%' : '—' ?></td><td class="text-end"><?= $r['mtbf'] !== null ? num($r['mtbf'], 0) : '—' ?></td><td class="text-end"><?= $r['mttr'] !== null ? num($r['mttr'], 1) : '—' ?></td>
  <td class="text-end"><?= moeda($r['mo']) ?></td><td class="text-end"><?= moeda($r['mat']) ?></td><td class="text-end"><?= moeda($r['ext']) ?></td><td class="text-end fw-semibold"><?= moeda($r['custo']) ?></td></tr>
<?php endforeach; if (!$porAtivo): ?><tr><td colspan="12" class="text-center text-muted">Sem OTs concluídas no período.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold d-flex justify-content-between">Pareto de causas de falha <a class="small no-print" href="<?= e($qs('causas')) ?>">CSV</a></div><div class="card-body">
    <?php $acum = 0; foreach ($causas as $c): $pct = $c['n'] / $totalCausas * 100; $acum += $pct; ?>
      <div class="d-flex justify-content-between small"><span><?= e($c['causa']) ?></span><span><?= $c['n'] ?> · <?= num($c['parada'], 1) ?> h · <span class="text-muted">acum. <?= num($acum, 0) ?>%</span></span></div>
      <div class="progress mb-2" style="height:8px"><div class="progress-bar bg-danger" style="width:<?= number_format($pct, 1, '.', '') ?>%"></div></div>
    <?php endforeach; if (!$causas): ?><span class="text-muted small">Sem falhas no período.</span><?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold d-flex justify-content-between">Horas por técnico <a class="small no-print" href="<?= e($qs('tecnicos')) ?>">CSV</a></div>
    <table class="table table-sm mb-0"><thead><tr><th>Técnico</th><th class="text-end">OTs</th><th class="text-end">Horas</th><th class="text-end">Custo</th></tr></thead><tbody>
    <?php foreach ($tecnicos as $t): ?><tr><td><?= e($t['nome']) ?></td><td class="text-end"><?= $t['ots'] ?></td><td class="text-end"><?= num($t['horas'], 1) ?></td><td class="text-end"><?= moeda($t['custo']) ?></td></tr><?php endforeach; ?>
    <?php if (!$tecnicos): ?><tr><td colspan="4" class="text-muted text-center">Sem apontamentos.</td></tr><?php endif; ?></tbody></table></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold d-flex justify-content-between">Consumo de materiais (top 20) <a class="small no-print" href="<?= e($qs('materiais')) ?>">CSV</a></div>
    <table class="table table-sm mb-0"><thead><tr><th>Artigo</th><th class="text-end">Qtd</th><th class="text-end">Custo</th></tr></thead><tbody>
    <?php foreach ($materiais as $m): ?><tr><td><?= e($m['codigo'] . ' · ' . $m['descricao']) ?></td><td class="text-end"><?= num($m['qtd'], 2) ?> <?= e($m['unidade']) ?></td><td class="text-end"><?= moeda($m['custo']) ?></td></tr><?php endforeach; ?>
    <?php if (!$materiais): ?><tr><td colspan="3" class="text-muted text-center">Sem consumo.</td></tr><?php endif; ?></tbody></table></div></div>
  <div class="col-lg-3"><div class="card h-100"><div class="card-header bg-white fw-semibold">Backlog atual</div>
    <table class="table table-sm mb-0"><thead><tr><th>Tipo</th><th class="text-end">Abertas</th><th class="text-end">Atras.</th></tr></thead><tbody>
    <?php foreach ($backlog as $b): ?><tr><td><?= e(rotulo('tipo_ot', $b['tipo'])) ?></td><td class="text-end"><?= $b['n'] ?></td><td class="text-end <?= $b['atrasadas'] ? 'text-danger' : '' ?>"><?= (int)$b['atrasadas'] ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
  <div class="col-lg-3"><div class="card h-100"><div class="card-header bg-white fw-semibold">Ativos A/B sem plano preventivo</div><ul class="list-group list-group-flush small">
    <?php foreach ($semPlano as $s): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('planos/form', ['ativo' => $s['id']])) ?>"><?= e($s['codigo'] . ' · ' . $s['nome']) ?></a><span class="badge text-bg-light"><?= $s['criticidade'] ?></span></li><?php endforeach; ?>
    <?php if (!$semPlano): ?><li class="list-group-item text-success">Todos cobertos.</li><?php endif; ?></ul></div></div>
</div>
<?php
$tl = array_map(fn($t) => rotulo('tipo_ot', $t['tipo']), $porTipo);
$tc = array_map(fn($t) => ['corretiva' => '#dc3545', 'preventiva' => '#198754', 'preditiva' => '#0dcaf0', 'inspecao' => '#0d6efd', 'lubrificacao' => '#ffc107', 'calibracao' => '#6c757d', 'melhoria' => '#212529'][$t['tipo']] ?? '#999', $porTipo);
$GLOBALS['__scripts'] = chart_js() . '<script>
new Chart(document.getElementById("gCusto"),{type:"line",data:{labels:' . json_encode($serie['labels']) . ',datasets:[{label:"Custo (R$)",data:' . json_encode($serie['custo']) . ',borderColor:"#0d6efd",backgroundColor:"rgba(13,110,253,.1)",fill:true,tension:.3}]},
 options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>"R$ "+v.toLocaleString("pt-BR")}}}}});
new Chart(document.getElementById("gTipo"),{type:"doughnut",data:{labels:' . json_encode($tl) . ',datasets:[{data:' . json_encode(array_map('intval', array_column($porTipo, 'n'))) . ',backgroundColor:' . json_encode($tc) . '}]},options:{plugins:{legend:{position:"bottom"}}}});
</script>';
view_footer();
