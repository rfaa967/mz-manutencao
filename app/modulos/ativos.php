<?php
exigir('ver');

$locs = arvore_opcoes('localizacoes');
$cats = opcoes('SELECT id, nome FROM categorias_ativo ORDER BY nome');
$forns = opcoes('SELECT id, nome FROM fornecedores ORDER BY nome');

/* ================= SALVAR ================= */
if ($acao === 'salvar' && is_post()) {
    exigir('gerir');
    $id = post_int('id');
    $dados = [
        'codigo' => mb_strtoupper((string)post('codigo')), 'nome' => (string)post('nome'),
        'parent_id' => post_int('parent_id'), 'localizacao_id' => post_int('localizacao_id'),
        'categoria_id' => post_int('categoria_id'), 'fornecedor_id' => post_int('fornecedor_id'),
        'fabricante' => post('fabricante') ?: null, 'modelo' => post('modelo') ?: null, 'numero_serie' => post('numero_serie') ?: null,
        'data_aquisicao' => post_date('data_aquisicao'), 'valor_aquisicao' => post_dec_pos('valor_aquisicao'),
        'garantia_ate' => post_date('garantia_ate'),
        'criticidade' => in_array(post('criticidade'), ['A', 'B', 'C'], true) ? post('criticidade') : 'B',
        'status' => array_key_exists((string)post('status'), ROTULOS['ativo_status']) ? post('status') : 'operacional',
        'centro_custo' => post('centro_custo') ?: null,
        'horas_operacao_dia' => min(24, max(0, post_dec('horas_operacao_dia') ?? 24)),
        'descricao' => post('descricao') ?: null,
    ];
    if ($dados['garantia_ate'] && $dados['data_aquisicao'] && $dados['garantia_ate'] < $dados['data_aquisicao']) { flash('A garantia não pode terminar antes da data de aquisição.', 'danger'); redirect('ativos/form', $id ? ['id' => $id] : []); }
    if (dec_invalido('valor_aquisicao')) { flash('Valor de aquisição inválido.', 'danger'); redirect('ativos/form', $id ? ['id' => $id] : []); }
    if (!$dados['localizacao_id'] && $dados['parent_id']) $dados['localizacao_id'] = qv('SELECT localizacao_id FROM ativos WHERE id = ?', [$dados['parent_id']]);
    if (!$dados['codigo'] || !$dados['nome']) { flash('Código e nome são obrigatórios.', 'danger'); redirect('ativos/form', $id ? ['id' => $id] : []); }
    if ($id && $dados['parent_id'] && in_array($dados['parent_id'], descendentes('ativos', $id), true)) {
        flash('Um ativo não pode ser subordinado a ele mesmo ou a um componente seu.', 'danger'); redirect('ativos/form', ['id' => $id]);
    }
    try {
        if ($id) { update('ativos', $dados, $id); auditar('editar', 'ativos', $id); }
        else { $id = insert('ativos', $dados); auditar('criar', 'ativos', $id); }
        flash('Ativo salvo.');
        redirect('ativos/ver', ['id' => $id]);
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'Já existe um ativo com esse código.' : 'Erro ao salvar.', 'danger');
        redirect('ativos/form', $id ? ['id' => $id] : []);
    }
}

/* ================= EXCLUIR ================= */
if ($acao === 'excluir' && is_post()) {
    exigir('gerir');
    $id = post_int('id');
    if (qv('SELECT COUNT(*) FROM ordens_trabalho WHERE ativo_id = ?', [$id])) {
        update('ativos', ['status' => 'desativado'], $id);
        flash('O ativo possui histórico de OTs e foi DESATIVADO em vez de excluído.', 'warning');
        redirect('ativos/ver', ['id' => $id]);
    }
    if (qv('SELECT COUNT(*) FROM ativos WHERE parent_id = ?', [$id])) { flash('O ativo tem componentes. Exclua ou mova os componentes antes.', 'danger'); redirect('ativos/ver', ['id' => $id]); }
    $arquivos = array_column(q('SELECT arquivo FROM ativo_documentos WHERE ativo_id = ?', [$id]), 'arquivo');
    db()->beginTransaction();
    try {
        qx('DELETE FROM planos_manutencao WHERE ativo_id = ?', [$id]);   // antes dos contadores (FK RESTRICT)
        qx('DELETE FROM ativos WHERE id = ?', [$id]);
        db()->commit();
    } catch (PDOException $ex) {
        db()->rollBack();
        flash('Não foi possível excluir o ativo: ele ainda é referenciado por outros registros.', 'danger');
        redirect('ativos/ver', ['id' => $id]);
    }
    foreach ($arquivos as $arq) @unlink(cfg('upload_dir') . '/' . basename($arq));   // só depois de excluir no banco
    auditar('excluir', 'ativos', $id);
    flash('Ativo excluído.');
    redirect('ativos');
}

