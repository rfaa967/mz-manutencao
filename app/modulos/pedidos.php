<?php
exigir('solicitar');
$u = usuario();

function pedido_visivel(array $p): bool { return pode('ver') || (int)$p['solicitante_id'] === usuario()['id']; }

/* ---------- Criar ---------- */
if ($acao === 'salvar' && is_post()) {
    limitar_cfg('pedidos_hora', 'u' . $u['id']);
    if ($erroTs = turnstile_validar('pedido')) { flash($erroTs, 'danger'); redirect_m('m/pedido', ['ativo' => post_int('ativo_id')]); redirect('pedidos/form', ['ativo' => post_int('ativo_id')]); }
    $titulo = mb_substr((string)post('titulo'), 0, 150);
    $ativoId = post_int('ativo_id');
    $locId = post_int('localizacao_id');
    $erro = null;
    if ($titulo === '') $erro = 'Descreva o problema no título.';
    elseif ($ativoId && !qv("SELECT 1 FROM ativos WHERE id = ? AND status <> 'desativado'", [$ativoId])) $erro = 'Equipamento inválido.';
    elseif ($locId && !qv('SELECT 1 FROM localizacoes WHERE id = ?', [$locId])) $erro = 'Localização inválida.';
    if ($erro) { flash($erro, 'danger'); redirect_m('m/pedido', ['ativo' => $ativoId]); redirect('pedidos/form', ['ativo' => $ativoId]); }
    $dados = [
        'numero' => proximo_numero('pedidos', 'PT'), 'ativo_id' => $ativoId, 'localizacao_id' => $locId,
        'solicitante_id' => $u['id'], 'titulo' => $titulo, 'descricao' => post('descricao') ?: null,
        'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : 'media',
        'ativo_parado' => post('ativo_parado') ? 1 : 0,
    ];
    if ($ativoId && !$dados['localizacao_id']) $dados['localizacao_id'] = qv('SELECT localizacao_id FROM ativos WHERE id = ?', [$ativoId]);
    $id = insert('pedidos', $dados);
    // pedidos da equipe já marcam o ativo como parado; os de solicitantes, só após a aprovação
    atualizar_status_ativo($ativoId);
    auditar('criar', 'pedidos', $id);
    notificar_novo_pedido($id);
    flash("Pedido {$dados['numero']} registrado. A equipe de manutenção foi avisada.");
    redirect_m('m/pedidos', ['id' => $id]);
    redirect('pedidos/ver', ['id' => $id]);
}

