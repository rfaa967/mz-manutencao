<?php
exigir('ver');
$tiposPlano = array_intersect_key(lista_rotulos('tipo_ot'), array_flip(['preventiva', 'preditiva', 'inspecao', 'lubrificacao', 'calibracao']));
$unidades = ['dia' => 'Dia(s)', 'semana' => 'Semana(s)', 'mes' => 'Mês(es)', 'ano' => 'Ano(s)'];
$unidadesTxt = ['dia' => ['dia', 'dias'], 'semana' => ['semana', 'semanas'], 'mes' => ['mês', 'meses'], 'ano' => ['ano', 'anos']];

/* ---------- Gerar OTs devidas ---------- */
if ($acao === 'gerar' && is_post()) {
    exigir('gerir');
    $g = gerar_preventivas(usuario()['id']);
    if ($g) {
        $nums = array_column(q('SELECT numero FROM ordens_trabalho WHERE id IN (' . implode(',', array_map(fn($x) => (int)$x['ot_id'], $g)) . ')'), 'numero');
        flash(plural(count($g), 'OT preventiva gerada', 'OTs preventivas geradas') . ': ' . implode(', ', $nums) . '.');
    } else flash('Nenhum plano vencendo no momento.', 'info');
    redirect('planos');
}
if ($acao === 'gerar_um' && is_post()) {
    exigir('gerir');
    $p = q1('SELECT * FROM planos_manutencao WHERE id = ?', [post_int('id')]);
    $ot = $p ? gerar_ot_de_plano($p, $p['gatilho'] === 'tempo' ? ($p['proxima_data'] ?: date('Y-m-d')) : date('Y-m-d'), usuario()['id']) : null;
    if ($ot) {
        flash('OT gerada a partir do plano.');
        redirect('ots/ver', ['id' => $ot]);
    }
    flash('O plano já possui uma OT pendente.', 'warning');
    redirect('planos/form', ['id' => post_int('id')]);
}