/* ================= DOCUMENTOS ================= */
if ($acao === 'upload' && is_post()) {
    exigir('executar');
    limitar_cfg('uploads_hora', 'u' . usuario()['id']);
    $id = (int)post_int('id');
    if (!qv('SELECT 1 FROM ativos WHERE id = ?', [$id])) { flash('Ativo não encontrado.', 'danger'); redirect('ativos'); }
    $f = $_FILES['arquivo'] ?? null;
    $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    if ($f && is_array($f) && in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) flash('Arquivo maior que o limite do servidor (upload_max_filesize = ' . ini_get('upload_max_filesize') . ').', 'danger');
    elseif (!$f || !is_array($f) || !is_string($f['name'] ?? null) || $f['error'] !== UPLOAD_ERR_OK) flash('Falha no envio do arquivo.', 'danger');
    elseif ($f['size'] > cfg('upload_max_mb', 15) * 1048576) flash('Arquivo maior que o limite.', 'danger');
    elseif (!in_array($ext, cfg('upload_extensoes', []), true)) flash('Tipo de arquivo não permitido.', 'danger');
    else {
        $dir = cfg('upload_dir');
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $nome = 'at' . $id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (move_uploaded_file($f['tmp_name'], "$dir/$nome")) {
            insert('ativo_documentos', ['ativo_id' => $id, 'nome_original' => mb_substr(basename($f['name']), 0, 250), 'arquivo' => $nome, 'tamanho' => (int)$f['size'], 'enviado_por' => usuario()['id']]);
            auditar('upload', 'ativos', $id, $f['name']);
            flash('Documento anexado.');
        } else flash('Não foi possível gravar o arquivo.', 'danger');
    }
    redirect('ativos/ver', ['id' => $id, 'tab' => 'docs']);
}

if ($acao === 'download') {
    $d = q1('SELECT * FROM ativo_documentos WHERE id = ?', [(int)get('id')]);
    $path = $d ? cfg('upload_dir') . '/' . basename($d['arquivo']) : '';
    if (!$d || !is_file($path)) { http_response_code(404); exit('Arquivo não encontrado'); }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true); // PDFs e demais: sempre download
    header("Content-Security-Policy: sandbox; default-src 'none'; img-src 'self'");
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $d['nome_original']) . '"');
    readfile($path);
    exit;
}

if ($acao === 'excluir_doc' && is_post()) {
    exigir('gerir');
    $d = q1('SELECT * FROM ativo_documentos WHERE id = ?', [post_int('doc_id')]);
    if ($d) {
        @unlink(cfg('upload_dir') . '/' . basename($d['arquivo']));
        qx('DELETE FROM ativo_documentos WHERE id = ?', [$d['id']]);
        auditar('excluir_doc', 'ativos', (int)$d['ativo_id'], $d['nome_original']);
        flash('Documento removido.');
    }
    redirect('ativos/ver', ['id' => $d['ativo_id'] ?? 0, 'tab' => 'docs']);
}

