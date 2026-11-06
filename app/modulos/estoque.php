<?php
exigir('ver');
$forns = opcoes('SELECT id, nome FROM fornecedores ORDER BY nome');

if ($acao === 'salvar' && is_post()) {
    exigir('gerir');
    $id = post_int('id');
    $volta = fn(string $m) => [flash($m, 'danger'), redirect('estoque/form', $id ? ['id' => $id] : [])];
    $dados = [
        'codigo' => mb_strtoupper(mb_substr((string)post('codigo'), 0, 40)), 'descricao' => mb_substr((string)post('descricao'), 0, 200),
        'unidade' => mb_substr((string)post('unidade'), 0, 10) ?: 'un', 'familia' => mb_substr((string)post('familia'), 0, 80) ?: null,
        'estoque_minimo' => post_dec_pos('estoque_minimo') ?? 0, 'fornecedor_id' => post_int('fornecedor_id'),
        'localizacao' => mb_substr((string)post('localizacao'), 0, 80) ?: null, 'ativo' => post('ativo') ? 1 : 0,
    ];
    if (!$dados['codigo'] || !$dados['descricao']) $volta('Código e descrição são obrigatórios.');
    if (dec_invalido('estoque_minimo') || dec_invalido('estoque_inicial') || dec_invalido('custo_medio')) $volta('Quantidades e custos devem ser números positivos.');
    db()->beginTransaction();
    try {
        if ($id) update('artigos', $dados, $id);
        else {
            $dados['custo_medio'] = post_dec_pos('custo_medio') ?? 0;
            $id = insert('artigos', $dados);
            $ini = post_dec_pos('estoque_inicial');
            if ($ini > 0) movimentar_estoque($id, 'entrada', round($ini, 3), $dados['custo_medio'], null, 'INICIAL', 'Saldo inicial');
        }
        db()->commit();
        auditar('salvar', 'artigos', $id);
        flash('Artigo salvo.');
        redirect('estoque/ver', ['id' => $id]);
    } catch (PDOException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $volta(($ex->errorInfo[1] ?? 0) == 1062 ? 'Já existe um artigo com esse código.' : 'Erro ao salvar (valor fora do limite?).');
    }
}

if ($acao === 'movimento' && is_post()) {
    exigir('gerir');
    $id = (int)post_int('artigo_id');
    $tipo = (string)post('tipo');
    $qtd = post_dec_pos('quantidade');
    $custo = $tipo === 'entrada' ? post_dec_pos('custo_unitario') : null;
    if (!in_array($tipo, ['entrada', 'saida', 'ajuste'], true) || $qtd === null || ($tipo !== 'ajuste' && round($qtd, 3) <= 0) || $qtd > 99999999) {
        flash('Informe uma quantidade válida (positiva; no ajuste, o novo saldo).', 'danger'); redirect('estoque/ver', ['id' => $id]);
    }
    if ($tipo === 'entrada' && dec_invalido('custo_unitario')) { flash('O custo unitário não pode ser negativo.', 'danger'); redirect('estoque/ver', ['id' => $id]); }
    db()->beginTransaction();
    try {
        movimentar_estoque($id, $tipo, round($qtd, 3), $custo, null, mb_substr((string)post('documento'), 0, 60) ?: null, mb_substr((string)post('observacao'), 0, 255) ?: null);
        db()->commit();
        auditar('mov_' . $tipo, 'artigos', $id, (string)$qtd);
        flash('Movimento registrado.');
    } catch (RuntimeException $ex) { if (db()->inTransaction()) db()->rollBack(); flash($ex instanceof PDOException ? 'Valor fora do limite permitido.' : $ex->getMessage(), 'danger'); }
    redirect('estoque/ver', ['id' => $id]);
}

