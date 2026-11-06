<?php
exigir('ver');

$cores = ['corretiva' => '#dc3545', 'preventiva' => '#198754', 'preditiva' => '#0dcaf0', 'inspecao' => '#0d6efd', 'lubrificacao' => '#ffc107', 'calibracao' => '#6c757d', 'melhoria' => '#212529'];

/* Eventos JSON para o FullCalendar: OTs existentes + projeção dos planos por tempo */
if ($acao === 'eventos') {
    $ini = substr((string)get('start', date('Y-m-01')), 0, 10);
    $fim = substr((string)get('end', date('Y-m-t')), 0, 10);
    $resp = get('resp');
    $ev = [];
    $sql = "SELECT o.id, o.numero, o.titulo, o.tipo, o.status, o.data_prevista, DATE(o.data_fim) df, a.codigo
            FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
            WHERE o.status <> 'cancelada' AND COALESCE(DATE(o.data_fim), o.data_prevista) BETWEEN ? AND ?" . ($resp ? ' AND o.responsavel_id = ' . (int)$resp : '');
    foreach (q($sql, [$ini, $fim]) as $o) {
        $concl = $o['status'] === 'concluida';
        $ev[] = [
            'title' => ($concl ? '✔ ' : '') . $o['codigo'] . ' · ' . $o['titulo'],
            'start' => $concl ? $o['df'] : $o['data_prevista'],
            'url' => url('ots/ver', ['id' => $o['id']]),
            'color' => $cores[$o['tipo']] ?? '#6c757d',
            'textColor' => in_array($o['tipo'], ['lubrificacao', 'preditiva']) ? '#000' : '#fff',
            'classNames' => [$concl ? 'opacity-50' : ''],
        ];
    }
    // projeções (ainda sem OT gerada)
    if (get('proj', '1') === '1') {
        $planos = q("SELECT p.*, a.codigo FROM planos_manutencao p JOIN ativos a ON a.id=p.ativo_id WHERE p.ativo=1 AND p.gatilho='tempo' AND a.status<>'desativado'" . ($resp ? ' AND p.responsavel_id = ' . (int)$resp : ''));
        foreach ($planos as $p) {
            $datas = projetar_plano($p, $fim);
            if ($datas && plano_tem_ot_pendente((int)$p['id'])) array_shift($datas); // a 1ª já virou OT
            foreach ($datas as $d) {
                if ($d < $ini) continue;
                $ev[] = ['title' => '◌ ' . $p['codigo'] . ' · ' . $p['titulo'], 'start' => $d, 'url' => url('planos/form', ['id' => $p['id']]),
                         'color' => 'transparent', 'textColor' => $cores[$p['tipo']] ?? '#198754', 'borderColor' => $cores[$p['tipo']] ?? '#198754'];
            }
        }
        foreach (q("SELECT p.*, a.codigo FROM planos_manutencao p JOIN ativos a ON a.id=p.ativo_id WHERE p.ativo=1 AND p.gatilho='contador'") as $p) {
            if (plano_tem_ot_pendente((int)$p['id'])) continue;
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
    <div style="min-width:220px"><?= sel('resp', $tecs, get('resp'), true, 'class="form-select form-select-sm" onchange="cal.refetchEvents()"', 'Todos os técnicos') ?></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="proj" checked onchange="cal.refetchEvents()"><label class="form-check-label small" for="proj">Mostrar projeção dos planos</label></div>
  </div>
</div>
<div class="small mb-2">
  <?php foreach ($cores as $t => $c): ?><span class="me-3"><i class="bi bi-square-fill" style="color:<?= $c ?>"></i> <?= e(rotulo('tipo_ot', $t)) ?></span><?php endforeach; ?>
  <span class="text-muted">◌ = previsto pelo plano (OT ainda não gerada) · ✔ = concluída</span>
</div>
<div class="card"><div class="card-body"><div id="cal"></div></div></div>
<?php
$GLOBALS['__scripts'] = '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.15/locales/pt-br.global.min.js"></script>
<script>var cal;document.addEventListener("DOMContentLoaded",function(){cal=new FullCalendar.Calendar(document.getElementById("cal"),{
 locale:"pt-br",initialView:"dayGridMonth",height:"auto",dayMaxEvents:4,
 headerToolbar:{left:"prev,next today",center:"title",right:"dayGridMonth,timeGridWeek,listMonth"},
 events:function(info,ok,err){const u=new URL(' . json_encode(url('calendario/eventos')) . ',location.href);
   u.searchParams.set("start",info.startStr);u.searchParams.set("end",info.endStr);
   u.searchParams.set("resp",document.getElementById("f_resp").value);u.searchParams.set("proj",document.getElementById("proj").checked?"1":"0");
   fetch(u).then(r=>r.json()).then(ok).catch(err);}});cal.render();});</script>';
view_footer();
