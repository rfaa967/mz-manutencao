<?php
exigir('executar');
$ativoId = (int)get('ativo', 0) ?: (int)post_int('ativo_id');

if ($acao === 'salvar' && is_post()) {
    exigir('gerir');
    $nome = mb_substr((string)post('nome'), 0, 80);
    $ini = post_dec_pos('valor_atual') ?? 0;
    if (!$ativoId || !qv('SELECT 1 FROM ativos WHERE id = ?', [$ativoId]) || $nome === '' || dec_invalido('valor_atual')) {
        flash('Informe o nome e um valor inicial válido.', 'danger');
    } else {
        db()->beginTransaction();
        $id = insert('contadores', ['ativo_id' => $ativoId, 'nome' => $nome, 'unidade' => mb_substr((string)post('unidade'), 0, 20) ?: 'h', 'valor_atual' => $ini, 'atualizado_em' => date('Y-m-d H:i:s')]);
        insert('leituras_contador', ['contador_id' => $id, 'valor' => $ini, 'data_leitura' => date('Y-m-d H:i:s'), 'usuario_id' => usuario()['id'], 'observacao' => 'Leitura inicial']);
        db()->commit();
        flash('Contador criado.');
    }
    redirect('contadores', ['ativo' => $ativoId]);
}

if ($acao === 'leitura' && is_post()) {
    $c = q1('SELECT * FROM contadores WHERE id = ?', [post_int('contador_id')]);
    $valor = post_dec_pos('valor');
    $data = post_datetime('data_leitura') ?? date('Y-m-d H:i:s');
    $reset = (bool)post('reset');
    $volta = function (string $msg) use ($c, $ativoId): never {
        flash($msg, 'danger');
        redirect_m('m/ativo', ['id' => $c['ativo_id'] ?? $ativoId]);
        redirect('contadores', ['ativo' => $c['ativo_id'] ?? $ativoId]);
    };
    if (!$c) $volta('Contador não encontrado.');
    if ($valor === null || $valor > 999999999999) $volta('Informe a leitura como um número positivo (ex.: 1.250 ou 1250,5).');
    if ($data > date('Y-m-d H:i:s', time() + 600)) $volta('A data da leitura não pode estar no futuro.');
    $retroativa = $c['atualizado_em'] && $data < $c['atualizado_em'];
    if ($reset && $retroativa) $volta('A troca/zeragem precisa ter data posterior à última leitura (' . dt($c['atualizado_em'], true) . ').');
    if (!$reset && !$retroativa && $valor < (float)$c['valor_atual'])
        $volta('O valor informado é menor que o atual (' . num($c['valor_atual'], 1) . '). Marque "troca/zeragem do contador" se for o caso.');
    if ($retroativa) {
        // leitura antiga entra só no histórico e precisa ser coerente com as leituras vizinhas
        $antes = qv('SELECT MAX(valor) FROM leituras_contador WHERE contador_id = ? AND data_leitura <= ?', [$c['id'], $data]);
        $depois = qv('SELECT MIN(valor) FROM leituras_contador WHERE contador_id = ? AND data_leitura >= ?', [$c['id'], $data]);
        if (($antes !== null && $valor < (float)$antes) || ($depois !== null && $valor > (float)$depois))
            $volta('Leitura retroativa incoerente com o histórico (deve ficar entre ' . num((float)$antes, 1) . ' e ' . num((float)$depois, 1) . ').');
    }

    db()->beginTransaction();
    try {
        $c = q1('SELECT * FROM contadores WHERE id = ? FOR UPDATE', [$c['id']]);
        insert('leituras_contador', ['contador_id' => $c['id'], 'valor' => $valor, 'data_leitura' => $data, 'usuario_id' => usuario()['id'],
            'observacao' => $reset ? 'Troca/zeragem do contador (antes: ' . num($c['valor_atual'], 1) . ')' : (mb_substr((string)post('observacao'), 0, 255) ?: null)]);
        if (!$retroativa) qx('UPDATE contadores SET valor_atual = ?, atualizado_em = ? WHERE id = ?', [$valor, $data, $c['id']]);
        if ($reset) {
            // reposiciona os planos na nova escala, preservando o que faltava para a próxima revisão
            foreach (q('SELECT * FROM planos_manutencao WHERE contador_id = ? FOR UPDATE', [$c['id']]) as $p) {
                $pend = q1("SELECT id FROM ordens_trabalho WHERE plano_id = ? AND status IN (" . STATUS_PENDENTES . ") LIMIT 1", [$p['id']]);
                if ($pend) {
                    // já venceu: a OT pendente passa a referenciar a nova escala
                    qx('UPDATE ordens_trabalho SET valor_previsto = ? WHERE id = ?', [$valor, $pend['id']]);
                    $novo = $valor;
                } else {
                    $novo = $valor + max(0, (float)$p['proximo_valor'] - (float)$c['valor_atual']);
                }
                update('planos_manutencao', ['proximo_valor' => $novo, 'ultimo_valor' => $valor], (int)$p['id']);
            }
        }
        db()->commit();
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        throw $ex;
    }
    $g = $retroativa ? [] : gerar_preventivas(usuario()['id'], (int)$c['id']);
    flash(($retroativa ? 'Leitura retroativa registrada no histórico.' : 'Leitura registrada.') . ($g ? ' ' . plural(count($g), 'OT preventiva gerada', 'OTs preventivas geradas') . ' pelo contador.' : ''));
    redirect_m('m/ativo', ['id' => $c['ativo_id']]);
    redirect('contadores', ['ativo' => $c['ativo_id']]);
}

if ($acao === 'excluir' && is_post()) {
    exigir('gerir');
    try {
        qx('DELETE FROM contadores WHERE id = ?', [post_int('contador_id')]);
        flash('Contador excluído.');
    } catch (PDOException) {
        flash('Este contador é usado por um plano de manutenção. Altere ou exclua o plano antes.', 'danger');
    }
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