/* ================= ETIQUETAS QR ================= */
if ($acao === 'etiquetas') {
    $w = ["a.status <> 'desativado'"]; $prm = [];
    if ($i = get('id')) { $w[] = 'a.id = ?'; $prm[] = (int)$i; }
    if ($l = get('loc')) { $w[] = 'a.localizacao_id IN (' . implode(',', descendentes('localizacoes', (int)$l)) . ')'; }
    if ($c = get('cat')) { $w[] = 'a.categoria_id = ?'; $prm[] = (int)$c; }
    if ($cr = get('crit')) { $w[] = 'a.criticidade = ?'; $prm[] = $cr; }
    $lista = q('SELECT a.id, a.codigo, a.nome, l.nome AS local FROM ativos a LEFT JOIN localizacoes l ON l.id = a.localizacao_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.codigo LIMIT 500', $prm);
    $tam = in_array(get('tam'), ['p', 'm', 'g'], true) ? get('tam') : 'm';
    $mm = ['p' => 38, 'm' => 50, 'g' => 70][$tam];
    view_header('Etiquetas QR', 'ativos'); ?>
    <style>
      .etq-grade { display: flex; flex-wrap: wrap; gap: 4mm; }
      .etq { width: <?= $mm + 32 ?>mm; border: 1px dashed #adb5bd; padding: 2mm; display: flex; gap: 2mm; align-items: center; background: #fff; break-inside: avoid; page-break-inside: avoid; }
      .etq .qr { width: <?= $mm ?>mm; height: <?= $mm ?>mm; flex: 0 0 auto; }
      .etq .qr svg { width: 100%; height: 100%; }
      .etq .txt { font-size: <?= $tam === 'p' ? 7 : 9 ?>pt; line-height: 1.2; overflow: hidden; }
      .etq .cod { font-weight: 700; font-size: <?= $tam === 'p' ? 9 : 12 ?>pt; }
      @media print { .etq { border-color: #ccc; } @page { margin: 8mm; } }
    </style>
    <div class="page-title no-print"><h1>Etiquetas QR dos ativos</h1>
      <div class="d-flex gap-2"><button class="btn btn-primary btn-sm" data-print><i class="bi bi-printer"></i> Imprimir</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('ativos')) ?>">Voltar</a></div></div>
    <form class="card card-body py-2 mb-3 no-print" method="get"><input type="hidden" name="r" value="ativos/etiquetas"><div class="row g-2">
      <div class="col-md-4"><?= sel('loc', arvore_opcoes('localizacoes'), get('loc'), true, 'class="form-select form-select-sm"', 'Todas as localizações') ?></div>
      <div class="col-md-3"><?= sel('cat', $cats, get('cat'), true, 'class="form-select form-select-sm"', 'Todas as categorias') ?></div>
      <div class="col-md-2"><?= sel('crit', ['A' => 'Criticidade A', 'B' => 'Criticidade B', 'C' => 'Criticidade C'], get('crit'), true, 'class="form-select form-select-sm"', 'Toda criticidade') ?></div>
      <div class="col-md-2"><?= sel('tam', ['p' => 'Pequena (38 mm)', 'm' => 'Média (50 mm)', 'g' => 'Grande (70 mm)'], $tam, false, 'class="form-select form-select-sm"') ?></div>
      <div class="col-md-1"><button class="btn btn-sm btn-secondary w-100">Gerar</button></div>
    </div><div class="form-text">O QR abre a ficha do ativo na versão mobile (<?= e(url_qr('CODIGO')) ?>). Até 500 etiquetas por vez.</div></form>
    <div class="etq-grade">
      <?php foreach ($lista as $a): ?>
        <div class="etq"><div class="qr" data-qr="<?= e(url_qr($a['codigo'])) ?>"></div>
          <div class="txt"><div class="cod"><?= e($a['codigo']) ?></div><div><?= e($a['nome']) ?></div><div class="text-muted"><?= e($a['local']) ?></div>
            <div class="text-muted mt-1"><?= e(cfg('empresa')) ?> · Manutenção</div></div></div>
      <?php endforeach; if (!$lista): ?><p class="text-muted">Nenhum ativo para os filtros.</p><?php endif; ?>
    </div>
    <?php $GLOBALS['__scripts'] = qr_js(); view_footer(); exit;
}

/* ================= FORMULÁRIO ================= */
if ($acao === 'form') {
    exigir('gerir');
    $id = (int)get('id', 0);
    $a = $id ? q1('SELECT * FROM ativos WHERE id = ?', [$id]) : ['criticidade' => 'B', 'status' => 'operacional', 'horas_operacao_dia' => 24, 'parent_id' => get('pai'),
        'localizacao_id' => get('pai') ? qv('SELECT localizacao_id FROM ativos WHERE id = ?', [(int)get('pai')]) : null];
    $a = com_old($a, ['valor_aquisicao', 'horas_operacao_dia']);
    if ($id && !$a) redirect('ativos');
    view_header($id ? 'Editar ativo' : 'Novo ativo', 'ativos'); ?>
    <div class="page-title"><h1><?= $id ? 'Editar ativo' : 'Novo ativo' ?></h1>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e($id ? url('ativos/ver', ['id' => $id]) : url('ativos')) ?>">Voltar</a></div>
    <form method="post" action="<?= e(url('ativos/salvar')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?: '' ?>">
    <div class="card mb-3"><div class="card-header bg-white fw-semibold">Identificação</div><div class="card-body"><div class="row g-3">
      <div class="col-md-3"><label class="form-label">Código *</label><input class="form-control text-uppercase" name="codigo" required maxlength="40" value="<?= e($a['codigo'] ?? '') ?>"></div>
      <div class="col-md-9"><label class="form-label">Nome / designação *</label><input class="form-control" name="nome" required value="<?= e($a['nome'] ?? '') ?>"></div>
      <div class="col-md-6"><label class="form-label">Ativo pai (componente de)</label><?= sel('parent_id', arvore_opcoes('ativos', 'nome', $id ?: null), $a['parent_id'] ?? null) ?></div>
      <div class="col-md-6"><label class="form-label">Localização</label><?= sel('localizacao_id', $locs, $a['localizacao_id'] ?? null) ?></div>
      <div class="col-md-4"><label class="form-label">Categoria</label><?= sel('categoria_id', $cats, $a['categoria_id'] ?? null) ?></div>
      <div class="col-md-4"><label class="form-label">Criticidade</label><?= sel('criticidade', ['A' => 'A — Crítico', 'B' => 'B — Importante', 'C' => 'C — Baixa'], $a['criticidade'], false) ?></div>
      <div class="col-md-4"><label class="form-label">Status</label><?= sel('status', lista_rotulos('ativo_status'), $a['status'], false) ?></div>
    </div></div></div>
    <div class="card mb-3"><div class="card-header bg-white fw-semibold">Dados técnicos e financeiros</div><div class="card-body"><div class="row g-3">
      <div class="col-md-4"><label class="form-label">Fabricante</label><input class="form-control" name="fabricante" value="<?= e($a['fabricante'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Modelo</label><input class="form-control" name="modelo" value="<?= e($a['modelo'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Nº de série</label><input class="form-control" name="numero_serie" value="<?= e($a['numero_serie'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label">Fornecedor</label><?= sel('fornecedor_id', $forns, $a['fornecedor_id'] ?? null) ?></div>
      <div class="col-md-2"><label class="form-label">Aquisição</label><input class="form-control" type="date" name="data_aquisicao" value="<?= e($a['data_aquisicao'] ?? '') ?>"></div>
      <div class="col-md-2"><label class="form-label">Valor (R$)</label><input class="form-control" inputmode="decimal" name="valor_aquisicao" value="<?= isset($a['valor_aquisicao']) ? num($a['valor_aquisicao']) : '' ?>"></div>
      <div class="col-md-2"><label class="form-label">Garantia até</label><input class="form-control" type="date" name="garantia_ate" value="<?= e($a['garantia_ate'] ?? '') ?>"></div>
      <div class="col-md-2"><label class="form-label">Centro de custo</label><input class="form-control" name="centro_custo" value="<?= e($a['centro_custo'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label">Horas de operação/dia</label><input class="form-control" inputmode="decimal" name="horas_operacao_dia" value="<?= num($a['horas_operacao_dia'], 1) ?>">
        <div class="form-text">Base de cálculo da disponibilidade e MTBF.</div></div>
      <div class="col-12"><label class="form-label">Descrição / características</label><textarea class="form-control" rows="4" name="descricao"><?= e($a['descricao'] ?? '') ?></textarea></div>
    </div></div></div>
    <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Salvar ativo</button>
    </form>
    <?php view_footer(); exit;
}

/* ================= FICHA DO ATIVO ================= */
if ($acao === 'ver') {
    $id = (int)get('id', 0);
    $a = q1('SELECT a.*, c.nome AS categoria, f.nome AS fornecedor, p.codigo AS pai_codigo, p.nome AS pai_nome
             FROM ativos a LEFT JOIN categorias_ativo c ON c.id = a.categoria_id LEFT JOIN fornecedores f ON f.id = a.fornecedor_id
             LEFT JOIN ativos p ON p.id = a.parent_id WHERE a.id = ?', [$id]);
    if (!$a) redirect('ativos');
    $tab = get('tab', 'geral');
    $filhos = q('SELECT id, codigo, nome, status FROM ativos WHERE parent_id = ? ORDER BY codigo', [$id]);
    $contadores = q('SELECT * FROM contadores WHERE ativo_id = ?', [$id]);
    $planos = q('SELECT * FROM planos_manutencao WHERE ativo_id = ? ORDER BY ativo DESC, titulo', [$id]);
    $ots = q("SELECT o.*, t.nome AS responsavel, " . SQL_CUSTO_OT . " + " . SQL_CUSTO_MAT . " + o.custo_externo AS custo
              FROM ordens_trabalho o LEFT JOIN tecnicos t ON t.id=o.responsavel_id WHERE o.ativo_id = ? ORDER BY o.data_abertura DESC", [$id]);
    $docs = q('SELECT * FROM ativo_documentos WHERE ativo_id = ? ORDER BY enviado_em DESC', [$id]);

    // indicadores do ativo e componentes (12 meses) — mesmas regras do relatório geral
    $ini = date('Y-m-d', strtotime('-12 months +1 day'));
    $k = kpis($ini, date('Y-m-d'), descendentes('ativos', $id));
    $disp = $k['disponibilidade'] ?? 0; $mtbf = $k['mtbf']; $custoTotal = $k['custo_total'];
    $k['falhas'] = $k['corretivas'];

    view_header($a['codigo'], 'ativos');
    if ($tab === 'geral') $GLOBALS['__scripts'] = qr_js(); ?>
    <div class="page-title">
      <div><div class="text-muted small"><?= e(caminho_localizacao($a['localizacao_id'] ? (int)$a['localizacao_id'] : null)) ?></div>
        <h1><span class="text-muted"><?= e($a['codigo']) ?></span> <?= e($a['nome']) ?> <?= badge('ativo_status', $a['status']) ?></h1></div>
      <div class="d-flex gap-2 no-print">
        <a class="btn btn-danger btn-sm" href="<?= e(url('pedidos/form', ['ativo' => $id])) ?>"><i class="bi bi-exclamation-triangle"></i> Reportar falha</a>
        <?php if (pode('gerir')): ?>
          <a class="btn btn-warning btn-sm" href="<?= e(url('ots/form', ['ativo' => $id])) ?>"><i class="bi bi-plus-lg"></i> Nova OT</a>
          <a class="btn btn-outline-primary btn-sm" href="<?= e(url('planos/form', ['ativo' => $id])) ?>"><i class="bi bi-arrow-repeat"></i> Novo plano</a>
          <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('ativos/form', ['id' => $id])) ?>"><i class="bi bi-pencil"></i> Editar</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <?php foreach ([['Disponibilidade 12m', num($disp, 1) . '%'], ['MTBF (h)', $mtbf !== null ? num($mtbf, 0) : '—'], ['MTTR (h)', $k['mttr'] !== null ? num($k['mttr'], 1) : '—'],
                     ['Falhas 12m', (int)$k['falhas']], ['Custo manutenção 12m', moeda($custoTotal)]] as [$r, $v]): ?>
        <div class="col-6 col-md"><div class="card kpi"><div class="card-body py-2"><div class="rot"><?= $r ?></div><div class="valor fs-4"><?= $v ?></div></div></div></div>
      <?php endforeach; ?>
    </div>

    <ul class="nav nav-tabs">
      <?php foreach (['geral' => 'Ficha', 'ots' => 'Histórico (' . count($ots) . ')', 'planos' => 'Planos (' . count($planos) . ')', 'docs' => 'Documentos (' . count($docs) . ')'] as $tk => $tr): ?>
        <li class="nav-item"><a class="nav-link <?= $tab === $tk ? 'active' : '' ?>" href="<?= e(url('ativos/ver', ['id' => $id, 'tab' => $tk])) ?>"><?= $tr ?></a></li>
      <?php endforeach; ?>
    </ul>
    <div class="card border-top-0 rounded-top-0"><div class="card-body">
    <?php if ($tab === 'geral'): ?>
      <div class="row">
        <div class="col-lg-7">
          <table class="table table-sm">
            <?php foreach ([
                'Ativo pai' => $a['pai_codigo'] ? '<a href="' . e(url('ativos/ver', ['id' => $a['parent_id']])) . '">' . e($a['pai_codigo'] . ' · ' . $a['pai_nome']) . '</a>' : '—',
                'Categoria' => e($a['categoria']), 'Criticidade' => e($a['criticidade']), 'Fabricante' => e($a['fabricante']), 'Modelo' => e($a['modelo']),
                'Nº série' => e($a['numero_serie']), 'Fornecedor' => e($a['fornecedor']), 'Aquisição' => dt($a['data_aquisicao']) . ($a['valor_aquisicao'] ? ' · ' . moeda($a['valor_aquisicao']) : ''),
                'Garantia até' => $a['garantia_ate'] ? dt($a['garantia_ate']) . ($a['garantia_ate'] >= date('Y-m-d') ? ' <span class="badge text-bg-success">vigente</span>' : ' <span class="badge text-bg-secondary">expirada</span>') : '—',
                'Centro de custo' => e($a['centro_custo']), 'Operação' => num($a['horas_operacao_dia'], 1) . ' h/dia',
            ] as $r => $v): ?><tr><th class="text-muted fw-normal" style="width:35%"><?= $r ?></th><td><?= $v ?></td></tr><?php endforeach; ?>
          </table>
          <?php if ($a['descricao']): ?><p class="small" style="white-space:pre-line"><?= e($a['descricao']) ?></p><?php endif; ?>
        </div>
        <div class="col-lg-5">
          <h6>Componentes <?php if (pode('gerir')): ?><a class="btn btn-link btn-sm" href="<?= e(url('ativos/form', ['pai' => $id])) ?>">+ adicionar</a><?php endif; ?></h6>
          <ul class="list-group mb-3">
            <?php foreach ($filhos as $f): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('ativos/ver', ['id' => $f['id']])) ?>"><?= e($f['codigo'] . ' · ' . $f['nome']) ?></a><?= badge('ativo_status', $f['status']) ?></li><?php endforeach; ?>
            <?php if (!$filhos): ?><li class="list-group-item text-muted small">Sem componentes.</li><?php endif; ?>
          </ul>
          <div class="d-flex align-items-center gap-3 border rounded p-2 mb-3">
            <div data-qr="<?= e(url_qr($a['codigo'])) ?>" style="width:96px;height:96px"></div>
            <div class="small"><div class="fw-semibold">QR Code do ativo</div><div class="text-muted">Abre esta ficha no celular para reportar falha, executar OTs e registrar leituras.</div>
              <a class="no-print" href="<?= e(url('ativos/etiquetas', ['id' => $id])) ?>" target="_blank"><i class="bi bi-printer"></i> Imprimir etiqueta</a></div>
          </div>
          <h6>Contadores <a class="btn btn-link btn-sm" href="<?= e(url('contadores', ['ativo' => $id])) ?>">gerenciar</a></h6>
          <ul class="list-group">
            <?php foreach ($contadores as $c): ?><li class="list-group-item d-flex justify-content-between"><span><?= e($c['nome']) ?></span><strong><?= num($c['valor_atual'], 1) ?> <?= e($c['unidade']) ?></strong></li><?php endforeach; ?>
            <?php if (!$contadores): ?><li class="list-group-item text-muted small">Sem contadores.</li><?php endif; ?>
          </ul>
        </div>
      </div>
      <?php if (pode('gerir')): ?>
        <form method="post" action="<?= e(url('ativos/excluir')) ?>" class="text-end no-print" data-confirm="Excluir este ativo? Se houver histórico ele será apenas desativado."><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn-link text-danger btn-sm">Excluir ativo</button></form>
      <?php endif; ?>

    <?php elseif ($tab === 'ots'): ?>
      <div class="table-responsive"><table class="table table-sm table-hover">
        <thead><tr><th>Nº</th><th>Tipo</th><th>Título</th><th>Abertura</th><th>Conclusão</th><th>Responsável</th><th>Parada (h)</th><th class="text-end">Custo</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($ots as $o): ?>
          <tr data-href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>"><td><?= e($o['numero']) ?></td><td><?= badge('tipo_ot', $o['tipo']) ?></td><td><?= e($o['titulo']) ?></td>
          <td><?= dt($o['data_abertura']) ?></td><td><?= dt($o['data_fim']) ?></td><td><?= e($o['responsavel']) ?></td><td><?= num($o['horas_parada'], 1) ?></td>
          <td class="text-end"><?= moeda($o['custo']) ?></td><td><?= badge('ot_status', $o['status']) ?></td></tr>
        <?php endforeach; if (!$ots): ?><tr><td colspan="9" class="text-muted text-center">Sem histórico.</td></tr><?php endif; ?>
      </tbody></table></div>

    <?php elseif ($tab === 'planos'): ?>
      <table class="table table-sm table-hover"><thead><tr><th>Plano</th><th>Tipo</th><th>Gatilho</th><th>Última</th><th>Próxima</th><th>Ativo</th></tr></thead><tbody>
      <?php foreach ($planos as $p): ?>
        <tr data-href="<?= e(url('planos/form', ['id' => $p['id']])) ?>"><td><?= e($p['titulo']) ?></td><td><?= badge('tipo_ot', $p['tipo']) ?></td>
        <td><?= $p['gatilho'] === 'tempo' ? 'A cada ' . (int)$p['periodicidade'] . ' ' . e($p['unidade_periodo']) . '(s)' : 'A cada ' . num($p['intervalo_contador'], 0) . ' (contador)' ?></td>
        <td><?= dt($p['ultima_execucao']) ?></td><td><?= $p['gatilho'] === 'tempo' ? dt($p['proxima_data']) : num($p['proximo_valor'], 0) ?></td><td><?= $p['ativo'] ? 'Sim' : 'Não' ?></td></tr>
      <?php endforeach; if (!$planos): ?><tr><td colspan="6" class="text-muted text-center">Nenhum plano.</td></tr><?php endif; ?>
      </tbody></table>

    <?php else: ?>
      <?php if (pode('executar')): ?>
      <form class="row g-2 mb-3 no-print" method="post" enctype="multipart/form-data" action="<?= e(url('ativos/upload')) ?>"><?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-md-6"><input class="form-control form-control-sm" type="file" name="arquivo" required></div>
        <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-upload"></i> Anexar</button></div>
        <div class="col-12 form-text">Manuais, esquemas, fotos, certificados. Máx. <?= (int)cfg('upload_max_mb') ?> MB — <?= e(implode(', ', cfg('upload_extensoes'))) ?></div>
      </form>
      <?php endif; ?>
      <table class="table table-sm"><thead><tr><th>Arquivo</th><th>Tamanho</th><th>Enviado em</th><th></th></tr></thead><tbody>
      <?php foreach ($docs as $d): ?>
        <tr><td><a href="<?= e(url('ativos/download', ['id' => $d['id']])) ?>" target="_blank"><i class="bi bi-file-earmark"></i> <?= e($d['nome_original']) ?></a></td>
        <td><?= num($d['tamanho'] / 1024, 0) ?> KB</td><td><?= dt($d['enviado_em'], true) ?></td>
        <td class="text-end"><?php if (pode('gerir')): ?><form method="post" action="<?= e(url('ativos/excluir_doc')) ?>" data-confirm="Remover documento?"><?= csrf_field() ?>
          <input type="hidden" name="doc_id" value="<?= $d['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0">remover</button></form><?php endif; ?></td></tr>
      <?php endforeach; if (!$docs): ?><tr><td colspan="4" class="text-muted text-center">Nenhum documento.</td></tr><?php endif; ?>
      </tbody></table>
    <?php endif; ?>
    </div></div>
    <?php view_footer(); exit;
}

/* ================= LISTA (tabela server-side) / ÁRVORE ================= */
$modo = get('modo') === 'arvore' ? 'arvore' : 'lista';
$t = new Tabela('ativos',
    'ativos a LEFT JOIN categorias_ativo c ON c.id = a.categoria_id LEFT JOIN localizacoes l ON l.id = a.localizacao_id',
    'a.id, a.codigo, a.nome, a.criticidade, a.status, a.fabricante, a.modelo, a.numero_serie, c.nome AS categoria, l.nome AS local,
     (SELECT COUNT(*) FROM ordens_trabalho o WHERE o.ativo_id = a.id AND o.status IN (' . STATUS_PENDENTES . ')) AS ots_abertas', [
    'codigo' => ['rotulo' => 'Código', 'ordem' => 'a.codigo', 'busca' => 'a.codigo', 'classe' => 'fw-semibold text-nowrap', 'render' => fn($r) => e($r['codigo'])],
    'nome' => ['rotulo' => 'Nome', 'ordem' => 'a.nome', 'busca' => "CONCAT_WS(' ', a.nome, a.fabricante, a.modelo, a.numero_serie)",
               'render' => fn($r) => e($r['nome']) . ($r['modelo'] ? '<div class="small text-muted">' . e(trim($r['fabricante'] . ' ' . $r['modelo'])) . '</div>' : ''), 'csv' => fn($r) => $r['nome']],
    'categoria' => ['rotulo' => 'Categoria', 'ordem' => 'c.nome', 'busca' => 'c.nome', 'classe' => 'd-none d-md-table-cell', 'render' => fn($r) => e($r['categoria'])],
    'local' => ['rotulo' => 'Localização', 'ordem' => 'l.nome', 'busca' => 'l.nome', 'classe' => 'd-none d-lg-table-cell', 'render' => fn($r) => e($r['local'])],
    'crit' => ['rotulo' => 'Crit.', 'ordem' => 'a.criticidade', 'render' => fn($r) => e($r['criticidade'])],
    'ots' => ['rotulo' => 'OTs abertas', 'ordem' => 'ots_abertas', 'classe' => 'text-center',
              'render' => fn($r) => $r['ots_abertas'] ? '<span class="badge text-bg-warning">' . (int)$r['ots_abertas'] . '</span>' : '', 'csv' => fn($r) => (int)$r['ots_abertas']],
    'status' => ['rotulo' => 'Status', 'ordem' => 'a.status', 'render' => fn($r) => badge('ativo_status', $r['status']), 'csv' => fn($r) => rotulo('ativo_status', $r['status'])],
    'serie' => ['rotulo' => 'Nº série', 'classe' => 'd-none', 'render' => fn($r) => '', 'csv' => fn($r) => $r['numero_serie']],
]);
$t->ordemPadrao = 'codigo'; $t->desempate = 'a.id';
$t->placeholder = 'Código, nome, modelo, série, local…';
$t->href = fn($r) => url('ativos/ver', ['id' => $r['id']]);
if ($l = (int)get('loc')) $t->where[] = 'a.localizacao_id IN (' . implode(',', descendentes('localizacoes', $l)) . ')';
if ($c = (int)get('cat')) { $t->where[] = 'a.categoria_id = ?'; $t->params[] = $c; }
if (array_key_exists((string)get('status'), ROTULOS['ativo_status'])) { $t->where[] = 'a.status = ?'; $t->params[] = get('status'); }
else $t->where[] = "a.status <> 'desativado'";
if (in_array(get('crit'), ['A', 'B', 'C'], true)) { $t->where[] = 'a.criticidade = ?'; $t->params[] = get('crit'); }
if ($modo === 'lista') $t->responder('ativos');

view_header('Ativos', 'ativos'); ?>
<div class="page-title"><h1>Ativos / Equipamentos</h1>
  <div class="d-flex gap-2">
    <div class="btn-group btn-group-sm">
      <a class="btn btn-outline-secondary <?= $modo === 'lista' ? 'active' : '' ?>" href="<?= e(url('ativos', ['modo' => 'lista'] + array_diff_key($_GET, ['r' => 1, 'modo' => 1]))) ?>"><i class="bi bi-list-ul"></i> Lista</a>
      <a class="btn btn-outline-secondary <?= $modo === 'arvore' ? 'active' : '' ?>" href="<?= e(url('ativos', ['modo' => 'arvore'])) ?>"><i class="bi bi-diagram-3"></i> Árvore</a>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('ativos/etiquetas', array_filter(['loc' => get('loc'), 'cat' => get('cat'), 'crit' => get('crit')]))) ?>"><i class="bi bi-qr-code"></i> Etiquetas QR</a>
    <?php if (pode('gerir')): ?><a class="btn btn-warning btn-sm" href="<?= e(url('ativos/form')) ?>"><i class="bi bi-plus-lg"></i> Novo ativo</a><?php endif; ?>
  </div>
