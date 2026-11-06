<?php
exigir('solicitar');
$u = usuario();

function pedido_visivel(array $p): bool { return pode('ver') || (int)$p['solicitante_id'] === usuario()['id']; }

/* ---------- Criar ---------- */
if ($acao === 'salvar' && is_post()) {
    $titulo = (string)post('titulo');
    if ($titulo === '') { flash('Descreva o problema no título.', 'danger'); redirect('pedidos/form'); }
    $ativoId = post_int('ativo_id');
    $dados = [
        'numero' => proximo_numero('pedidos', 'PT'), 'ativo_id' => $ativoId, 'localizacao_id' => post_int('localizacao_id'),
        'solicitante_id' => $u['id'], 'titulo' => $titulo, 'descricao' => post('descricao') ?: null,
        'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : 'media',
        'ativo_parado' => post('ativo_parado') ? 1 : 0,
    ];
    if ($ativoId && !$dados['localizacao_id']) $dados['localizacao_id'] = qv('SELECT localizacao_id FROM ativos WHERE id = ?', [$ativoId]);
    $id = insert('pedidos', $dados);
    if ($dados['ativo_parado'] && $ativoId) qx("UPDATE ativos SET status = 'parado' WHERE id = ? AND status = 'operacional'", [$ativoId]);
    auditar('criar', 'pedidos', $id);
    flash("Pedido {$dados['numero']} registrado. A equipe de manutenção será notificada no painel.");
    redirect('pedidos/ver', ['id' => $id]);
}

/* ---------- Avaliar (aprovar → OT / rejeitar) ---------- */
if ($acao === 'avaliar' && is_post()) {
    exigir('gerir');
    $p = q1('SELECT * FROM pedidos WHERE id = ?', [post_int('id')]);
    if (!$p || !in_array($p['status'], ['aberto', 'aprovado'], true)) { flash('Pedido não pode mais ser avaliado.', 'warning'); redirect('pedidos'); }
    $decisao = post('decisao');
    $resposta = post('resposta') ?: null;
    if ($decisao === 'rejeitar') {
        update('pedidos', ['status' => 'rejeitado', 'resposta' => $resposta, 'avaliado_por' => $u['id'], 'avaliado_em' => date('Y-m-d H:i:s')], (int)$p['id']);
        auditar('rejeitar', 'pedidos', (int)$p['id']);
        flash('Pedido rejeitado.', 'info');
        redirect('pedidos/ver', ['id' => $p['id']]);
    }
    // converter em OT
    $pdo = db(); $pdo->beginTransaction();
    $otId = insert('ordens_trabalho', [
        'numero' => proximo_numero('ordens_trabalho', 'OT'), 'ativo_id' => $p['ativo_id'], 'pedido_id' => $p['id'],
        'tipo' => array_key_exists((string)post('tipo'), ROTULOS['tipo_ot']) ? post('tipo') : 'corretiva',
        'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : $p['prioridade'],
        'status' => post_int('responsavel_id') ? 'planejada' : 'aberta',
        'titulo' => $p['titulo'], 'descricao' => trim(($p['descricao'] ?? '') . ($resposta ? "\n\n[Gestor] $resposta" : '')),
        'responsavel_id' => post_int('responsavel_id'), 'data_prevista' => post_date('data_prevista') ?? date('Y-m-d'),
        'ativo_parado' => $p['ativo_parado'], 'criado_por' => $u['id'],
    ]);
    update('pedidos', ['status' => 'convertido', 'ot_id' => $otId, 'resposta' => $resposta, 'avaliado_por' => $u['id'], 'avaliado_em' => date('Y-m-d H:i:s')], (int)$p['id']);
    $pdo->commit();
    auditar('converter', 'pedidos', (int)$p['id'], "OT #$otId");
    flash('Pedido aprovado e convertido em ordem de trabalho.');
    redirect('ots/ver', ['id' => $otId]);
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
        <div class="col-12"><label class="form-label">O que está acontecendo? *</label><input class="form-control" name="titulo" required maxlength="150" placeholder="Ex.: Compressor fazendo ruído e desarmando"></div>
        <div class="col-12"><label class="form-label">Detalhes</label><textarea class="form-control" name="descricao" rows="4" placeholder="Quando começou, sintomas, o que já foi tentado…"></textarea></div>
        <div class="col-md-4"><label class="form-label">Urgência</label><?= sel('prioridade', lista_rotulos('prioridade'), 'media', false) ?></div>
        <div class="col-md-8 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="ativo_parado" value="1" id="parado">
          <label class="form-check-label" for="parado">O equipamento está <strong>parado</strong> / sem condições de uso</label></div></div>
      </div>
      <button class="btn btn-danger mt-3"><i class="bi bi-send"></i> Enviar pedido</button>
    </form></div></div>
    <?php view_footer(); exit;
}

