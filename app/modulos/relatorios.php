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

/* ================= CONSUMO DE PEÇAS POR EQUIPAMENTO ================= */
if ($acao === 'consumo') {
    $w = ['m.baixado = 1', "o.status <> 'cancelada'", 'COALESCE(o.data_fim, o.data_abertura) BETWEEN ? AND ?'];
    $prm = [$ini . ' 00:00:00', $fim . ' 23:59:59'];
    if ($ativoF) $w[] = 'o.ativo_id IN (' . implode(',', descendentes('ativos', $ativoF)) . ')';
    $patF = patrimonio_normalizar((string)get('patrimonio', ''));
    if ($patF) { $w[] = '(a.patrimonio = ? OR o.patrimonio = ?)'; array_push($prm, $patF, $patF); }
    $artF = trim((string)get('artigo', ''));
    if ($artF !== '') { $w[] = "CONCAT_WS(' ', ar.codigo, ar.descricao) LIKE ?"; $prm[] = '%' . $artF . '%'; }
    $areaF = array_key_exists((string)get('area'), ROTULOS['area']) ? (string)get('area') : '';
    if ($areaF) { $w[] = 'o.area = ?'; $prm[] = $areaF; }
    $empF = trim((string)get('empresa', ''));
    if ($empF !== '') { $w[] = 'o.empresa = ?'; $prm[] = $empF; }
    $where = implode(' AND ', $w);
    $from = 'ot_materiais m JOIN artigos ar ON ar.id = m.artigo_id JOIN ordens_trabalho o ON o.id = m.ot_id LEFT JOIN ativos a ON a.id = o.ativo_id';
    // equipamento: o ativo cadastrado; sem ativo, o patrimônio/equipamento informado na OT
    $chave = "COALESCE(CONCAT('a', a.id), CONCAT('p', o.patrimonio), CONCAT('e', o.equipamento), 'x')";
    $linhas = q("SELECT $chave AS eq, MAX(a.id) ativo_id, MAX(COALESCE(a.patrimonio, o.patrimonio)) pat, MAX(a.codigo) cod_ativo, MAX(COALESCE(a.nome, o.equipamento)) equip,
                        ar.codigo art_codigo, ar.descricao art_desc, ar.unidade, SUM(m.quantidade) qtd, SUM(m.quantidade * m.custo_unitario) custo,
                        COUNT(DISTINCT m.ot_id) ots, MAX(COALESCE(o.data_fim, o.data_abertura)) ultima
                 FROM $from WHERE $where GROUP BY eq, ar.id, ar.codigo, ar.descricao, ar.unidade ORDER BY MAX(COALESCE(a.patrimonio, o.patrimonio)) IS NULL, MAX(COALESCE(a.patrimonio, o.patrimonio)), MAX(a.codigo), MAX(COALESCE(a.nome, o.equipamento)), SUM(m.quantidade * m.custo_unitario) DESC", $prm);
    if (get('csv') === 'resumo') {
        csv_download('consumo_pecas_por_equipamento', ['Patrimônio', 'Código ativo', 'Equipamento', 'Código peça', 'Peça / material', 'Qtd', 'Unid.', 'Custo (R$)', 'OTs', 'Último consumo'],
            array_map(fn($r) => [$r['pat'], $r['cod_ativo'], $r['equip'], $r['art_codigo'], $r['art_desc'], num($r['qtd'], 3), $r['unidade'], num($r['custo']), $r['ots'], dt($r['ultima'])], $linhas));
    }
    if (get('csv') === 'itens') {
        $itens = q("SELECT COALESCE(a.patrimonio, o.patrimonio) pat, a.codigo cod_ativo, COALESCE(a.nome, o.equipamento) equip, o.numero, o.tipo, o.area,
                           COALESCE(o.data_fim, o.data_abertura) dt, ar.codigo art_codigo, ar.descricao art_desc, ar.unidade, m.quantidade, m.custo_unitario
                    FROM $from WHERE $where ORDER BY pat, cod_ativo, dt, o.numero", $prm);
        csv_download('consumo_pecas_por_ot', ['Patrimônio', 'Código ativo', 'Equipamento', 'OT', 'Tipo', 'Área', 'Data', 'Código peça', 'Peça / material', 'Qtd', 'Unid.', 'Custo unit. (R$)', 'Total (R$)'],
            array_map(fn($r) => [$r['pat'], $r['cod_ativo'], $r['equip'], $r['numero'], rotulo('tipo_ot', $r['tipo']), $r['area'] ? rotulo('area', $r['area']) : '', dt($r['dt']),
                                 $r['art_codigo'], $r['art_desc'], num($r['quantidade'], 3), $r['unidade'], num($r['custo_unitario'], 4), num($r['quantidade'] * $r['custo_unitario'])], $itens));
    }
    $grupos = [];
    foreach ($linhas as $l) $grupos[$l['eq']][] = $l;
    $total = array_sum(array_column($linhas, 'custo'));
    $qsC = fn(string $t) => '?' . http_build_query(['r' => 'relatorios/consumo', 'de' => $ini, 'ate' => $fim, 'ativo' => $ativoF ?: null, 'patrimonio' => $patF, 'artigo' => $artF ?: null, 'area' => $areaF ?: null, 'empresa' => $empF ?: null, 'csv' => $t]);
    $empresas = array_column(q('SELECT DISTINCT empresa FROM ordens_trabalho WHERE empresa IS NOT NULL ORDER BY empresa'), 'empresa');

    view_header('Consumo de peças por equipamento', 'relatorios'); ?>
    <?php if ($avisoPeriodo): ?><div class="alert alert-warning"><?= e($avisoPeriodo) ?></div><?php endif; ?>
    <div class="page-title"><h1>Consumo de peças por equipamento</h1>
      <div class="d-flex gap-2 no-print"><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('relatorios')) ?>">Relatórios</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e($qsC('resumo')) ?>"><i class="bi bi-filetype-csv"></i> CSV resumo</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= e($qsC('itens')) ?>"><i class="bi bi-filetype-csv"></i> CSV por OT</a>
        <button class="btn btn-outline-secondary btn-sm" data-print><i class="bi bi-printer"></i> Imprimir</button></div></div>
    <form class="card card-body py-2 mb-3 no-print" method="get"><input type="hidden" name="r" value="relatorios/consumo"><div class="row g-2 align-items-end">
      <div class="col-6 col-md-2"><label class="form-label" for="c_de">De</label><input type="date" class="form-control form-control-sm" id="c_de" name="de" value="<?= e($ini) ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label" for="c_ate">Até</label><input type="date" class="form-control form-control-sm" id="c_ate" name="ate" value="<?= e($fim) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="c_at">Ativo (inclui componentes)</label><?= sel('ativo', arvore_opcoes('ativos'), $ativoF ?: null, true, 'id="c_at" class="form-select form-select-sm"', 'Todos') ?></div>
      <div class="col-6 col-md-2"><label class="form-label" for="c_pat">Cód. patrimônio</label><input class="form-control form-control-sm text-uppercase" id="c_pat" name="patrimonio" value="<?= e($patF) ?>" placeholder="C1.10.016"></div>
      <div class="col-6 col-md-3"><label class="form-label" for="c_art">Peça / material</label><input class="form-control form-control-sm" id="c_art" name="artigo" value="<?= e($artF) ?>" placeholder="código ou descrição"></div>
      <div class="col-6 col-md-2"><label class="form-label" for="c_area">Área</label><?= sel('area', lista_rotulos('area'), $areaF ?: null, true, 'id="c_area" class="form-select form-select-sm"', 'Todas') ?></div>
      <div class="col-6 col-md-2"><label class="form-label" for="c_emp">Empresa</label><?= sel('empresa', array_combine($empresas, $empresas) ?: [], $empF ?: null, true, 'id="c_emp" class="form-select form-select-sm"', 'Todas') ?></div>
      <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Atualizar</button></div></div></form>
    <p class="small text-muted">Peças e materiais baixados do estoque nas OTs (preventivas e OMs), pela data de término da OT — ou de abertura, se ainda não concluída. Período: <?= dt($ini) ?> a <?= dt($fim) ?>. Total: <strong><?= moeda($total) ?></strong>.</p>
    <?php foreach ($grupos as $g): $h = $g[0]; ?>
      <div class="card mb-3"><div class="card-header bg-white d-flex justify-content-between flex-wrap gap-2">
        <div><?= $h['pat'] ? '<span class="badge text-bg-dark me-1">' . e($h['pat']) . '</span>' : '<span class="badge text-bg-light me-1">sem patrimônio</span>' ?>
          <?= $h['ativo_id'] ? '<a class="fw-semibold" href="' . e(url('ativos/ver', ['id' => $h['ativo_id'], 'tab' => 'pecas'])) . '">' . e($h['cod_ativo'] . ' · ' . $h['equip']) . '</a>' : '<span class="fw-semibold">' . e($h['equip'] ?: '(sem equipamento informado)') . '</span>' ?></div>
        <div class="fw-semibold"><?= moeda(array_sum(array_column($g, 'custo'))) ?></div></div>
        <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Código</th><th>Peça / material</th><th class="text-end">Qtd</th><th class="text-end">OTs</th><th>Último</th><th class="text-end">Custo</th></tr></thead><tbody>
        <?php foreach ($g as $r): ?><tr><td><?= e($r['art_codigo']) ?></td><td><?= e($r['art_desc']) ?></td><td class="text-end"><?= num($r['qtd'], 2) ?> <?= e($r['unidade']) ?></td><td class="text-end"><?= (int)$r['ots'] ?></td><td><?= dt($r['ultima']) ?></td><td class="text-end"><?= moeda($r['custo']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    <?php endforeach; if (!$grupos): ?><div class="alert alert-light border">Nenhuma peça baixada em OT com estes filtros.</div><?php endif; ?>
    <?php view_footer(); exit;
}

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
// OMs (OTs sem plano) por área no período: abertas no período, concluídas e ainda pendentes
$omsArea = q("SELECT COALESCE(o.area, '') area, COUNT(*) n, SUM(o.status = 'concluida') concl, SUM(o.status IN (" . STATUS_PENDENTES . ")) pend,
                     AVG(CASE WHEN o.status = 'concluida' THEN DATEDIFF(o.data_fim, o.data_abertura) END) dias
              FROM ordens_trabalho o WHERE o.plano_id IS NULL AND o.data_abertura BETWEEN ? AND ? $fa GROUP BY COALESCE(o.area, '') ORDER BY n DESC", $p);
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
<div class="page-title"><h1>Relatórios e indicadores</h1><div class="d-flex gap-2 no-print">
  <a class="btn btn-outline-primary btn-sm" href="<?= e(url('relatorios/consumo', ['de' => $ini, 'ate' => $fim, 'ativo' => $ativoF ?: null])) ?>"><i class="bi bi-box-seam"></i> Consumo de peças por equipamento</a>
  <button class="btn btn-outline-secondary btn-sm" data-print><i class="bi bi-printer"></i> Imprimir</button></div></div>
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
  <div class="col-lg-8"><div class="card h-100"><div class="card-header bg-white fw-semibold">Custo de manutenção por mês (12 meses)</div><div class="card-body"><canvas id="gCusto" height="110" role="img" aria-label="Gráfico de custo por mês" <?= grafico_attr([
    'type' => 'line', 'moeda' => true,
    'data' => ['labels' => $serie['labels'], 'datasets' => [['label' => 'Custo (R$)', 'data' => $serie['custo'], 'borderColor' => '#0d6efd', 'backgroundColor' => 'rgba(13,110,253,.1)', 'fill' => true, 'tension' => .3]]],
    'options' => ['plugins' => ['legend' => ['display' => false]]],
]) ?>></canvas></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header bg-white fw-semibold">OTs por tipo no período</div><div class="card-body"><canvas id="gTipo" role="img" aria-label="Gráfico de OTs por tipo" <?= grafico_attr([
    'type' => 'doughnut',
    'data' => ['labels' => array_map(fn($t) => rotulo('tipo_ot', $t['tipo']), $porTipo),
               'datasets' => [['data' => array_map('intval', array_column($porTipo, 'n')), 'backgroundColor' => array_map(fn($t) => CORES_TIPO[$t['tipo']] ?? '#999', $porTipo)]]],
    'options' => ['plugins' => ['legend' => ['position' => 'bottom']]],
]) ?>></canvas></div></div></div>
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

<div class="card mb-3"><div class="card-header bg-white fw-semibold d-flex justify-content-between">OMs (não preventivas) abertas no período, por área
  <a class="small no-print" href="<?= e(url('ots', ['f' => 'todas', 'origem' => 'om', 'de' => $ini, 'ate' => $fim])) ?>">ver OMs</a></div>
  <table class="table table-sm mb-0"><thead><tr><th>Área</th><th class="text-end">OMs</th><th class="text-end">Concluídas</th><th class="text-end">Pendentes</th><th class="text-end">Prazo médio (dias)</th></tr></thead><tbody>
  <?php foreach ($omsArea as $oa): ?><tr><td><?= $oa['area'] !== '' ? e(rotulo('area', $oa['area'])) : '<span class="text-muted">não informada</span>' ?></td><td class="text-end"><?= (int)$oa['n'] ?></td><td class="text-end"><?= (int)$oa['concl'] ?></td><td class="text-end <?= $oa['pend'] ? 'text-warning' : '' ?>"><?= (int)$oa['pend'] ?></td><td class="text-end"><?= $oa['dias'] !== null ? num($oa['dias'], 1) : '—' ?></td></tr><?php endforeach; ?>
  <?php if (!$omsArea): ?><tr><td colspan="5" class="text-muted text-center">Nenhuma OM aberta no período.</td></tr><?php endif; ?></tbody></table></div>

<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold d-flex justify-content-between">Consumo de materiais (top 20) <span class="small no-print"><a href="<?= e(url('relatorios/consumo', ['de' => $ini, 'ate' => $fim, 'ativo' => $ativoF ?: null])) ?>">por equipamento</a> · <a href="<?= e($qs('materiais')) ?>">CSV</a></span></div>
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
$GLOBALS['__scripts'] = chart_js();
view_footer();