/* ---------- Avaliar (aprovar → OT / rejeitar) ---------- */
if ($acao === 'avaliar' && is_post()) {
    exigir('gerir');
    $pid = (int)post_int('id');
    $decisao = post('decisao') === 'rejeitar' ? 'rejeitar' : 'aprovar';
    $resposta = post('resposta') ?: null;
    $resp = post_int('responsavel_id');
    if ($resp && !qv('SELECT 1 FROM tecnicos WHERE id = ? AND ativo = 1', [$resp])) { flash('Responsável inválido.', 'danger'); redirect('pedidos/ver', ['id' => $pid]); }

    if ($decisao === 'rejeitar' && mb_strlen(trim((string)$resposta)) < 5) { flash('Para rejeitar, explique o motivo ao solicitante no campo de comentário.', 'danger'); redirect('pedidos/ver', ['id' => $pid]); }
    $pdo = db(); $pdo->beginTransaction();
    try {
        // trava o pedido: dois gestores (ou duplo clique) não geram duas OTs
        $p = q1('SELECT * FROM pedidos WHERE id = ? FOR UPDATE', [$pid]);
        if (!$p || !in_array($p['status'], ['aberto', 'aprovado'], true)) {
            $pdo->rollBack();
            flash('Este pedido já foi avaliado.', 'warning');
            redirect('pedidos/ver', ['id' => $pid]);
        }
        $otId = null;
        if ($decisao === 'rejeitar') {
            update('pedidos', ['status' => 'rejeitado', 'resposta' => $resposta, 'avaliado_por' => $u['id'], 'avaliado_em' => date('Y-m-d H:i:s')], $pid);
        } else {
            $otId = insert('ordens_trabalho', [
                'numero' => proximo_numero('ordens_trabalho', 'OT'), 'ativo_id' => $p['ativo_id'], 'pedido_id' => $pid,
                'tipo' => array_key_exists((string)post('tipo'), ROTULOS['tipo_ot']) ? post('tipo') : 'corretiva',
                'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : $p['prioridade'],
                'status' => $resp ? 'planejada' : 'aberta',
                'titulo' => $p['titulo'], 'descricao' => trim(($p['descricao'] ?? '') . ($resposta ? "\n\n[Gestor] $resposta" : '')),
                'responsavel_id' => $resp, 'data_prevista' => post_date('data_prevista') ?? date('Y-m-d'),
                'ativo_parado' => $p['ativo_parado'], 'criado_por' => $u['id'],
            ]);
            update('pedidos', ['status' => 'convertido', 'ot_id' => $otId, 'resposta' => $resposta, 'avaliado_por' => $u['id'], 'avaliado_em' => date('Y-m-d H:i:s')], $pid);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }
    atualizar_status_ativo($p['ativo_id'] ? (int)$p['ativo_id'] : null);
    auditar($otId ? 'converter' : 'rejeitar', 'pedidos', $pid, $otId ? "OT #$otId" : '');
    notificar_pedido_avaliado($pid);
    if ($otId) {
        notificar_ot_atribuida($otId);
        flash('Pedido aprovado e convertido em ordem de trabalho.');
        redirect('ots/ver', ['id' => $otId]);
    }
    flash('Pedido rejeitado.', 'info');
    redirect('pedidos/ver', ['id' => $pid]);
}

/* ---------- Formulário ---------- */
if ($acao === 'form') {
    $ativoPre = (int)get('ativo', 0);
    view_header('Novo pedido de trabalho', 'pedidos'); ?>
    <div class="page-title"><h1>Novo pedido de trabalho</h1><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('pedidos')) ?>">Voltar</a></div>
    <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('pedidos/salvar')) ?>"><?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Equipamento / ativo</label><?= sel('ativo_id', arvore_opcoes('ativos'), $ativoPre ?: null) ?></div>
        <div class="col-md-6"><label class="form-label">Ou localização (se não souber o ativo)</label><?= sel('localizacao_id', arvore_opcoes('localizacoes'), null) ?></div>
        <div class="col-12"><label class="form-label">O que está acontecendo? *</label><input class="form-control" name="titulo" required maxlength="150" value="<?= e(old('titulo', '')) ?>" placeholder="Ex.: Compressor fazendo ruído e desarmando"></div>
        <div class="col-12"><label class="form-label">Detalhes</label><textarea class="form-control" name="descricao" rows="4" placeholder="Quando começou, sintomas, o que já foi tentado…"><?= e(old('descricao', '')) ?></textarea></div>
        <div class="col-md-4"><label class="form-label">Urgência</label><?= sel('prioridade', lista_rotulos('prioridade'), 'media', false) ?></div>
        <div class="col-md-8 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="ativo_parado" value="1" id="parado">
          <label class="form-check-label" for="parado">O equipamento está <strong>parado</strong> / sem condições de uso</label></div></div>
      </div>
      <div class="mt-3"><?= turnstile_widget('pedido', true) ?></div>
      <button class="btn btn-danger mt-3"><i class="bi bi-send"></i> Enviar pedido</button>
    </form></div></div>
    <?php view_footer(); exit;
}