/* ---------- Detalhe ---------- */
if ($acao === 'ver') {
    $p = q1('SELECT p.*, a.codigo, a.nome AS ativo, l.nome AS local, us.nome AS solicitante, av.nome AS avaliador, o.numero AS ot_numero, o.status AS ot_status
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
          <tr><th class="fw-normal text-muted">Ativo</th><td><?= $p['ativo_id'] ? '<a href="' . e(url('ativos/ver', ['id' => $p['ativo_id']])) . '">' . e($p['codigo'] . ' · ' . $p['ativo']) . '</a>' : '—' ?></td></tr>
          <tr><th class="fw-normal text-muted">Localização</th><td><?= e(caminho_localizacao($p['localizacao_id'] ? (int)$p['localizacao_id'] : null)) ?></td></tr>
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
            <div class="col-12"><label class="form-label">Comentário / resposta ao solicitante</label><textarea class="form-control" name="resposta" rows="2"></textarea></div>
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

/* ---------- Lista ---------- */
$w = []; $prm = [];
if (!pode('ver')) { $w[] = 'p.solicitante_id = ?'; $prm[] = $u['id']; }
$st = get('status', pode('gerir') ? 'aberto' : '');
if ($st && $st !== 'todos') { $w[] = 'p.status = ?'; $prm[] = $st; }
if ($b = get('q')) { $w[] = '(p.numero LIKE ? OR p.titulo LIKE ? OR a.codigo LIKE ?)'; array_push($prm, "%$b%", "%$b%", "%$b%"); }
$sql = 'SELECT p.*, a.codigo, a.nome AS ativo, us.nome AS solicitante FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id JOIN usuarios us ON us.id=p.solicitante_id '
     . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . " ORDER BY FIELD(p.status,'aberto','aprovado','convertido','rejeitado'), FIELD(p.prioridade,'urgente','alta','media','baixa'), p.criado_em DESC";
$pg = paginar($sql, $prm);

view_header('Pedidos de trabalho', 'pedidos'); ?>
<div class="page-title"><h1>Pedidos de trabalho</h1><a class="btn btn-danger btn-sm" href="<?= e(url('pedidos/form')) ?>"><i class="bi bi-plus-lg"></i> Novo pedido</a></div>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <div class="btn-group btn-group-sm">
    <?php foreach (['aberto' => 'Abertos', 'convertido' => 'Convertidos', 'rejeitado' => 'Rejeitados', 'todos' => 'Todos'] as $k => $r): ?>
      <a class="btn btn-outline-secondary <?= ($st ?: 'todos') === $k ? 'active' : '' ?>" href="<?= e(url('pedidos', ['status' => $k])) ?>"><?= $r ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="ms-auto"><input type="hidden" name="r" value="pedidos"><input type="hidden" name="status" value="<?= e($st) ?>">
    <input class="form-control form-control-sm" name="q" placeholder="Buscar…" value="<?= e(get('q')) ?>"></form>
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th>Nº</th><th>Título</th><th>Ativo</th><th>Solicitante</th><th>Data</th><th>Urgência</th><th>Status</th></tr></thead><tbody>
<?php foreach ($pg['linhas'] as $l): ?>
  <tr data-href="<?= e(url('pedidos/ver', ['id' => $l['id']])) ?>"><td><?= e($l['numero']) ?></td><td><?= e($l['titulo']) ?> <?= $l['ativo_parado'] ? '<i class="bi bi-sign-stop-fill text-danger" title="Parado"></i>' : '' ?></td>
  <td><?= e($l['codigo']) ?></td><td><?= e($l['solicitante']) ?></td><td><?= dt($l['criado_em'], true) ?></td><td><?= badge('prioridade', $l['prioridade']) ?></td><td><?= badge('ped_status', $l['status']) ?></td></tr>
<?php endforeach; if (!$pg['linhas']): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum pedido.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white"><?= paginacao_html($pg) ?></div></div>
<?php view_footer();
