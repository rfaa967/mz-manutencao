<?php
/**
 * Interface mobile (técnico em campo / solicitante): leitura de QR, ficha resumida do ativo,
 * execução de OT, leitura de contador e abertura de pedido. Os formulários postam para as
 * ações dos módulos completos com _m=1, que devolvem o usuário para estas telas.
 */
exigir('solicitar');
$u = usuario();

function m_header(string $titulo, ?string $voltar = null, string $aba = ''): void
{
    $GLOBALS['__titulo'] = $titulo; $GLOBALS['__voltar'] = $voltar; $GLOBALS['__aba'] = $aba;
    require APP_ROOT . '/app/views/m_header.php';
}
function m_footer(): void { require APP_ROOT . '/app/views/m_footer.php'; }

function m_item_ot(array $o): string
{
    $atr = !in_array($o['status'], ['concluida', 'cancelada'], true) && $o['data_prevista'] && $o['data_prevista'] < date('Y-m-d');
    return '<a href="' . e(url('m/ot', ['id' => $o['id']])) . '" class="' . ($atr ? 'atrasada' : '') . '">'
        . '<div class="d-flex justify-content-between gap-2"><strong>' . e($o['titulo']) . '</strong>' . badge('ot_status', $o['status']) . '</div>'
        . '<div class="m-sub">' . e($o['numero']) . ' · ' . e($o['codigo'] ?? '') . ' · ' . badge('tipo_ot', $o['tipo']) . ' ' . badge('prioridade', $o['prioridade'])
        . ($o['data_prevista'] ? ' · <span class="' . ($atr ? 'text-danger fw-semibold' : '') . '">' . ($atr ? '⚠ ' : '') . dt($o['data_prevista']) . '</span>' : '') . '</div></a>';
}