</div>

<?php if ($modo === 'arvore'):
    // Árvore: Localização > Ativo > Componentes
    $todasLoc = q('SELECT id, parent_id, nome, codigo FROM localizacoes ORDER BY nome');
    $todosAt = q("SELECT id, parent_id, localizacao_id, codigo, nome, status FROM ativos WHERE status <> 'desativado' ORDER BY codigo");
    $locFilhos = []; foreach ($todasLoc as $x) $locFilhos[(int)$x['parent_id']][] = $x;
    $atPorLoc = []; $atFilhos = [];
    foreach ($todosAt as $x) { if ($x['parent_id']) $atFilhos[(int)$x['parent_id']][] = $x; else $atPorLoc[(int)$x['localizacao_id']][] = $x; }
    $rAt = function (array $lista) use (&$rAt, $atFilhos) {
        echo '<ul>';
        foreach ($lista as $x) {
            echo '<li><i class="bi bi-cpu text-primary"></i> <a href="' . e(url('ativos/ver', ['id' => $x['id']])) . '">' . e($x['codigo'] . ' · ' . $x['nome']) . '</a> ' . badge('ativo_status', $x['status']);
            if (!empty($atFilhos[(int)$x['id']])) $rAt($atFilhos[(int)$x['id']]);
            echo '</li>';
        }
        echo '</ul>';
    };
    $rLoc = function (int $pai) use (&$rLoc, &$rAt, $locFilhos, $atPorLoc) {
        if (empty($locFilhos[$pai])) return;
        echo '<ul>';
        foreach ($locFilhos[$pai] as $l) {
            echo '<li><i class="bi bi-geo-alt-fill text-warning"></i> <strong>' . e($l['nome']) . '</strong>';
            $rLoc((int)$l['id']);
            if (!empty($atPorLoc[(int)$l['id']])) $rAt($atPorLoc[(int)$l['id']]);
            echo '</li>';
        }
        echo '</ul>';
    }; ?>
    <div class="card"><div class="card-body arvore">
      <?php $rLoc(0); if (!empty($atPorLoc[0])) { echo '<div class="mt-2 text-muted small">Sem localização</div>'; $rAt($atPorLoc[0]); } ?>
    </div></div>
<?php else: ?>
    <form class="card card-body mb-3 py-2" method="get"><input type="hidden" name="r" value="ativos">
      <div class="row g-2">
        <div class="col-md-4"><?= sel('loc', $locs, get('loc'), true, 'class="form-select form-select-sm" aria-label="Localização" data-auto-submit', 'Todas as localizações') ?></div>
        <div class="col-md-3"><?= sel('cat', $cats, get('cat'), true, 'class="form-select form-select-sm" aria-label="Categoria" data-auto-submit', 'Todas as categorias') ?></div>
        <div class="col-md-3"><?= sel('status', lista_rotulos('ativo_status'), get('status'), true, 'class="form-select form-select-sm" aria-label="Status" data-auto-submit', 'Ativos em uso') ?></div>
        <div class="col-md-2"><?= sel('crit', ['A' => 'Criticidade A', 'B' => 'Criticidade B', 'C' => 'Criticidade C'], get('crit'), true, 'class="form-select form-select-sm" aria-label="Criticidade" data-auto-submit', 'Toda criticidade') ?></div>
      </div>
    </form>
    <?= $t->html() ?>
<?php endif;
view_footer();
