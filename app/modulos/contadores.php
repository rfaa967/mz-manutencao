<?php
exigir('executar');
$ativoId = (int)get('ativo', 0) ?: (int)post_int('ativo_id');

if ($acao === 'salvar' && is_post()) {
    exigir('gerir');
    $nome = (string)post('nome');
    if ($ativoId && $nome !== '') {
        $id = insert('contadores', ['ativo_id' => $ativoId, 'nome' => $nome, 'unidade' => post('unidade') ?: 'h', 'valor_atual' => post_dec('valor_atual') ?? 0, 'atualizado_em' => date('Y-m-d H:i:s')]);
        insert('leituras_contador', ['contador_id' => $id, 'valor' => post_dec('valor_atual') ?? 0, 'data_leitura' => date('Y-m-d H:i:s'), 'usuario_id' => usuario()['id'], 'observacao' => 'Leitura inicial']);
        flash('Contador criado.');
    }
    redirect('contadores', ['ativo' => $ativoId]);
}

if ($acao === 'leitura' && is_post()) {
    $c = q1('SELECT * FROM contadores WHERE id = ?', [post_int('contador_id')]);
    $valor = post_dec('valor');
    $data = post_datetime('data_leitura') ?? date('Y-m-d H:i:s');
    if (!$c || $valor === null) { flash('Leitura inválida.', 'danger'); redirect('contadores', ['ativo' => $ativoId]); }
    if ($valor < (float)$c['valor_atual'] && !post('reset')) {
        flash('O valor informado é menor que o atual (' . num($c['valor_atual'], 1) . '). Marque "troca/zeragem do contador" se for o caso.', 'danger');
        redirect('contadores', ['ativo' => $c['ativo_id']]);
    }
    insert('leituras_contador', ['contador_id' => $c['id'], 'valor' => $valor, 'data_leitura' => $data, 'usuario_id' => usuario()['id'], 'observacao' => post('reset') ? 'Troca/zeragem do contador' : (post('observacao') ?: null)]);
    qx('UPDATE contadores SET valor_atual = ?, atualizado_em = ? WHERE id = ?', [$valor, $data, $c['id']]);
    if (post('reset')) {
        // reposiciona planos por contador relativos ao novo valor
        foreach (q('SELECT * FROM planos_manutencao WHERE contador_id = ?', [$c['id']]) as $p) {
            update('planos_manutencao', ['proximo_valor' => $valor + (float)$p['intervalo_contador'], 'ultimo_valor' => $valor], (int)$p['id']);
        }
    }
    $g = gerar_preventivas(usuario()['id']);
    flash('Leitura registrada.' . ($g ? ' ' . count($g) . ' OT(s) preventiva(s) gerada(s) pelo contador.' : ''));
    redirect('contadores', ['ativo' => $c['ativo_id']]);
}

if ($acao === 'excluir' && is_post()) {
    exigir('gerir');
    qx('DELETE FROM contadores WHERE id = ?', [post_int('contador_id')]);
    flash('Contador excluído.');
    redirect('contadores', ['ativo' => $ativoId]);
}

$lista = q('SELECT c.*, a.codigo, a.nome AS ativo FROM contadores c JOIN ativos a ON a.id=c.ativo_id ' . ($ativoId ? 'WHERE c.ativo_id = ' . $ativoId : '') . ' ORDER BY a.codigo, c.nome');
view_header('Contadores', 'contadores'); ?>
<div class="page-title"><h1>Contadores e leituras</h1></div>
<form method="get" class="mb-3" style="max-width:480px"><input type="hidden" name="r" value="contadores"><?= sel('ativo', arvore_opcoes('ativos'), $ativoId ?: null, true, 'onchange="this.form.submit()"') ?></form>

<?php if ($ativoId && pode('gerir')): ?>
<div class="card mb-3"><div class="card-body">
  <form method="post" action="<?= e(url('contadores/salvar')) ?>" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="ativo_id" value="<?= $ativoId ?>">
    <div class="col-md-4"><label class="form-label">Novo contador</label><input class="form-control form-control-sm" name="nome" placeholder="Horímetro, Odômetro, Ciclos…" required></div>
    <div class="col-md-2"><label class="form-label">Unidade</label><input class="form-control form-control-sm" name="unidade" value="h"></div>
    <div class="col-md-3"><label class="form-label">Valor inicial</label><input class="form-control form-control-sm" inputmode="decimal" name="valor_atual" value="0"></div>
    <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Criar contador</button></div>
  </form>
</div></div>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($lista as $c):
    $leit = q('SELECT l.*, u.nome AS usuario FROM leituras_contador l LEFT JOIN usuarios u ON u.id=l.usuario_id WHERE contador_id = ? ORDER BY data_leitura DESC LIMIT 8', [$c['id']]); ?>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <div class="d-flex justify-content-between">
      <div><div class="small text-muted"><a href="<?= e(url('ativos/ver', ['id' => $c['ativo_id']])) ?>"><?= e($c['codigo'] . ' · ' . $c['ativo']) ?></a></div><h2 class="h6 mb-0"><?= e($c['nome']) ?></h2></div>
      <div class="text-end"><div class="fs-4 fw-bold"><?= num($c['valor_atual'], 1) ?> <small class="fs-6 text-muted"><?= e($c['unidade']) ?></small></div><div class="small text-muted"><?= dt($c['atualizado_em'], true) ?></div></div>
    </div>
    <form method="post" action="<?= e(url('contadores/leitura')) ?>" class="row g-1 mt-2"><?= csrf_field() ?><input type="hidden" name="contador_id" value="<?= $c['id'] ?>"><input type="hidden" name="ativo_id" value="<?= $c['ativo_id'] ?>">
      <div class="col-4"><input class="form-control form-control-sm" inputmode="decimal" name="valor" placeholder="Nova leitura" required></div>
      <div class="col-5"><input type="datetime-local" class="form-control form-control-sm" name="data_leitura" value="<?= e(input_dt(date('Y-m-d H:i'))) ?>"></div>
      <div class="col-3"><button class="btn btn-sm btn-primary w-100">Registrar</button></div>
      <div class="col-12"><div class="form-check small"><input class="form-check-input" type="checkbox" name="reset" value="1" id="rs<?= $c['id'] ?>"><label class="form-check-label" for="rs<?= $c['id'] ?>">Troca/zeragem do contador</label></div></div>
    </form>
    <table class="table table-sm small mt-2 mb-0"><tbody>
      <?php foreach ($leit as $l): ?><tr><td><?= dt($l['data_leitura'], true) ?></td><td class="text-end"><?= num($l['valor'], 1) ?></td><td><?= e($l['usuario']) ?></td><td class="text-muted"><?= e($l['observacao']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php if (pode('gerir')): ?><form method="post" action="<?= e(url('contadores/excluir')) ?>" class="text-end" data-confirm="Excluir contador e histórico de leituras?"><?= csrf_field() ?>
      <input type="hidden" name="contador_id" value="<?= $c['id'] ?>"><input type="hidden" name="ativo_id" value="<?= $c['ativo_id'] ?>"><button class="btn btn-link btn-sm text-danger p-0">excluir</button></form><?php endif; ?>
  </div></div></div>
<?php endforeach; if (!$lista): ?><div class="col-12 text-muted">Nenhum contador<?= $ativoId ? ' para este ativo' : '' ?>.</div><?php endif; ?>
</div>
<?php view_footer();