/* ---------------- Início ---------------- */
if ($acao === 'index') {
    m_header('Olá, ' . explode(' ', $u['nome'])[0], null, 'inicio'); ?>
    <form method="get" class="mb-3"><input type="hidden" name="r" value="m/buscar">
      <div class="input-group"><input class="form-control" name="q" placeholder="Código ou nome do equipamento" autocomplete="off">
        <button class="btn btn-dark"><i class="bi bi-search"></i></button></div></form>
    <a href="<?= e(url('m/scan')) ?>" class="btn btn-warning btn-m w-100 mb-3"><i class="bi bi-qr-code-scan"></i> Ler QR Code do equipamento</a>
    <?php if (pode('ver')):
        $minhas = $u['tecnico_id'] ? q("SELECT o.*, a.codigo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id
            WHERE o.responsavel_id = ? AND o.status IN (" . STATUS_PENDENTES . ")
            ORDER BY o.status='em_execucao' DESC, FIELD(o.prioridade,'urgente','alta','media','baixa'), o.data_prevista IS NULL, o.data_prevista LIMIT 30", [$u['tecnico_id']]) : [];
        $semResp = (int)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE responsavel_id IS NULL AND status IN (" . STATUS_PENDENTES . ")");
        $pedAbertos = pode('gerir') ? (int)qv("SELECT COUNT(*) FROM pedidos WHERE status='aberto'") : 0; ?>
      <?php if ($pedAbertos): ?><a class="alert alert-warning d-block text-decoration-none py-2" href="<?= e(url('pedidos')) ?>"><i class="bi bi-megaphone"></i> <?= $pedAbertos ?> pedido(s) aguardando avaliação</a><?php endif; ?>
      <h2 class="h6 text-muted text-uppercase mt-2">Minhas ordens de trabalho (<?= count($minhas) ?>)</h2>
      <div class="m-lista"><?php foreach ($minhas as $o) echo m_item_ot($o); ?>
        <?php if (!$minhas): ?><div class="m-item text-muted"><?= $u['tecnico_id'] ? 'Nenhuma OT atribuída a você.' : 'Seu usuário não está vinculado a um técnico.' ?></div><?php endif; ?></div>
      <?php if ($semResp): ?><a class="btn btn-outline-secondary w-100" href="<?= e(url('m/ots', ['f' => 'sem'])) ?>"><?= $semResp ?> OT(s) sem responsável</a><?php endif; ?>
    <?php else:
        $peds = q('SELECT p.*, a.codigo FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id WHERE p.solicitante_id = ? ORDER BY p.id DESC LIMIT 10', [$u['id']]); ?>
      <a href="<?= e(url('m/pedido')) ?>" class="btn btn-danger btn-m w-100 mb-3"><i class="bi bi-exclamation-triangle"></i> Reportar falha sem QR</a>
      <h2 class="h6 text-muted text-uppercase">Meus pedidos recentes</h2>
      <div class="m-lista"><?php foreach ($peds as $p): ?>
        <a href="<?= e(url('m/pedidos', ['id' => $p['id']])) ?>"><div class="d-flex justify-content-between"><strong><?= e($p['titulo']) ?></strong><?= badge('ped_status', $p['status']) ?></div>
          <div class="m-sub"><?= e($p['numero']) ?> · <?= e($p['codigo']) ?> · <?= dt($p['criado_em'], true) ?></div></a>
      <?php endforeach; if (!$peds): ?><div class="m-item text-muted">Nenhum pedido ainda.</div><?php endif; ?></div>
    <?php endif;
    m_footer(); exit;
}

/* ---------------- Leitor de QR ---------------- */
if ($acao === 'scan') {
    m_header('Ler QR Code', url('m'), 'scan'); ?>
    <div id="leitor" data-destino="<?= e(url('m/ativo')) ?>"></div>
    <div id="leitor-msg" class="small text-muted mt-2">Aponte a câmera para a etiqueta do equipamento.</div>
    <form method="get" class="mt-3"><input type="hidden" name="r" value="m/buscar">
      <label class="form-label small text-muted">Ou digite o código</label>
      <div class="input-group"><input class="form-control text-uppercase" name="q" autocomplete="off"><button class="btn btn-dark">Abrir</button></div></form>
    <?php
    $GLOBALS['__scripts'] = cdn_js('html5-qrcode@2.3.8/html5-qrcode.min.js');
    m_footer(); exit;
}

/* ---------------- Busca ---------------- */
if ($acao === 'buscar') {
    $t = trim((string)get('q', ''));
    $res = $t === '' ? [] : q("SELECT a.id, a.codigo, a.nome, a.status, l.nome AS local FROM ativos a LEFT JOIN localizacoes l ON l.id=a.localizacao_id
        WHERE a.status <> 'desativado' AND (a.codigo = ? OR a.codigo LIKE ? OR a.nome LIKE ?) ORDER BY a.codigo = ? DESC, a.codigo LIMIT 40", [$t, "$t%", "%$t%", $t]);
    if (count($res) === 1) redirect('m/ativo', ['id' => $res[0]['id']]);
    m_header('Buscar equipamento', url('m'), 'inicio'); ?>
    <form method="get" class="mb-3"><input type="hidden" name="r" value="m/buscar">
      <div class="input-group"><input class="form-control" name="q" value="<?= e($t) ?>" autofocus><button class="btn btn-dark"><i class="bi bi-search"></i></button></div></form>
    <div class="m-lista"><?php foreach ($res as $a): ?>
      <a href="<?= e(url('m/ativo', ['id' => $a['id']])) ?>"><div class="d-flex justify-content-between"><strong><?= e($a['codigo']) ?></strong><?= badge('ativo_status', $a['status']) ?></div>
        <div><?= e($a['nome']) ?></div><div class="m-sub"><?= e($a['local']) ?></div></a>
    <?php endforeach; if ($t !== '' && !$res): ?><div class="m-item text-muted">Nenhum equipamento encontrado para “<?= e($t) ?>”.</div><?php endif; ?></div>
    <?php m_footer(); exit;
}

/* ---------------- Ficha do ativo ---------------- */
if ($acao === 'ativo') {
    $a = get('id') ? q1('SELECT * FROM ativos WHERE id = ?', [(int)get('id')]) : q1('SELECT * FROM ativos WHERE codigo = ?', [mb_strtoupper(trim((string)get('c', '')))]);
    if (!$a) {
        $cod = (string)get('c', '');
        flash(preg_match('/^[A-Z0-9][A-Z0-9\-_.\/]{0,39}$/i', $cod) ? "Equipamento não encontrado: $cod" : 'Equipamento não encontrado.', 'warning');
        redirect('m/scan');
    }
    $id = (int)$a['id'];
    $ots = pode('ver') ? q("SELECT o.*, a.codigo FROM ordens_trabalho o JOIN ativos a ON a.id=o.ativo_id WHERE o.ativo_id = ? AND o.status IN (" . STATUS_PENDENTES . ") ORDER BY o.data_prevista", [$id]) : [];
    $hist = pode('ver') ? q("SELECT o.id, o.numero, o.titulo, o.tipo, o.data_fim, o.causa_falha FROM ordens_trabalho o WHERE o.ativo_id = ? AND o.status='concluida' ORDER BY o.data_fim DESC LIMIT 5", [$id]) : [];
    $cont = pode('executar') ? q('SELECT * FROM contadores WHERE ativo_id = ?', [$id]) : [];
    $pedAb = pode('ver')
        ? q("SELECT numero, titulo, criado_em FROM pedidos WHERE ativo_id = ? AND status IN ('aberto','aprovado') ORDER BY id DESC LIMIT 3", [$id])
        : q("SELECT numero, titulo, criado_em FROM pedidos WHERE ativo_id = ? AND solicitante_id = ? AND status IN ('aberto','aprovado') ORDER BY id DESC LIMIT 3", [$id, $u['id']]);
    $pedOutros = pode('ver') ? 0 : (int)qv("SELECT COUNT(*) FROM pedidos WHERE ativo_id = ? AND solicitante_id <> ? AND status IN ('aberto','aprovado')", [$id, $u['id']]);
    m_header($a['codigo'], url('m')); ?>
    <div class="m-card">
      <div class="d-flex justify-content-between align-items-start gap-2"><h1 class="h5 mb-1"><?= e($a['nome']) ?></h1><?= badge('ativo_status', $a['status']) ?></div>
      <div class="small text-muted mb-2"><?= e(caminho_localizacao($a['localizacao_id'] ? (int)$a['localizacao_id'] : null)) ?></div>
      <dl class="m-kv mb-0">
        <?php if ($a['fabricante'] || $a['modelo']): ?><dt>Modelo</dt><dd><?= e(trim($a['fabricante'] . ' ' . $a['modelo'])) ?></dd><?php endif; ?>
        <?php if ($a['numero_serie']): ?><dt>Nº série</dt><dd><?= e($a['numero_serie']) ?></dd><?php endif; ?>
        <dt>Criticidade</dt><dd><?= e($a['criticidade']) ?></dd>
      </dl>
    </div>

    <?php if ($pedOutros): ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Este equipamento já tem <?= plural($pedOutros, 'pedido aberto', 'pedidos abertos') ?> por outras pessoas. Se for o mesmo problema, não precisa reportar de novo.</div><?php endif; ?>
    <?php if ($pedAb): ?><div class="alert alert-info py-2 small"><i class="bi bi-info-circle"></i> Já existe pedido aberto para este equipamento:
      <?php foreach ($pedAb as $p): ?><div><strong><?= e($p['numero']) ?></strong> — <?= e($p['titulo']) ?> (<?= dt($p['criado_em'], true) ?>)</div><?php endforeach; ?></div><?php endif; ?>

    <div class="m-grid mb-3">
      <a class="btn btn-danger btn-m" href="<?= e(url('m/pedido', ['ativo' => $id])) ?>"><i class="bi bi-exclamation-triangle"></i><br>Reportar falha</a>
      <?php if (pode('executar')): ?><a class="btn btn-dark btn-m" href="<?= e(url('m/nova_ot', ['ativo' => $id])) ?>"><i class="bi bi-plus-lg"></i><br>Abrir OT</a><?php endif; ?>
    </div>

    <?php if (pode('ver')): ?>
      <h2 class="h6 text-muted text-uppercase">OTs em aberto (<?= count($ots) ?>)</h2>
      <div class="m-lista"><?php foreach ($ots as $o) echo m_item_ot($o); if (!$ots): ?><div class="m-item text-muted">Nenhuma OT em aberto.</div><?php endif; ?></div>
    <?php endif; ?>

    <?php foreach ($cont as $c): ?>
      <div class="m-card"><h2><i class="bi bi-stopwatch"></i> <?= e($c['nome']) ?>: <?= num($c['valor_atual'], 1) ?> <?= e($c['unidade']) ?></h2>
        <form method="post" action="<?= e(url('contadores/leitura')) ?>"><?= csrf_field() ?><input type="hidden" name="_m" value="1">
          <input type="hidden" name="contador_id" value="<?= $c['id'] ?>"><input type="hidden" name="ativo_id" value="<?= $id ?>">
          <div class="input-group"><input class="form-control" inputmode="decimal" name="valor" placeholder="Nova leitura (<?= e($c['unidade']) ?>)" aria-label="Nova leitura" required><button class="btn btn-primary">Registrar</button></div>
          <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="reset" value="1" id="rs<?= $c['id'] ?>">
            <label class="form-check-label" for="rs<?= $c['id'] ?>">Contador trocado/zerado</label></div>
          <div class="small text-muted mt-1">Última: <?= dt($c['atualizado_em'], true) ?></div>
        </form></div>
    <?php endforeach; ?>

    <?php if ($hist): ?>
      <h2 class="h6 text-muted text-uppercase mt-3">Últimas intervenções</h2>
      <div class="m-lista"><?php foreach ($hist as $h): ?><a href="<?= e(url('m/ot', ['id' => $h['id']])) ?>"><div class="d-flex justify-content-between"><span><?= e($h['titulo']) ?></span><span class="m-sub"><?= dt($h['data_fim']) ?></span></div>
        <div class="m-sub"><?= badge('tipo_ot', $h['tipo']) ?> <?= e($h['causa_falha']) ?></div></a><?php endforeach; ?></div>
    <?php endif;
    m_footer(); exit;
}

/* ---------------- Reportar falha ---------------- */
if ($acao === 'pedido') {
    $ativoId = (int)get('ativo', 0);
    $a = $ativoId ? q1('SELECT id, codigo, nome FROM ativos WHERE id = ?', [$ativoId]) : null;
    m_header('Reportar falha', $a ? url('m/ativo', ['id' => $a['id']]) : url('m')); ?>
    <form method="post" action="<?= e(url('pedidos/salvar')) ?>" class="m-card"><?= csrf_field() ?><input type="hidden" name="_m" value="1">
      <?php if ($a): ?><input type="hidden" name="ativo_id" value="<?= $a['id'] ?>"><div class="mb-3"><div class="small text-muted">Equipamento</div><strong><?= e($a['codigo'] . ' · ' . $a['nome']) ?></strong></div>
      <?php else: ?><div class="mb-3"><label class="form-label">Equipamento</label><?= sel('ativo_id', arvore_opcoes('ativos'), null, true, '', 'Não sei / não está na lista') ?></div>
        <div class="mb-3"><label class="form-label">Local</label><?= sel('localizacao_id', arvore_opcoes('localizacoes'), null) ?></div><?php endif; ?>
      <div class="mb-3"><label class="form-label">O que está acontecendo? *</label><input class="form-control" name="titulo" required maxlength="150"></div>
      <div class="mb-3"><label class="form-label">Detalhes</label><textarea class="form-control" name="descricao" rows="3"></textarea></div>
      <div class="mb-3"><label class="form-label">Urgência</label>
        <div class="btn-group w-100" role="group">
          <?php foreach (['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'] as $k => $r): ?>
            <input type="radio" class="btn-check" name="prioridade" id="pr_<?= $k ?>" value="<?= $k ?>" <?= $k === 'media' ? 'checked' : '' ?>><label class="btn btn-outline-secondary" for="pr_<?= $k ?>"><?= $r ?></label>
          <?php endforeach; ?></div></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" role="switch" name="ativo_parado" value="1" id="parado"><label class="form-check-label" for="parado">Equipamento <strong>parado</strong></label></div>
      <?= turnstile_widget('pedido', true) ?>
      <button class="btn btn-danger btn-m w-100"><i class="bi bi-send"></i> Enviar pedido</button>
    </form>
    <?php m_footer(); exit;
}

/* ---------------- Meus pedidos (solicitante) ---------------- */
if ($acao === 'pedidos') {
    if ($pid = (int)get('id', 0)) {
        $p = q1('SELECT p.*, a.codigo, a.nome AS ativo, o.numero AS ot_numero, o.status AS ot_status FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id LEFT JOIN ordens_trabalho o ON o.id=p.ot_id WHERE p.id = ?', [$pid]);
        if (!$p || (!pode('ver') && (int)$p['solicitante_id'] !== $u['id'])) redirect('m/pedidos');
        m_header($p['numero'], url('m/pedidos'), 'pedidos'); ?>
        <div class="m-card"><div class="d-flex justify-content-between"><h1 class="h5"><?= e($p['titulo']) ?></h1><?= badge('ped_status', $p['status']) ?></div>
          <dl class="m-kv"><dt>Equipamento</dt><dd><?= e(trim($p['codigo'] . ' ' . $p['ativo'])) ?: '—' ?></dd><dt>Aberto em</dt><dd><?= dt($p['criado_em'], true) ?></dd>
            <dt>Urgência</dt><dd><?= badge('prioridade', $p['prioridade']) ?></dd>
            <?php if ($p['ot_numero']): ?><dt>OT</dt><dd><?= e($p['ot_numero']) ?> <?= badge('ot_status', $p['ot_status']) ?></dd><?php endif; ?>
            <?php if ($p['resposta']): ?><dt>Resposta</dt><dd><?= nl2br(e($p['resposta'])) ?></dd><?php endif; ?></dl>
          <?php if ($p['descricao']): ?><p class="small mb-0" style="white-space:pre-line"><?= e($p['descricao']) ?></p><?php endif; ?></div>
        <?php m_footer(); exit;
    }
    $peds = q('SELECT p.*, a.codigo FROM pedidos p LEFT JOIN ativos a ON a.id=p.ativo_id WHERE p.solicitante_id = ? ORDER BY p.id DESC LIMIT 50', [$u['id']]);
    m_header('Meus pedidos', url('m'), 'pedidos'); ?>
    <a href="<?= e(url('m/pedido')) ?>" class="btn btn-danger btn-m w-100 mb-3"><i class="bi bi-plus-lg"></i> Novo pedido</a>
    <div class="m-lista"><?php foreach ($peds as $p): ?><a href="<?= e(url('m/pedidos', ['id' => $p['id']])) ?>"><div class="d-flex justify-content-between"><strong><?= e($p['titulo']) ?></strong><?= badge('ped_status', $p['status']) ?></div>
      <div class="m-sub"><?= e($p['numero']) ?> · <?= e($p['codigo']) ?> · <?= dt($p['criado_em'], true) ?></div></a><?php endforeach; ?>
      <?php if (!$peds): ?><div class="m-item text-muted">Nenhum pedido.</div><?php endif; ?></div>
    <?php m_footer(); exit;
}

/* ===== daqui para baixo: equipe de manutenção ===== */
exigir('ver');

/* ---------------- Lista de OTs ---------------- */
if ($acao === 'ots') {
    $f = get('f', 'minhas');
    $w = ["o.status IN (" . STATUS_PENDENTES . ")"]; $prm = [];
    if ($f === 'minhas') { $w[] = 'o.responsavel_id = ?'; $prm[] = (int)$u['tecnico_id']; }
    if ($f === 'sem') $w[] = 'o.responsavel_id IS NULL';
    if ($f === 'atrasadas') $w[] = 'o.data_prevista < CURDATE()';
    $ots = q('SELECT o.*, a.codigo FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id WHERE ' . implode(' AND ', $w)
           . " ORDER BY FIELD(o.prioridade,'urgente','alta','media','baixa'), o.data_prevista IS NULL, o.data_prevista LIMIT 100", $prm);
    m_header('Ordens de trabalho', url('m'), 'ots'); ?>
    <div class="btn-group w-100 mb-3">
      <?php foreach (['minhas' => 'Minhas', 'atrasadas' => 'Atrasadas', 'sem' => 'Sem resp.', 'todas' => 'Todas'] as $k => $r): ?>
        <a class="btn btn-sm btn-outline-dark <?= $f === $k ? 'active' : '' ?>" href="<?= e(url('m/ots', ['f' => $k])) ?>"><?= $r ?></a><?php endforeach; ?></div>
    <div class="m-lista"><?php foreach ($ots as $o) echo m_item_ot($o); if (!$ots): ?><div class="m-item text-muted">Nada por aqui.</div><?php endif; ?></div>
    <?php m_footer(); exit;
}

/* ---------------- Nova OT rápida ---------------- */
if ($acao === 'nova_ot') {
    exigir('executar');
    $a = q1('SELECT id, codigo, nome FROM ativos WHERE id = ?', [(int)get('ativo')]);
    m_header('Abrir OT', $a ? url('m/ativo', ['id' => $a['id']]) : url('m')); ?>
    <form method="post" action="<?= e(url('ots/salvar')) ?>" class="m-card"><?= csrf_field() ?><input type="hidden" name="_m" value="1">
      <?php if ($a): ?><input type="hidden" name="ativo_id" value="<?= $a['id'] ?>"><div class="mb-3"><div class="small text-muted">Equipamento</div><strong><?= e($a['codigo'] . ' · ' . $a['nome']) ?></strong></div>
      <?php else: ?><div class="mb-3"><label class="form-label">Equipamento</label><?= sel('ativo_id', arvore_opcoes('ativos'), null) ?></div><?php endif; ?>
      <div class="mb-3"><label class="form-label">Tipo</label><?= sel('tipo', lista_rotulos('tipo_ot'), 'corretiva', false) ?></div>
      <div class="mb-3"><label class="form-label">Título *</label><input class="form-control" name="titulo" required maxlength="150"></div>
      <div class="mb-3"><label class="form-label">Descrição</label><textarea class="form-control" name="descricao" rows="3"></textarea></div>
      <?php if (pode('gerir')): ?><div class="mb-3"><label class="form-label">Prioridade</label><?= sel('prioridade', lista_rotulos('prioridade'), 'media', false) ?></div>
        <div class="mb-3"><label class="form-label">Responsável</label><?= sel('responsavel_id', opcoes('SELECT id, nome FROM tecnicos WHERE ativo=1 ORDER BY nome'), $u['tecnico_id']) ?></div><?php endif; ?>
      <input type="hidden" name="data_prevista" value="<?= date('Y-m-d') ?>">
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="ativo_parado" value="1" id="ap"><label class="form-check-label" for="ap">Equipamento parado</label></div>
      <button class="btn btn-dark btn-m w-100">Criar OT</button>
    </form>
    <?php m_footer(); exit;
}

/* ---------------- Execução da OT ---------------- */
if ($acao === 'ot') {
    $o = q1('SELECT o.*, a.codigo, a.nome AS ativo_nome, t.nome AS responsavel, pl.gatilho AS plano_gatilho
             FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id LEFT JOIN tecnicos t ON t.id=o.responsavel_id
             LEFT JOIN planos_manutencao pl ON pl.id=o.plano_id WHERE o.id = ?', [(int)get('id')]);
    if (!$o) redirect('m/ots');
    $id = (int)$o['id'];
    $aberta = ot_aberta($o);
    $exec = ot_pode_executar($o);
    $tar = q('SELECT * FROM ot_tarefas WHERE ot_id = ? ORDER BY ordem, id', [$id]);
    $mo = q('SELECT m.*, t.nome FROM ot_mao_obra m JOIN tecnicos t ON t.id=m.tecnico_id WHERE m.ot_id = ? ORDER BY m.id', [$id]);
    $mat = q('SELECT m.*, a.codigo, a.descricao, a.unidade FROM ot_materiais m JOIN artigos a ON a.id=m.artigo_id WHERE m.ot_id = ?', [$id]);
    $horas = array_sum(array_column($mo, 'horas'));
    $hid = fn() => csrf_field() . '<input type="hidden" name="_m" value="1"><input type="hidden" name="id" value="' . $id . '">';
    m_header($o['numero'], $o['ativo_id'] ? url('m/ativo', ['id' => $o['ativo_id']]) : url('m/ots'), 'ots'); ?>
    <?php if ($aberta && !$exec): ?><div class="alert alert-secondary py-2 small"><i class="bi bi-lock"></i> Atribuída a <strong><?= e($o['responsavel']) ?></strong> — somente leitura.</div><?php endif; ?>
    <div class="m-card">
      <div class="small mb-1"><?= badge('tipo_ot', $o['tipo']) ?> <?= badge('prioridade', $o['prioridade']) ?> <?= badge('ot_status', $o['status']) ?></div>
      <h1 class="h5"><?= e($o['titulo']) ?></h1>
      <dl class="m-kv mb-2"><dt>Equip.</dt><dd><?= $o['ativo_id'] ? '<a href="' . e(url('m/ativo', ['id' => $o['ativo_id']])) . '">' . e($o['codigo'] . ' · ' . $o['ativo_nome']) . '</a>' : '—' ?></dd>
        <dt>Prevista</dt><dd><?= dt($o['data_prevista']) ?: '—' ?></dd><dt>Resp.</dt><dd><?= e($o['responsavel'] ?: '—') ?></dd>
        <?php if ($o['data_inicio']): ?><dt>Início</dt><dd><?= dt($o['data_inicio'], true) ?></dd><?php endif; ?>
        <?php if ($o['data_fim']): ?><dt>Término</dt><dd><?= dt($o['data_fim'], true) ?></dd><?php endif; ?></dl>
      <?php if ($o['descricao']): ?><div class="small" style="white-space:pre-line"><?= e($o['descricao']) ?></div><?php endif; ?>
      <?php if ($o['solucao']): ?><div class="small mt-2"><strong>Realizado:</strong> <?= nl2br(e($o['solucao'])) ?></div><?php endif; ?>
    </div>

    <?php if ($exec): ?>
      <form method="post" action="<?= e(url('ots/status')) ?>" class="mb-3"><?= $hid() ?>
        <?php if (in_array($o['status'], ['aberta', 'planejada'], true)): ?><button class="btn btn-primary btn-m w-100" name="novo" value="iniciar"><i class="bi bi-play-fill"></i> Iniciar trabalho</button>
        <?php elseif ($o['status'] === 'em_execucao'): ?><button class="btn btn-outline-warning w-100" name="novo" value="aguardar"><i class="bi bi-pause-fill"></i> Aguardando peças</button>
        <?php elseif ($o['status'] === 'aguardando_pecas'): ?><button class="btn btn-primary btn-m w-100" name="novo" value="retomar"><i class="bi bi-play-fill"></i> Retomar</button><?php endif; ?>
      </form>
    <?php endif; ?>

    <?php if ($tar): ?>
    <div class="m-card" data-checklist><h2 data-checklist-titulo>Checklist (<?= count(array_filter($tar, fn($t) => $t['concluida'])) ?>/<?= count($tar) ?>)</h2>
      <?php foreach ($tar as $t): ?><div class="m-check"><input class="form-check-input" type="checkbox" id="t<?= $t['id'] ?>" data-tarefa="<?= $t['id'] ?>" data-url="<?= e(url('ots/tarefa')) ?>" <?= $t['concluida'] ? 'checked' : '' ?> <?= $exec ? '' : 'disabled' ?>>
        <label for="t<?= $t['id'] ?>"><?= e($t['descricao']) ?></label></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="m-card"><h2>Horas apontadas: <?= num($horas, 1) ?> h</h2>
      <?php foreach ($mo as $m): ?><div class="small d-flex justify-content-between"><span><?= dt($m['data']) ?> · <?= e($m['nome']) ?></span><span><?= num($m['horas'], 1) ?> h</span></div><?php endforeach; ?>
      <?php if ($exec && ($u['tecnico_id'] || pode('gerir'))): ?>
        <form method="post" action="<?= e(url('ots/mo_add')) ?>" class="mt-2"><?= $hid() ?>
          <input type="hidden" name="tecnico_id" value="<?= (int)($u['tecnico_id'] ?: $o['responsavel_id']) ?>"><input type="hidden" name="data" value="<?= date('Y-m-d') ?>">
          <div class="d-flex gap-2 mb-2"><?php foreach (['0,5', '1', '2', '4'] as $h): ?><button class="btn btn-outline-dark flex-fill" name="horas" value="<?= $h ?>">+<?= $h ?> h</button><?php endforeach; ?></div>
        </form>
        <form method="post" action="<?= e(url('ots/mo_add')) ?>"><?= $hid() ?>
          <input type="hidden" name="tecnico_id" value="<?= (int)($u['tecnico_id'] ?: $o['responsavel_id']) ?>"><input type="hidden" name="data" value="<?= date('Y-m-d') ?>">
          <div class="input-group input-group-sm"><input class="form-control" inputmode="decimal" name="horas" placeholder="Outra quantidade (h)" required><button class="btn btn-outline-secondary">Apontar</button></div></form>
      <?php endif; ?></div>

    <div class="m-card"><h2>Materiais</h2>
      <?php foreach ($mat as $m): ?><div class="small d-flex justify-content-between"><span><?= e($m['codigo']) ?> · <?= e($m['descricao']) ?></span><span><?= num($m['quantidade'], 2) ?> <?= e($m['unidade']) ?> <?= $m['baixado'] ? '' : '<span class="badge text-bg-warning">reservado</span>' ?></span></div><?php endforeach; ?>
      <?php if (!$mat): ?><div class="small text-muted">Nenhum material.</div><?php endif; ?>
      <?php if ($exec): ?>
        <form method="post" action="<?= e(url('ots/mat_add')) ?>" class="mt-2"><?= $hid() ?>
          <?= sel('artigo_id', opcoes("SELECT id, CONCAT(codigo,' · ',descricao,' (',FORMAT(estoque_atual,1,'pt_BR'),' ',unidade,')') FROM artigos WHERE ativo=1 AND estoque_atual > 0 ORDER BY codigo"), null, true, 'required class="form-select mb-2"', 'Selecione a peça') ?>
          <div class="input-group"><input class="form-control" inputmode="decimal" name="quantidade" placeholder="Quantidade" required><button class="btn btn-outline-dark">Requisitar</button></div></form>
      <?php endif; ?></div>

    <?php if ($exec): ?>
    <form method="post" action="<?= e(url('ots/status')) ?>" class="m-card border border-success" id="concluir"><?= $hid() ?><input type="hidden" name="novo" value="concluir">
      <h2 class="text-success">Concluir OT</h2>
      <?php if ($o['tipo'] === 'corretiva'): ?><div class="mb-2"><label class="form-label small">Causa da falha</label><input class="form-control" name="causa_falha" list="causas" value="<?= e(old('causa_falha', $o['causa_falha'])) ?>">
        <datalist id="causas"><?php foreach (q('SELECT DISTINCT causa_falha FROM ordens_trabalho WHERE causa_falha IS NOT NULL ORDER BY causa_falha LIMIT 40') as $c): ?><option value="<?= e($c['causa_falha']) ?>"><?php endforeach; ?></datalist></div><?php endif; ?>
      <?php if ($o['plano_gatilho'] === 'contador'): ?><div class="mb-2"><label class="form-label small">Leitura do contador</label><input class="form-control" inputmode="decimal" name="leitura_contador" value="<?= e((string)old('leitura_contador', '')) ?>"></div><?php endif; ?>
      <div class="mb-2"><label class="form-label small">O que foi feito</label><textarea class="form-control" name="solucao" rows="3"><?= e(old('solucao', $o['solucao'] ?? '')) ?></textarea></div>
      <input type="hidden" name="data_inicio" value="<?= e(input_dt($o['data_inicio'] ?: date('Y-m-d H:i'))) ?>">
      <?php if ($tar && count(array_filter($tar, fn($t) => !$t['concluida']))): ?>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="ignorar_tarefas" value="1" id="ig" <?= old('ignorar_tarefas') ? 'checked' : '' ?>><label class="form-check-label small" for="ig">Concluir com tarefas pendentes</label></div><?php endif; ?>
      <button class="btn btn-success btn-m w-100"><i class="bi bi-check2-all"></i> Concluir agora</button>
    </form>
    <?php endif; ?>
    <a class="btn btn-link w-100" href="<?= e(url('ots/ver', ['id' => $id])) ?>">Abrir na versão completa</a>
    <?php
    m_footer(); exit;
}

redirect('m');