/* ---------- Detalhe ---------- */
if ($acao === 'ver') {
    $p = q1('SELECT p.*, a.codigo, a.nome AS ativo, a.localizacao_id AS ativo_localizacao_id, l.nome AS local, us.nome AS solicitante, av.nome AS avaliador, o.numero AS ot_numero, o.status AS ot_status
             FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id LEFT JOIN localizacoes l ON l.id=p.localizacao_id
             JOIN usuarios us ON us.id=p.solicitante_id LEFT JOIN usuarios av ON av.id=p.avaliado_por LEFT JOIN ordens_trabalho o ON o.id=p.ot_id
             WHERE p.id = ?', [(int)get('id')]);
    if (!$p || !pedido_visivel($p)) redirect('pedidos');
    view_header($p['numero'], 'pedidos'); ?>
    <div class="page-title"><h1><?= e($p['numero']) ?> <?= badge('ped_status', $p['status']) ?></h1><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('pedidos')) ?>">Voltar</a></div>
    <div class="row g-3">
      <div class="col-lg-7"><div class="card"><div class="card-body">
        <h2 class="h5"><?= e($p['titulo']) ?></h2>
        <p style="white-space:pre-line"><?= e($p['descricao']) ?></p>
        <table class="table table-sm mb-0">
          <tr><th class="fw-normal text-muted">Ativo</th><td><?= $p['ativo_id'] ? (pode('ver') ? '<a href="' . e(url('ativos/ver', ['id' => $p['ativo_id']])) . '">' . e($p['codigo'] . ' · ' . $p['ativo']) . '</a>' : e($p['codigo'] . ' · ' . $p['ativo'])) : '—' ?></td></tr>
          <tr><th class="fw-normal text-muted">Localização</th><td><?= e(caminho_localizacao(($p['localizacao_id'] ?: $p['ativo_localizacao_id']) ? (int)($p['localizacao_id'] ?: $p['ativo_localizacao_id']) : null)) ?: '—' ?></td></tr>
          <tr><th class="fw-normal text-muted">Urgência</th><td><?= badge('prioridade', $p['prioridade']) ?> <?= $p['ativo_parado'] ? '<span class="badge text-bg-danger">equipamento parado</span>' : '' ?></td></tr>
          <tr><th class="fw-normal text-muted">Solicitante</th><td><?= e($p['solicitante']) ?> em <?= dt($p['criado_em'], true) ?></td></tr>
          <?php if ($p['avaliado_em']): ?><tr><th class="fw-normal text-muted">Avaliado por</th><td><?= e($p['avaliador']) ?> em <?= dt($p['avaliado_em'], true) ?></td></tr><?php endif; ?>
          <?php if ($p['resposta']): ?><tr><th class="fw-normal text-muted">Resposta</th><td style="white-space:pre-line"><?= e($p['resposta']) ?></td></tr><?php endif; ?>
          <?php if ($p['ot_id']): ?><tr><th class="fw-normal text-muted">Ordem de trabalho</th><td>
            <?= pode('ver') ? '<a href="' . e(url('ots/ver', ['id' => $p['ot_id']])) . '">' . e($p['ot_numero']) . '</a>' : e($p['ot_numero']) ?> <?= badge('ot_status', $p['ot_status']) ?></td></tr><?php endif; ?>
        </table>
      </div></div></div>
      <?php if (pode('gerir') && in_array($p['status'], ['aberto', 'aprovado'], true)): ?>
      <div class="col-lg-5"><div class="card border-warning"><div class="card-body">
        <h2 class="h6">Avaliar pedido</h2>
        <form method="post" action="<?= e(url('pedidos/avaliar')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <div class="row g-2">
            <div class="col-6"><label class="form-label">Tipo da OT</label><?= sel('tipo', lista_rotulos('tipo_ot'), 'corretiva', false) ?></div>
            <div class="col-6"><label class="form-label">Prioridade</label><?= sel('prioridade', lista_rotulos('prioridade'), $p['prioridade'], false) ?></div>
            <div class="col-6"><label class="form-label">Responsável</label><?= sel('responsavel_id', opcoes('SELECT id, nome FROM tecnicos WHERE ativo=1 ORDER BY nome')) ?></div>
            <div class="col-6"><label class="form-label">Data prevista</label><input type="date" class="form-control" name="data_prevista" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><label class="form-label">Comentário / resposta ao solicitante <span class="text-muted fw-normal">(obrigatório para rejeitar)</span></label><textarea class="form-control" name="resposta" rows="2"><?= e((string)old('resposta', '')) ?></textarea></div>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-success" name="decisao" value="aprovar"><i class="bi bi-check2-circle"></i> Aprovar e gerar OT</button>
            <button class="btn btn-outline-danger" name="decisao" value="rejeitar" data-confirm="Rejeitar este pedido?">Rejeitar</button>
          </div>
        </form>
      </div></div></div>
      <?php endif; ?>
    </div>
    <?php view_footer(); exit;
}

