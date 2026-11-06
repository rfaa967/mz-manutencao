<?php
exigir('ver');

$cores = CORES_TIPO;

/* Eventos JSON para o FullCalendar: OTs existentes + projeção dos planos por tempo */
if ($acao === 'eventos') {
    $ini = substr((string)get('start', date('Y-m-01')), 0, 10);
    $fim = substr((string)get('end', date('Y-m-t')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim) || $fim < $ini || strtotime($fim) - strtotime($ini) > 120 * 86400) json_out([]);
    $resp = get('resp');
    $ev = [];
    $sql = "SELECT o.id, o.numero, o.titulo, o.tipo, o.status, o.data_prevista, DATE(o.data_fim) df, a.codigo
            FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
            WHERE o.status <> 'cancelada'
              AND ((o.data_fim >= ? AND o.data_fim < ? + INTERVAL 1 DAY) OR (o.data_fim IS NULL AND o.data_prevista BETWEEN ? AND ?))" . ($resp ? ' AND o.responsavel_id = ' . (int)$resp : '');
    foreach (q($sql, [$ini, $fim, $ini, $fim]) as $o) {
        $concl = $o['status'] === 'concluida';
        $atrasada = !$concl && $o['data_prevista'] < date('Y-m-d');
        $ev[] = [
            'title' => ($concl ? '✔ ' : ($atrasada ? '⚠ ' : '')) . $o['codigo'] . ' · ' . $o['titulo'],
            'start' => $concl ? $o['df'] : $o['data_prevista'],
            'url' => url('ots/ver', ['id' => $o['id']]),
            'color' => $cores[$o['tipo']] ?? '#6c757d',
            'textColor' => in_array($o['tipo'], ['lubrificacao', 'preditiva']) ? '#000' : '#fff',
            'classNames' => array_values(array_filter([$concl ? 'opacity-50' : '', $atrasada ? 'ot-atrasada' : ''])),
        ];
    }
    // projeções (ainda sem OT gerada)
    if (get('proj', '1') === '1') {
        $pend = "EXISTS(SELECT 1 FROM ordens_trabalho o WHERE o.plano_id = p.id AND o.status IN (" . STATUS_PENDENTES . ")) AS tem_pendente";
        $planos = q("SELECT p.*, a.codigo, $pend FROM planos_manutencao p JOIN ativos a ON a.id=p.ativo_id WHERE p.ativo=1 AND p.gatilho='tempo' AND a.status<>'desativado'" . ($resp ? ' AND p.responsavel_id = ' . (int)$resp : ''));
        foreach ($planos as $p) {
            $datas = projetar_plano($p, $fim);
            if ($datas && $p['tem_pendente']) array_shift($datas); // a 1ª já virou OT
            foreach ($datas as $d) {
                if ($d < $ini) continue;
                $ev[] = ['title' => '◌ ' . $p['codigo'] . ' · ' . $p['titulo'], 'start' => $d, 'url' => url('planos/form', ['id' => $p['id']]),
                         'color' => 'transparent', 'textColor' => $cores[$p['tipo']] ?? '#198754', 'borderColor' => $cores[$p['tipo']] ?? '#198754'];
            }
        }
        foreach (q("SELECT p.*, a.codigo, $pend FROM planos_manutencao p JOIN ativos a ON a.id=p.ativo_id WHERE p.ativo=1 AND p.gatilho='contador' AND a.status<>'desativado'") as $p) {
            if ($p['tem_pendente']) continue;
            $d = estimar_data_contador($p);
            if ($d && $d >= $ini && $d <= $fim) $ev[] = ['title' => '◌ ' . $p['codigo'] . ' · ' . $p['titulo'] . ' (estim.)', 'start' => $d, 'url' => url('planos/form', ['id' => $p['id']]), 'color' => 'transparent', 'textColor' => '#0d6efd', 'borderColor' => '#0d6efd'];
        }
    }
    json_out($ev);
}

$tecs = opcoes('SELECT id, nome FROM tecnicos WHERE ativo=1 ORDER BY nome');
view_header('Calendário', 'calendario'); ?>
<div class="page-title"><h1>Calendário de manutenção</h1>
  <div class="d-flex gap-2 align-items-center">
    <div style="min-width:220px"><?= sel('resp', $tecs, get('resp'), true, 'class="form-select form-select-sm" aria-label="Técnico" data-cal-filtro', 'Todos os técnicos') ?></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="proj" name="proj" checked data-cal-filtro><label class="form-check-label small" for="proj">Mostrar projeção dos planos</label></div>
  </div>
</div>
<div class="small mb-2">
  <?php foreach ($cores as $t => $c): ?><span class="me-3"><i class="bi bi-square-fill" style="color:<?= $c ?>"></i> <?= e(rotulo('tipo_ot', $t)) ?></span><?php endforeach; ?>
  <span class="text-muted">◌ = previsto pelo plano (OT ainda não gerada) · ✔ = concluída · ⚠ = atrasada</span>
</div>
<div class="card"><div class="card-body"><div id="cal" data-eventos="<?= e(url('calendario/eventos')) ?>"></div></div></div>
<?php
$GLOBALS['__scripts'] = cdn_js('fullcalendar@6.1.15/index.global.min.js') . cdn_js('@fullcalendar/core@6.1.15/locales/pt-br.global.min.js');
view_footer();