/* ---------- Salvar ---------- */
if ($acao === 'salvar' && is_post()) {
    exigir('gerir');
    $id = post_int('id');
    $gat = post('gatilho') === 'contador' ? 'contador' : 'tempo';
    $dados = [
        'ativo_id' => post_int('ativo_id'), 'titulo' => (string)post('titulo'),
        'tipo' => array_key_exists((string)post('tipo'), $tiposPlano) ? post('tipo') : 'preventiva',
        'descricao' => post('descricao') ?: null, 'gatilho' => $gat,
        'base_calculo' => post('base_calculo') === 'conclusao' ? 'conclusao' : 'fixa',
        'antecedencia_dias' => max(0, (int)post('antecedencia_dias')),
        'dia_ancora' => null,
        'responsavel_id' => post_int('responsavel_id'),
        'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : 'media',
        'duracao_estimada' => post_dec_pos('duracao_estimada'), 'ativo' => post('ativo') ? 1 : 0,
        'periodicidade' => null, 'unidade_periodo' => null, 'proxima_data' => null, 'contador_id' => null, 'intervalo_contador' => null, 'proximo_valor' => null,
    ];
    if ($gat === 'tempo') {
        $dados['periodicidade'] = max(1, (int)post('periodicidade'));
        $dados['unidade_periodo'] = array_key_exists((string)post('unidade_periodo'), $unidades) ? post('unidade_periodo') : 'mes';
        $dados['proxima_data'] = post_date('proxima_data') ?? date('Y-m-d');
        $ant = $id ? q1('SELECT proxima_data, dia_ancora FROM planos_manutencao WHERE id = ?', [$id]) : null;
        $dados['dia_ancora'] = ($ant && $ant['dia_ancora'] && $ant['proxima_data'] === $dados['proxima_data'])
            ? (int)$ant['dia_ancora']                              // data não mudou: mantém o dia original (31 → 28 → 31)
            : (int)substr($dados['proxima_data'], 8, 2);
        if ($dados['antecedencia_dias'] >= periodo_em_dias($dados['periodicidade'], $dados['unidade_periodo'])) {
            flash('A antecedência precisa ser menor que a periodicidade; do contrário, cada conclusão geraria a OT seguinte imediatamente.', 'danger');
            redirect('planos/form', $id ? ['id' => $id] : ['ativo' => $dados['ativo_id']]);
        }
    } else {
        $dados['contador_id'] = post_int('contador_id');
        $dados['intervalo_contador'] = post_dec_pos('intervalo_contador');
        $atual = (float)qv('SELECT valor_atual FROM contadores WHERE id = ? AND ativo_id = ?', [$dados['contador_id'], $dados['ativo_id']]);
        $dados['proximo_valor'] = post_dec_pos('proximo_valor') ?? ($atual + (float)$dados['intervalo_contador']);
        if ($dados['antecedencia_dias'] >= (float)$dados['intervalo_contador']) $dados['antecedencia_dias'] = (int)floor((float)$dados['intervalo_contador'] / 2);
        if (!$dados['contador_id'] || !$dados['intervalo_contador']) { flash('Selecione o contador do ativo e o intervalo.', 'danger'); redirect('planos/form', $id ? ['id' => $id] : ['ativo' => $dados['ativo_id']]); }
    }
    if (!$dados['ativo_id'] || $dados['titulo'] === '') { flash('Ativo e título são obrigatórios.', 'danger'); redirect('planos/form', $id ? ['id' => $id] : []); }

    db()->beginTransaction();
    if ($id) update('planos_manutencao', $dados, $id); else $id = insert('planos_manutencao', $dados);
    qx('DELETE FROM plano_tarefas WHERE plano_id = ?', [$id]);
    foreach ((array)post('tarefa', []) as $i => $t) {
        if (trim((string)$t) !== '') insert('plano_tarefas', ['plano_id' => $id, 'ordem' => $i + 1, 'descricao' => trim((string)$t)]);
    }
    qx('DELETE FROM plano_materiais WHERE plano_id = ?', [$id]);
    $arts = (array)post('mat_artigo', []); $qtds = (array)post('mat_qtd', []);
    foreach ($arts as $i => $aid) {
        $qv = (float)(dec_br($qtds[$i] ?? null) ?? 0);
        if ((int)$aid && $qv > 0) insert('plano_materiais', ['plano_id' => $id, 'artigo_id' => (int)$aid, 'quantidade' => $qv]);
    }
    db()->commit();
    auditar('salvar', 'planos_manutencao', $id);
    flash('Plano salvo.');
    redirect('planos/form', ['id' => $id]);
}

if ($acao === 'excluir' && is_post()) {
    exigir('gerir');
    $pid = (int)post_int('id');
    if (qv('SELECT COUNT(*) FROM ordens_trabalho WHERE plano_id = ?', [$pid])) {
        // preserva a rastreabilidade das OTs geradas: apenas inativa
        qx('UPDATE planos_manutencao SET ativo = 0 WHERE id = ?', [$pid]);
        auditar('inativar', 'planos_manutencao', $pid);
        flash('O plano já gerou OTs, então foi inativado (não excluído) para manter o histórico.', 'warning');
        redirect('planos/form', ['id' => $pid]);
    }
    qx('DELETE FROM planos_manutencao WHERE id = ?', [$pid]);
    auditar('excluir', 'planos_manutencao', $pid);
    flash('Plano excluído.');
    redirect('planos');
}

/* ---------- Contadores do ativo (AJAX) ---------- */
if ($acao === 'contadores') {
    json_out(q('SELECT id, nome, unidade, valor_atual FROM contadores WHERE ativo_id = ?', [(int)get('ativo')]));
}

