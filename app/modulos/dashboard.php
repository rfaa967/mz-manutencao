<?php
if (!pode('ver')) redirect('pedidos');
$u = usuario();
$fim = date('Y-m-d'); $ini = date('Y-m-d', strtotime('-29 days'));
$k = kpis($ini, $fim);
$serie = serie_mensal($fim, 12);

$minhas = $u['tecnico_id'] ? q("SELECT o.*, a.codigo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
    WHERE o.responsavel_id = ? AND o.status IN (" . STATUS_PENDENTES . ") ORDER BY o.data_prevista IS NULL, o.data_prevista LIMIT 10", [$u['tecnico_id']]) : [];
$urgentes = q("SELECT o.*, a.codigo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
    WHERE o.status IN (" . STATUS_PENDENTES . ") AND (o.prioridade IN ('urgente','alta') OR o.data_prevista < CURDATE())
    ORDER BY FIELD(o.prioridade,'urgente','alta','media','baixa'), o.data_prevista LIMIT 10");
$pedidos = pode('gerir') ? q("SELECT p.*, a.codigo FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id WHERE p.status='aberto' ORDER BY FIELD(p.prioridade,'urgente','alta','media','baixa'), p.criado_em LIMIT 8") : [];
$parados = q("SELECT id, codigo, nome, status FROM ativos WHERE status IN ('parado','em_manutencao') ORDER BY criticidade, codigo LIMIT 10");
$semana = q("SELECT o.id, o.numero, o.titulo, o.data_prevista, o.tipo, a.codigo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
    WHERE o.status IN (" . STATUS_PENDENTES . ") AND o.data_prevista BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY o.data_prevista LIMIT 12");
$estoqueBaixo = (int)qv('SELECT COUNT(*) FROM artigos WHERE ativo=1 AND estoque_atual < estoque_minimo');

$rel = pode('gerir') ? url('relatorios') : null;
$cards = [
    ['OTs pendentes', $k['abertas'], 'wrench', 'primary', url('ots')],
    ['Atrasadas', $k['atrasadas'], 'alarm', $k['atrasadas'] ? 'danger' : 'success', url('ots', ['f' => 'atrasadas'])],
    pode('gerir') ? ['Pedidos a avaliar', $k['pedidos_abertos'], 'megaphone', $k['pedidos_abertos'] ? 'warning' : 'success', url('pedidos')]
                  : ['Minhas OTs abertas', $u['tecnico_id'] ? (int)qv('SELECT COUNT(*) FROM ordens_trabalho WHERE responsavel_id = ? AND status IN (' . STATUS_PENDENTES . ')', [$u['tecnico_id']]) : 0, 'person-gear', 'primary', url('ots', ['f' => 'minhas'])],
    ['Disponibilidade 30d', $k['disponibilidade'] !== null ? num($k['disponibilidade'], 1) . '%' : '—', 'activity', 'success', $rel],
    ['MTTR 30d', $k['mttr'] !== null ? num($k['mttr'], 1) . ' h' : '—', 'hourglass-split', 'info', $rel],
    ['% Planejada 30d', $k['pct_planejada'] !== null ? num($k['pct_planejada'], 0) . '%' : '—', 'calendar-check', 'info', $rel],
    ['Custo 30d', moeda($k['custo_total']), 'cash-coin', 'secondary', $rel],
    ['Peças abaixo do mín.', $estoqueBaixo, 'box-seam', $estoqueBaixo ? 'danger' : 'success', url('estoque', ['abaixo' => 1])],
];

view_header('Painel', 'dashboard'); ?>
<div class="page-title"><h1>Painel de manutenção</h1><span class="text-muted small">Indicadores dos últimos 30 dias · <?= dt($ini) ?> a <?= dt($fim) ?></span></div>
<?php if ($minhas && $u['perfil'] === 'tecnico'): ?>
<div class="card mb-3 border-primary"><div class="card-header bg-white fw-semibold">Minhas OTs (<?= count($minhas) ?>)</div><div class="list-group list-group-flush">
  <?php foreach ($minhas as $o): ?><a class="list-group-item list-group-item-action d-flex justify-content-between flex-wrap gap-1" href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>">
    <span><?= e($o['numero']) ?> · <?= e($o['codigo']) ?> — <?= e($o['titulo']) ?></span><span><?= dt($o['data_prevista']) ?> <?= badge('ot_status', $o['status']) ?></span></a><?php endforeach; ?>
</div></div>
<?php $minhasNoTopo = true; endif; ?>
<div class="row g-3 mb-3">
  <?php foreach ($cards as [$r, $v, $ic, $cor, $lnk]): ?>
    <div class="col-6 col-md-3"><?= $lnk ? '<a href="' . e($lnk) . '" class="text-decoration-none text-reset">' : '' ?><div class="card kpi h-100"><div class="card-body d-flex justify-content-between align-items-center">
      <div><div class="rot"><?= e($r) ?></div><div class="valor"><?= $v ?></div></div><i class="bi bi-<?= $ic ?> fs-2 text-<?= $cor ?>" aria-hidden="true"></i></div></div><?= $lnk ? '</a>' : '' ?></div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card h-100"><div class="card-header bg-white fw-semibold">OTs concluídas por mês</div><div class="card-body"><canvas id="gMes" height="110" role="img" aria-label="Gráfico de OTs concluídas por mês" <?= grafico_attr([
    'type' => 'bar',
    'data' => ['labels' => $serie['labels'], 'datasets' => [
        ['label' => 'Corretivas', 'data' => $serie['corr'], 'backgroundColor' => '#dc3545'],
        ['label' => 'Planejadas', 'data' => $serie['plan'], 'backgroundColor' => '#198754'],
        ['label' => 'Melhorias', 'data' => $serie['melh'], 'backgroundColor' => '#6c757d']]],
    'options' => ['responsive' => true, 'scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]]], 'plugins' => ['legend' => ['position' => 'bottom']]],
]) ?>></canvas></div></div></div>
  <div class="col-lg-4"><div class="card h-100"><div class="card-header bg-white fw-semibold">Ativos parados / em manutenção</div><ul class="list-group list-group-flush">
    <?php foreach ($parados as $a): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('ativos/ver', ['id' => $a['id']])) ?>"><?= e($a['codigo'] . ' · ' . $a['nome']) ?></a><?= badge('ativo_status', $a['status']) ?></li><?php endforeach; ?>
    <?php if (!$parados): ?><li class="list-group-item text-success"><i class="bi bi-check-circle"></i> Todos os ativos operacionais.</li><?php endif; ?>
  </ul></div></div>
</div>

<div class="row g-3">
  <?php if ($minhas && empty($minhasNoTopo)): ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Minhas OTs</div><div class="list-group list-group-flush">
    <?php foreach ($minhas as $o): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>">
      <span><?= e($o['numero']) ?> · <?= e($o['codigo']) ?> — <?= e($o['titulo']) ?></span><span><?= dt($o['data_prevista']) ?> <?= badge('ot_status', $o['status']) ?></span></a><?php endforeach; ?>
  </div></div></div>
  <?php endif; ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Prioridade alta/urgente e atrasadas</div><div class="list-group list-group-flush">
    <?php foreach ($urgentes as $o): $atr = $o['data_prevista'] && $o['data_prevista'] < date('Y-m-d'); ?><a class="list-group-item list-group-item-action d-flex justify-content-between <?= $atr ? 'atrasada' : '' ?>" href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>">
      <span><?= badge('prioridade', $o['prioridade']) ?> <?= e($o['numero']) ?> · <?= e($o['codigo']) ?> — <?= e($o['titulo']) ?></span><span class="<?= $atr ? 'text-danger' : '' ?>"><?= dt($o['data_prevista']) ?></span></a><?php endforeach; ?>
    <?php if (!$urgentes): ?><div class="list-group-item text-muted">Nada urgente.</div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-header bg-white fw-semibold">Próximos 7 dias</div><div class="list-group list-group-flush">
    <?php foreach ($semana as $o): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>">
      <span><?= badge('tipo_ot', $o['tipo']) ?> <?= e($o['codigo']) ?> — <?= e($o['titulo']) ?></span><span><?= dt($o['data_prevista']) ?></span></a><?php endforeach; ?>
    <?php if (!$semana): ?><div class="list-group-item text-muted">Sem OTs programadas.</div><?php endif; ?>
  </div></div></div>
  <?php if ($pedidos): ?>
  <div class="col-lg-6"><div class="card h-100 border-warning"><div class="card-header bg-white fw-semibold">Pedidos aguardando avaliação</div><div class="list-group list-group-flush">
    <?php foreach ($pedidos as $p): ?><a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e(url('pedidos/ver', ['id' => $p['id']])) ?>">
      <span><?= badge('prioridade', $p['prioridade']) ?> <?= e($p['numero']) ?> · <?= e($p['codigo']) ?> — <?= e($p['titulo']) ?></span><span><?= dt($p['criado_em'], true) ?></span></a><?php endforeach; ?>
  </div></div></div>
  <?php endif; ?>
</div>
<?php
$GLOBALS['__scripts'] = chart_js();
view_footer();