if ($acao === 'form') {
    exigir('gerir');
    $id = (int)get('id', 0);
    $a = $id ? q1('SELECT * FROM artigos WHERE id = ?', [$id]) : ['unidade' => 'un', 'ativo' => 1, 'estoque_minimo' => 0];
    if (!empty($_SESSION['old']) || !empty($GLOBALS['__old'])) $a = com_old($a);
    view_header('Artigo', 'estoque'); ?>
    <div class="page-title"><h1><?= $id ? 'Editar artigo' : 'Novo artigo' ?></h1><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('estoque')) ?>">Voltar</a></div>
    <div class="card"><div class="card-body"><form method="post" action="<?= e(url('estoque/salvar')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?: '' ?>">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Código *</label><input class="form-control text-uppercase" name="codigo" required value="<?= e($a['codigo'] ?? '') ?>"></div>
        <div class="col-md-7"><label class="form-label">Descrição *</label><input class="form-control" name="descricao" required value="<?= e($a['descricao'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Unidade</label><input class="form-control" name="unidade" value="<?= e($a['unidade']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Família</label><input class="form-control" name="familia" list="familias" value="<?= e($a['familia'] ?? '') ?>">
          <datalist id="familias"><?php foreach (q('SELECT DISTINCT familia FROM artigos WHERE familia IS NOT NULL') as $f): ?><option value="<?= e($f['familia']) ?>"><?php endforeach; ?></datalist></div>
        <div class="col-md-3"><label class="form-label">Fornecedor preferencial</label><?= sel('fornecedor_id', $forns, $a['fornecedor_id'] ?? null) ?></div>
        <div class="col-md-3"><label class="form-label">Localização no almoxarifado</label><input class="form-control" name="localizacao" value="<?= e($a['localizacao'] ?? '') ?>" placeholder="Prateleira A3"></div>
        <div class="col-md-3"><label class="form-label">Estoque mínimo</label><input class="form-control" inputmode="decimal" name="estoque_minimo" value="<?= num($a['estoque_minimo'], 2) ?>"></div>
        <?php if (!$id): ?>
          <div class="col-md-3"><label class="form-label">Saldo inicial</label><input class="form-control" inputmode="decimal" name="estoque_inicial" value="0"></div>
          <div class="col-md-3"><label class="form-label">Custo unitário (R$)</label><input class="form-control" inputmode="decimal" name="custo_medio" value="0"></div>
        <?php endif; ?>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="ativo" value="1" id="aa" <?= $a['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="aa">Ativo</label></div></div>
      </div>
      <button class="btn btn-primary mt-3">Salvar</button>
    </form></div></div>
    <?php view_footer(); exit;
}

if ($acao === 'ver') {
    $id = (int)get('id');
    $a = q1('SELECT a.*, f.nome AS fornecedor FROM artigos a LEFT JOIN fornecedores f ON f.id=a.fornecedor_id WHERE a.id = ?', [$id]);
    if (!$a) redirect('estoque');
    $movs = q('SELECT m.*, u.nome AS usuario, o.numero AS ot_numero FROM movimentos_estoque m LEFT JOIN usuarios u ON u.id=m.usuario_id LEFT JOIN ordens_trabalho o ON o.id=m.ot_id WHERE m.artigo_id = ? ORDER BY m.data DESC, m.id DESC LIMIT 200', [$id]);
    view_header($a['codigo'], 'estoque'); ?>
    <div class="page-title"><h1><span class="text-muted"><?= e($a['codigo']) ?></span> <?= e($a['descricao']) ?></h1>
      <div class="d-flex gap-2"><?php if (pode('gerir')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('estoque/form', ['id' => $id])) ?>">Editar</a><?php endif; ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('estoque')) ?>">Voltar</a></div></div>
    <div class="row g-3 mb-3">
      <div class="col-md-3"><div class="card kpi"><div class="card-body"><div class="rot">Saldo</div><div class="valor <?= $a['estoque_atual'] < $a['estoque_minimo'] ? 'text-danger' : '' ?>"><?= num($a['estoque_atual'], 2) ?> <small class="fs-6"><?= e($a['unidade']) ?></small></div></div></div></div>
      <div class="col-md-3"><div class="card kpi"><div class="card-body"><div class="rot">Mínimo</div><div class="valor"><?= num($a['estoque_minimo'], 2) ?></div></div></div></div>
      <div class="col-md-3"><div class="card kpi"><div class="card-body"><div class="rot">Custo médio</div><div class="valor fs-4"><?= moeda($a['custo_medio']) ?></div></div></div></div>
      <div class="col-md-3"><div class="card kpi"><div class="card-body"><div class="rot">Valor em estoque</div><div class="valor fs-4"><?= moeda($a['estoque_atual'] * $a['custo_medio']) ?></div></div></div></div>
    </div>
    <div class="row g-3">
      <?php if (pode('gerir')): ?>
      <div class="col-lg-4"><div class="card"><div class="card-header bg-white fw-semibold">Movimentar</div><div class="card-body">
        <form method="post" action="<?= e(url('estoque/movimento')) ?>"><?= csrf_field() ?><input type="hidden" name="artigo_id" value="<?= $id ?>">
          <div class="mb-2"><label class="form-label">Tipo</label><?= sel('tipo', ['entrada' => 'Entrada (compra/devolução)', 'saida' => 'Saída avulsa', 'ajuste' => 'Ajuste de inventário (novo saldo)'], 'entrada', false) ?></div>
          <div class="mb-2"><label class="form-label">Quantidade / novo saldo</label><input class="form-control" inputmode="decimal" name="quantidade" required></div>
          <div class="mb-2" data-show-when="tipo=entrada"><label class="form-label">Custo unitário (R$)</label><input class="form-control" inputmode="decimal" name="custo_unitario" value="<?= num($a['custo_medio']) ?>"></div>
          <div class="mb-2"><label class="form-label">Documento (NF, requisição)</label><input class="form-control" name="documento"></div>
          <div class="mb-2"><label class="form-label">Observação</label><input class="form-control" name="observacao"></div>
          <button class="btn btn-primary w-100">Registrar</button>
        </form>
        <div class="small text-muted mt-3">Fornecedor: <?= e($a['fornecedor'] ?: '—') ?><br>Localização: <?= e($a['localizacao'] ?: '—') ?></div>
      </div></div></div>
      <?php endif; ?>
      <div class="col-lg-<?= pode('gerir') ? 8 : 12 ?>"><div class="card"><div class="card-header bg-white fw-semibold">Kardex (últimos 200)</div><div class="table-responsive">
        <table class="table table-sm mb-0"><thead><tr><th>Data</th><th>Tipo</th><th class="text-end">Qtd</th><th class="text-end">Custo un.</th><th class="text-end">Saldo</th><th>OT / Doc.</th><th>Usuário</th></tr></thead><tbody>
        <?php foreach ($movs as $m): ?><tr><td><?= dt($m['data'], true) ?></td>
          <td><?= ['entrada' => '<span class="text-success">Entrada</span>', 'saida' => '<span class="text-danger">Saída</span>', 'ajuste' => '<span class="text-primary">Ajuste</span>'][$m['tipo']] ?></td>
          <td class="text-end"><?= ($m['tipo'] === 'saida' ? '-' : ($m['quantidade'] > 0 && $m['tipo'] === 'ajuste' ? '+' : '')) . num($m['quantidade'], 2) ?></td><td class="text-end"><?= moeda($m['custo_unitario']) ?></td>
          <td class="text-end fw-semibold"><?= num($m['saldo_apos'], 2) ?></td>
          <td><?= $m['ot_id'] ? '<a href="' . e(url('ots/ver', ['id' => $m['ot_id']])) . '">' . e($m['ot_numero']) . '</a>' : e($m['documento']) ?> <span class="text-muted small"><?= e($m['observacao']) ?></span></td><td><?= e($m['usuario']) ?></td></tr>
        <?php endforeach; if (!$movs): ?><tr><td colspan="7" class="text-muted text-center">Sem movimentos.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    </div>
    <?php view_footer(); exit;
}

/* ---------- Lista ---------- */
$w = ['a.ativo = 1']; $prm = [];
if ($b = get('q')) { $w[] = '(a.codigo LIKE ? OR a.descricao LIKE ? OR a.familia LIKE ?)'; array_push($prm, "%$b%", "%$b%", "%$b%"); }
if (get('abaixo')) $w[] = 'a.estoque_atual < a.estoque_minimo';
if ($fam = get('familia')) { $w[] = 'a.familia = ?'; $prm[] = $fam; }
$sql = 'SELECT a.*, f.nome AS fornecedor FROM artigos a LEFT JOIN fornecedores f ON f.id=a.fornecedor_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.codigo';
if (get('csv')) {
    csv_download('estoque', ['Código', 'Descrição', 'Família', 'Unidade', 'Saldo', 'Mínimo', 'Custo médio', 'Valor', 'Fornecedor', 'Localização'],
        array_map(fn($a) => [$a['codigo'], $a['descricao'], $a['familia'], $a['unidade'], num($a['estoque_atual'], 3), num($a['estoque_minimo'], 3), num($a['custo_medio'], 4), num($a['estoque_atual'] * $a['custo_medio']), $a['fornecedor'], $a['localizacao']], q($sql, $prm)));
}
$pg = paginar($sql, $prm, 40);
$tot = q1('SELECT COUNT(*) n, SUM(estoque_atual*custo_medio) v, SUM(estoque_atual < estoque_minimo) abaixo FROM artigos WHERE ativo=1');
view_header('Estoque', 'estoque'); ?>
<div class="page-title"><h1>Estoque de peças e materiais</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query($_GET + ['csv' => 1])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
    <?php if (pode('gerir')): ?><a class="btn btn-warning btn-sm" href="<?= e(url('estoque/form')) ?>"><i class="bi bi-plus-lg"></i> Novo artigo</a><?php endif; ?>
  </div></div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card kpi"><div class="card-body"><div class="rot">Artigos ativos</div><div class="valor"><?= (int)$tot['n'] ?></div></div></div></div>
  <div class="col-md-4"><div class="card kpi"><div class="card-body"><div class="rot">Valor do estoque</div><div class="valor"><?= moeda($tot['v']) ?></div></div></div></div>
  <div class="col-md-4"><a class="text-decoration-none" href="<?= e(url('estoque', ['abaixo' => 1])) ?>"><div class="card kpi"><div class="card-body"><div class="rot">Abaixo do mínimo</div><div class="valor <?= $tot['abaixo'] ? 'text-danger' : '' ?>"><?= (int)$tot['abaixo'] ?></div></div></div></a></div>
</div>
<form class="card card-body py-2 mb-3" method="get"><input type="hidden" name="r" value="estoque"><div class="row g-2">
  <div class="col-md-5"><input class="form-control form-control-sm" name="q" placeholder="Código, descrição, família…" value="<?= e(get('q')) ?>"></div>
  <div class="col-md-3"><?= sel('familia', opcoes('SELECT DISTINCT familia, familia FROM artigos WHERE familia IS NOT NULL ORDER BY familia'), get('familia'), true, 'class="form-select form-select-sm"') ?></div>
  <div class="col-md-2"><div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="abaixo" value="1" id="ab" <?= get('abaixo') ? 'checked' : '' ?>><label class="form-check-label small" for="ab">Só abaixo do mínimo</label></div></div>
  <div class="col-md-2"><button class="btn btn-sm btn-secondary w-100">Filtrar</button></div></div></form>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
<thead><tr><th>Código</th><th>Descrição</th><th>Família</th><th class="text-end">Saldo</th><th class="text-end">Mínimo</th><th class="text-end">Custo médio</th><th>Local</th></tr></thead><tbody>
<?php foreach ($pg['linhas'] as $a): $baixo = $a['estoque_atual'] < $a['estoque_minimo']; ?>
  <tr data-href="<?= e(url('estoque/ver', ['id' => $a['id']])) ?>" class="<?= $baixo ? 'atrasada' : '' ?>"><td><?= e($a['codigo']) ?></td><td><?= e($a['descricao']) ?></td><td><?= e($a['familia']) ?></td>
  <td class="text-end <?= $baixo ? 'text-danger fw-semibold' : '' ?>"><?= num($a['estoque_atual'], 2) ?> <?= e($a['unidade']) ?></td><td class="text-end"><?= num($a['estoque_minimo'], 2) ?></td><td class="text-end"><?= moeda($a['custo_medio']) ?></td><td><?= e($a['localizacao']) ?></td></tr>
<?php endforeach; if (!$pg['linhas']): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum artigo.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white"><?= paginacao_html($pg) ?></div></div>
<?php view_footer();