/* ---------- Formulário ---------- */
if ($acao === 'form') {
    $id = (int)get('id', 0);
    $p = $id ? q1('SELECT * FROM planos_manutencao WHERE id = ?', [$id]) : [
        'ativo_id' => get('ativo'), 'tipo' => 'preventiva', 'gatilho' => 'tempo', 'periodicidade' => 1, 'unidade_periodo' => 'mes',
        'base_calculo' => 'fixa', 'antecedencia_dias' => 7, 'prioridade' => 'media', 'ativo' => 1, 'proxima_data' => date('Y-m-d'),
    ];
    $p = com_old($p, ['intervalo_contador', 'proximo_valor', 'duracao_estimada'], ['ativo']);
    if ($id && !$p) redirect('planos');
    $tarefas = $id ? q('SELECT * FROM plano_tarefas WHERE plano_id = ? ORDER BY ordem', [$id]) : [];
    $materiais = $id ? q('SELECT * FROM plano_materiais WHERE plano_id = ?', [$id]) : [];
    $artigos = opcoes("SELECT id, CONCAT(codigo,' · ',descricao) FROM artigos WHERE ativo=1 ORDER BY codigo");
    $contadores = $p['ativo_id'] ? q('SELECT id, nome, unidade, valor_atual FROM contadores WHERE ativo_id = ?', [$p['ativo_id']]) : [];
    $historico = $id ? q('SELECT id, numero, status, data_prevista, data_fim FROM ordens_trabalho WHERE plano_id = ? ORDER BY id DESC LIMIT 15', [$id]) : [];
    $ro = pode('gerir') ? '' : 'disabled';
    view_header($id ? 'Plano de manutenção' : 'Novo plano', 'planos'); ?>
    <div class="page-title"><h1><?= $id ? e($p['titulo']) : 'Novo plano de manutenção' ?></h1>
      <div class="d-flex gap-2">
        <?php if ($id && pode('gerir')): ?><form method="post" action="<?= e(url('planos/gerar_um')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-outline-success btn-sm"><i class="bi bi-lightning"></i> Gerar OT agora</button></form><?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('planos')) ?>">Voltar</a></div></div>
    <form method="post" action="<?= e(url('planos/salvar')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?: '' ?>">
    <fieldset <?= $ro ?>>
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body"><div class="row g-3">
          <div class="col-md-7"><label class="form-label">Ativo *</label><?= sel('ativo_id', arvore_opcoes('ativos'), $p['ativo_id'], true, 'required data-contadores-url="' . e(url('planos/contadores')) . '" data-contadores-alvo="f_contador_id"') ?></div>
          <div class="col-md-5"><label class="form-label">Tipo</label><?= sel('tipo', $tiposPlano, $p['tipo'], false) ?></div>
          <div class="col-12"><label class="form-label">Título *</label><input class="form-control" name="titulo" required value="<?= e($p['titulo'] ?? '') ?>" placeholder="Ex.: Preventiva mensal — troca de filtros"></div>
          <div class="col-12"><label class="form-label">Instruções</label><textarea class="form-control" name="descricao" rows="3"><?= e($p['descricao'] ?? '') ?></textarea></div>
        </div></div></div>

        <div class="card mb-3"><div class="card-header bg-white fw-semibold">Periodicidade</div><div class="card-body"><div class="row g-3">
          <div class="col-12">
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="gatilho" value="tempo" id="g1" <?= $p['gatilho'] === 'tempo' ? 'checked' : '' ?>><label class="form-check-label" for="g1">Por tempo (calendário)</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="gatilho" value="contador" id="g2" <?= $p['gatilho'] === 'contador' ? 'checked' : '' ?>><label class="form-check-label" for="g2">Por contador (horas, km, ciclos…)</label></div>
          </div>
          <div class="col-md-2" data-show-when="gatilho=tempo"><label class="form-label">A cada</label><input type="number" min="1" class="form-control" name="periodicidade" value="<?= (int)$p['periodicidade'] ?: 1 ?>"></div>
          <div class="col-md-3" data-show-when="gatilho=tempo"><label class="form-label">&nbsp;</label><?= sel('unidade_periodo', $unidades, $p['unidade_periodo'] ?: 'mes', false) ?></div>
          <div class="col-md-3" data-show-when="gatilho=tempo"><label class="form-label">Próxima execução</label><input type="date" class="form-control" name="proxima_data" value="<?= e($p['proxima_data'] ?? '') ?>"></div>
          <div class="col-md-4" data-show-when="gatilho=contador"><label class="form-label">Contador</label>
            <select class="form-select" name="contador_id" id="f_contador_id"><option value="">— selecione —</option>
              <?php foreach ($contadores as $c): ?><option value="<?= $c['id'] ?>" <?= (int)($p['contador_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?> (atual <?= num($c['valor_atual'], 1) ?> <?= e($c['unidade']) ?>)</option><?php endforeach; ?>
            </select><div class="form-text"><a href="<?= e(url('contadores', ['ativo' => $p['ativo_id']])) ?>">cadastrar contador</a></div></div>
          <div class="col-md-3" data-show-when="gatilho=contador"><label class="form-label">Intervalo</label><input class="form-control" inputmode="decimal" name="intervalo_contador" value="<?= isset($p['intervalo_contador']) ? num($p['intervalo_contador'], 2) : '' ?>" placeholder="ex.: 500"></div>
          <div class="col-md-3" data-show-when="gatilho=contador"><label class="form-label">Próximo valor</label><input class="form-control" inputmode="decimal" name="proximo_valor" value="<?= isset($p['proximo_valor']) ? num($p['proximo_valor'], 2) : '' ?>" placeholder="auto"></div>
          <div class="col-md-4"><label class="form-label">Reprogramar a partir de</label><?= sel('base_calculo', ['fixa' => 'Data/valor programado (fixa)', 'conclusao' => 'Data/valor da execução (flutuante)'], $p['base_calculo'], false) ?></div>
          <div class="col-md-4"><label class="form-label">Antecedência para gerar OT</label><input type="number" min="0" class="form-control" name="antecedencia_dias" value="<?= (int)$p['antecedencia_dias'] ?>">
            <div class="form-text">Dias (tempo) ou unidades do contador.</div></div>
          <?php if ($id): ?><div class="col-md-4 small"><div class="text-muted">Última execução</div><?= dt($p['ultima_execucao']) ?: '—' ?><?= $p['ultimo_valor'] !== null ? ' · ' . num($p['ultimo_valor'], 0) : '' ?></div><?php endif; ?>
        </div></div></div>

        <div class="card mb-3"><div class="card-header bg-white fw-semibold">Checklist de tarefas</div><div class="card-body">
          <div id="lista-tarefas">
            <?php foreach ($tarefas ?: [['descricao' => '']] as $t): ?>
              <div class="input-group input-group-sm mb-1 linha-dinamica"><span class="input-group-text"><i class="bi bi-grip-vertical"></i></span><input class="form-control" name="tarefa[]" aria-label="Tarefa" value="<?= e($t['descricao']) ?>"><button type="button" class="btn btn-outline-danger" data-remove-row><i class="bi bi-x"></i></button></div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-link btn-sm" data-add-row="tpl-tarefa" data-target="lista-tarefas">+ tarefa</button>
          <template id="tpl-tarefa"><div class="input-group input-group-sm mb-1 linha-dinamica"><span class="input-group-text"><i class="bi bi-grip-vertical"></i></span><input class="form-control" name="tarefa[]" aria-label="Tarefa"><button type="button" class="btn btn-outline-danger" data-remove-row><i class="bi bi-x"></i></button></div></template>
        </div></div>

        <div class="card mb-3"><div class="card-header bg-white fw-semibold">Materiais previstos</div><div class="card-body">
          <div id="lista-mat">
            <?php foreach ($materiais as $m): ?>
              <div class="row g-1 mb-1 linha-dinamica"><div class="col-8"><?= sel('mat_artigo[]', $artigos, $m['artigo_id'], true, 'class="form-select form-select-sm" aria-label="Material"') ?></div>
                <div class="col-3"><input class="form-control form-control-sm" name="mat_qtd[]" aria-label="Quantidade" value="<?= num($m['quantidade'], 2) ?>"></div>
                <div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-row><i class="bi bi-x"></i></button></div></div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn btn-link btn-sm" data-add-row="tpl-mat" data-target="lista-mat">+ material</button>
          <template id="tpl-mat"><div class="row g-1 mb-1 linha-dinamica"><div class="col-8"><?= sel('mat_artigo[]', $artigos, null, true, 'class="form-select form-select-sm" aria-label="Material"') ?></div>
            <div class="col-3"><input class="form-control form-control-sm" name="mat_qtd[]" placeholder="Qtd"></div>
            <div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger w-100" data-remove-row><i class="bi bi-x"></i></button></div></div></template>
        </div></div>
      </div>
      <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
          <div class="mb-2"><label class="form-label">Responsável padrão</label><?= sel('responsavel_id', opcoes('SELECT id, nome FROM tecnicos WHERE ativo=1 ORDER BY nome'), $p['responsavel_id'] ?? null) ?></div>
          <div class="mb-2"><label class="form-label">Prioridade</label><?= sel('prioridade', lista_rotulos('prioridade'), $p['prioridade'], false) ?></div>
          <div class="mb-2"><label class="form-label">Duração estimada (h)</label><input class="form-control" inputmode="decimal" name="duracao_estimada" value="<?= isset($p['duracao_estimada']) ? num($p['duracao_estimada'], 1) : '' ?>"></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="ativo" value="1" id="pa" <?= $p['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="pa">Plano ativo</label></div>
        </div></div>
        <?php if ($historico): ?>
        <div class="card mb-3"><div class="card-header bg-white fw-semibold">OTs geradas</div><ul class="list-group list-group-flush">
          <?php foreach ($historico as $h): ?><li class="list-group-item d-flex justify-content-between small"><a href="<?= e(url('ots/ver', ['id' => $h['id']])) ?>"><?= e($h['numero']) ?></a><span><?= dt($h['data_fim'] ?: $h['data_prevista']) ?></span><?= badge('ot_status', $h['status']) ?></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
      </div>
    </div>
    <?php if (pode('gerir')): ?><button class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar plano</button><?php endif; ?>
    </fieldset>
    </form>
    <?php if ($id && pode('gerir')): ?>
      <form method="post" action="<?= e(url('planos/excluir')) ?>" class="text-end" data-confirm="Excluir este plano?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-link text-danger btn-sm">Excluir plano</button></form>
    <?php endif;
    view_footer(); exit;
}

/* ---------- Lista (tabela server-side) ---------- */
$hoje = date('Y-m-d');
$periodo = function (array $p) use ($unidadesTxt): string {
    if ($p['gatilho'] !== 'tempo') return 'A cada ' . num($p['intervalo_contador'], 0) . ' ' . $p['unidade'];
    $n = (int)$p['periodicidade']; $ut = $unidadesTxt[$p['unidade_periodo']] ?? ['', ''];
    return $n === 1 ? ($p['unidade_periodo'] === 'semana' ? 'Toda ' : 'Todo ') . $ut[0] : "A cada $n {$ut[1]}";
};
$situacao = function (array $p) use ($hoje): array {
    if (!$p['ativo']) return ['inativo', 'secondary'];
    if ($p['pendente']) return ['OT gerada', 'info'];
    if ($p['gatilho'] === 'tempo') {
        $venc = $p['proxima_data'] && $p['proxima_data'] < $hoje;
        $gerar = $p['proxima_data'] && $p['proxima_data'] <= date('Y-m-d', strtotime('+' . (int)$p['antecedencia_dias'] . ' days'));
    } else {
        $venc = (float)$p['valor_atual'] >= (float)$p['proximo_valor'];
        $gerar = (float)$p['valor_atual'] >= (float)$p['proximo_valor'] - (float)$p['antecedencia_dias'];
    }
    return $venc ? ['vencido', 'danger'] : ($gerar ? ['a gerar', 'warning'] : ['em dia', 'success']);
};
$t = new Tabela('planos',
    'planos_manutencao p JOIN ativos a ON a.id = p.ativo_id LEFT JOIN contadores c ON c.id = p.contador_id LEFT JOIN tecnicos t ON t.id = p.responsavel_id',
    'p.*, a.codigo, a.nome AS ativo_nome, c.valor_atual, c.unidade, t.nome AS responsavel,
     EXISTS(SELECT 1 FROM ordens_trabalho o WHERE o.plano_id = p.id AND o.status IN (' . STATUS_PENDENTES . ')) AS pendente', [
    'ativo' => ['rotulo' => 'Ativo', 'ordem' => 'a.codigo', 'busca' => "CONCAT_WS(' ', a.codigo, a.nome)", 'classe' => 'text-nowrap', 'render' => fn($p) => '<span title="' . e($p['ativo_nome']) . '">' . e($p['codigo']) . '</span>'],
    'titulo' => ['rotulo' => 'Plano', 'ordem' => 'p.titulo', 'busca' => 'p.titulo', 'render' => fn($p) => e($p['titulo'])],
    'tipo' => ['rotulo' => 'Tipo', 'ordem' => 'p.tipo', 'classe' => 'd-none d-md-table-cell', 'render' => fn($p) => badge('tipo_ot', $p['tipo']), 'csv' => fn($p) => rotulo('tipo_ot', $p['tipo'])],
    'periodo' => ['rotulo' => 'Periodicidade', 'classe' => 'd-none d-lg-table-cell', 'render' => fn($p) => e($periodo($p)), 'csv' => $periodo],
    'ultima' => ['rotulo' => 'Última', 'ordem' => 'p.ultima_execucao', 'classe' => 'd-none d-md-table-cell', 'render' => fn($p) => dt($p['ultima_execucao'])],
    'proxima' => ['rotulo' => 'Próxima', 'ordem' => "COALESCE(p.proxima_data, '9999-12-31')", 'classe' => 'text-nowrap',
                  'render' => fn($p) => $p['gatilho'] === 'tempo' ? dt($p['proxima_data'])
                      : num($p['proximo_valor'], 0) . ' ' . e($p['unidade']) . ' <small class="text-muted">(atual ' . num($p['valor_atual'], 0) . ')</small>',
                  'csv' => fn($p) => $p['gatilho'] === 'tempo' ? dt($p['proxima_data']) : num($p['proximo_valor'], 0) . ' ' . $p['unidade']],
    'resp' => ['rotulo' => 'Responsável', 'ordem' => 't.nome', 'busca' => 't.nome', 'classe' => 'd-none d-lg-table-cell', 'render' => fn($p) => e($p['responsavel'])],
    'situacao' => ['rotulo' => 'Situação', 'render' => function ($p) use ($situacao) { [$r, $c] = $situacao($p); return '<span class="badge text-bg-' . $c . '">' . e($r) . '</span>'; },
                   'csv' => fn($p) => $situacao($p)[0]],
]);
$t->ordemPadrao = 'proxima'; $t->desempate = 'p.id';
$t->placeholder = 'Plano, ativo, responsável…';
$t->href = fn($p) => url('planos/form', ['id' => $p['id']]);
$t->vazio = 'Nenhum plano cadastrado.';
if (!get('inativos')) $t->where[] = 'p.ativo = 1';
$t->responder('planos');

view_header('Planos preventivos', 'planos'); ?>
<div class="page-title"><h1>Planos de manutenção preventiva</h1>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-secondary btn-sm <?= get('inativos') ? 'active' : '' ?>" href="<?= e(url('planos', get('inativos') ? [] : ['inativos' => 1])) ?>"><?= get('inativos') ? 'Ocultar inativos' : 'Mostrar inativos' ?></a>
    <?php if (pode('gerir')): ?>
      <form method="post" action="<?= e(url('planos/gerar')) ?>"><?= csrf_field() ?><button class="btn btn-success btn-sm"><i class="bi bi-lightning-charge"></i> Gerar OTs vencidas</button></form>
      <a class="btn btn-warning btn-sm" href="<?= e(url('planos/form')) ?>"><i class="bi bi-plus-lg"></i> Novo plano</a>
    <?php endif; ?>
  </div>
</div>
<?= $t->html() ?>
<p class="small text-muted mt-2">Configure o cron diário (<code>php cron/gerar_preventivas.php</code>) para gerar as OTs automaticamente; o botão acima faz o mesmo sob demanda.</p>
<?php view_footer();