/* ---------- Lista (tabela server-side) ---------- */
$st = in_array(get('status'), ['aberto', 'convertido', 'rejeitado', 'todos'], true) ? get('status') : (pode('gerir') ? 'aberto' : 'todos');
$t = new Tabela('pedidos',
    'pedidos p LEFT JOIN ativos a ON a.id = p.ativo_id JOIN usuarios us ON us.id = p.solicitante_id',
    'p.id, p.numero, p.titulo, p.prioridade, p.status, p.ativo_parado, p.criado_em, a.codigo, a.nome AS ativo, us.nome AS solicitante', [
    'numero' => ['rotulo' => 'Nº', 'ordem' => 'p.id', 'busca' => 'p.numero', 'classe' => 'text-nowrap', 'render' => fn($l) => e($l['numero'])],
    'titulo' => ['rotulo' => 'Título', 'ordem' => 'p.titulo', 'busca' => 'p.titulo',
                 'render' => fn($l) => e($l['titulo']) . ($l['ativo_parado'] ? ' <i class="bi bi-sign-stop-fill text-danger" title="Equipamento parado" aria-label="Equipamento parado"></i>' : ''), 'csv' => fn($l) => $l['titulo']],
    'ativo' => ['rotulo' => 'Ativo', 'ordem' => 'a.codigo', 'busca' => "CONCAT_WS(' ', a.codigo, a.nome)", 'classe' => 'd-none d-md-table-cell', 'render' => fn($l) => e($l['codigo'])],
    'solicitante' => ['rotulo' => 'Solicitante', 'ordem' => 'us.nome', 'busca' => 'us.nome', 'classe' => 'd-none d-lg-table-cell', 'render' => fn($l) => e($l['solicitante'])],
    'data' => ['rotulo' => 'Data', 'ordem' => 'p.criado_em', 'classe' => 'text-nowrap', 'render' => fn($l) => dt($l['criado_em'], true)],
    'urgencia' => ['rotulo' => 'Urgência', 'ordem' => "FIELD(p.prioridade, 'urgente', 'alta', 'media', 'baixa')", 'classe' => 'd-none d-md-table-cell',
                   'render' => fn($l) => badge('prioridade', $l['prioridade']), 'csv' => fn($l) => rotulo('prioridade', $l['prioridade'])],
    'status' => ['rotulo' => 'Status', 'ordem' => "FIELD(p.status, 'aberto', 'aprovado', 'convertido', 'rejeitado')", 'render' => fn($l) => badge('ped_status', $l['status']), 'csv' => fn($l) => rotulo('ped_status', $l['status'])],
]);
$t->desempate = 'p.id';
// padrão: urgência primeiro nos abertos; nos demais, mais recentes primeiro
if ($st === 'aberto') { $t->ordemPadrao = 'urgencia'; $t->dirPadrao = 'asc'; } else { $t->ordemPadrao = 'data'; $t->dirPadrao = 'desc'; }
$t->placeholder = 'Nº, título, ativo, solicitante…';
$t->href = fn($l) => url('pedidos/ver', ['id' => $l['id']]);
$t->vazio = 'Nenhum pedido.';
if (!pode('ver')) { $t->where[] = 'p.solicitante_id = ?'; $t->params[] = $u['id']; unset($t->colunas['solicitante']); }
if ($st !== 'todos') { $t->where[] = 'p.status = ?'; $t->params[] = $st; }
$t->responder('pedidos');

view_header('Pedidos de trabalho', 'pedidos'); ?>
<div class="page-title"><h1>Pedidos de trabalho</h1><a class="btn btn-danger btn-sm" href="<?= e(url('pedidos/form')) ?>"><i class="bi bi-plus-lg"></i> Novo pedido</a></div>
<div class="btn-group btn-group-sm mb-3 flex-wrap" role="group" aria-label="Situação">
  <?php foreach (['aberto' => 'Abertos', 'convertido' => 'Convertidos', 'rejeitado' => 'Rejeitados', 'todos' => 'Todos'] as $k => $r): ?>
    <a class="btn btn-outline-secondary <?= $st === $k ? 'active' : '' ?>" href="<?= e(url('pedidos', ['status' => $k])) ?>"><?= $r ?></a>
  <?php endforeach; ?>
</div>
<?= $t->html() ?>
<?php view_footer();
