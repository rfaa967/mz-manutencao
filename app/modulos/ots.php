<?php
exigir('ver');
$u = usuario();
$tecnicos = opcoes('SELECT id, nome FROM tecnicos WHERE ativo = 1 ORDER BY nome');

function ot_carregar(int $id): ?array
{
    return q1('SELECT o.*, a.codigo AS ativo_codigo, a.nome AS ativo_nome, a.localizacao_id, t.nome AS responsavel,
                 p.numero AS pedido_numero, p.criado_em AS pedido_em, pl.titulo AS plano_titulo, pl.gatilho AS plano_gatilho, pl.contador_id
               FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id LEFT JOIN tecnicos t ON t.id=o.responsavel_id
               LEFT JOIN pedidos p ON p.id=o.pedido_id LEFT JOIN planos_manutencao pl ON pl.id=o.plano_id WHERE o.id = ?', [$id]);
}
function ot_aberta(array $o): bool { return !in_array($o['status'], ['concluida', 'cancelada'], true); }
function ot_voltar(int $id, string $ancora = ''): never { header('Location: ' . url('ots/ver', ['id' => $id]) . ($ancora ? "#$ancora" : '')); exit; }

function atualizar_status_ativo(?int $ativoId): void
{
    if (!$ativoId) return;
    $st = qv('SELECT status FROM ativos WHERE id = ?', [$ativoId]);
    if ($st === 'desativado') return;
    $emExec = (int)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE ativo_id=? AND ativo_parado=1 AND status IN ('em_execucao','aguardando_pecas')", [$ativoId]);
    $parado = (int)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE ativo_id=? AND ativo_parado=1 AND status IN ('aberta','planejada')", [$ativoId]);
    $novo = $emExec ? 'em_manutencao' : ($parado ? 'parado' : 'operacional');
    qx('UPDATE ativos SET status = ? WHERE id = ?', [$novo, $ativoId]);
}

/* ================= SALVAR CABEÇALHO ================= */
if ($acao === 'salvar' && is_post()) {
    exigir('executar');
    $id = post_int('id');
    $dados = [
        'ativo_id' => post_int('ativo_id'),
        'tipo' => array_key_exists((string)post('tipo'), ROTULOS['tipo_ot']) ? post('tipo') : 'corretiva',
        'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : 'media',
        'titulo' => (string)post('titulo'), 'descricao' => post('descricao') ?: null,
        'responsavel_id' => post_int('responsavel_id'), 'data_prevista' => post_date('data_prevista'),
        'ativo_parado' => post('ativo_parado') ? 1 : 0, 'custo_externo' => post_dec('custo_externo') ?? 0,
    ];
    if ($dados['titulo'] === '') { flash('Informe o título.', 'danger'); redirect('ots/form', $id ? ['id' => $id] : []); }
    if ($id) {
        $o = ot_carregar($id);
        if (!$o || !ot_aberta($o)) { flash('OT encerrada não pode ser editada.', 'warning'); redirect('ots/ver', ['id' => $id]); }
        if (!pode('gerir')) unset($dados['responsavel_id'], $dados['prioridade']);
        if ($o['status'] === 'aberta' && !empty($dados['responsavel_id']) && $dados['data_prevista']) $dados['status'] = 'planejada';
        update('ordens_trabalho', $dados, $id);
        auditar('editar', 'ordens_trabalho', $id);
    } else {
        if (!pode('gerir')) { $dados['responsavel_id'] = $u['tecnico_id']; $dados['prioridade'] = 'media'; }
        $dados += ['numero' => proximo_numero('ordens_trabalho', 'OT'), 'criado_por' => $u['id'],
                   'status' => ($dados['responsavel_id'] && $dados['data_prevista']) ? 'planejada' : 'aberta'];
        $id = insert('ordens_trabalho', $dados);
        auditar('criar', 'ordens_trabalho', $id);
    }
    atualizar_status_ativo($dados['ativo_id']);
    flash('Ordem de trabalho salva.');
    redirect('ots/ver', ['id' => $id]);
}

/* ================= TRANSIÇÕES DE STATUS ================= */
if ($acao === 'status' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    if (!$o) redirect('ots');
    $novo = (string)post('novo');
    $agora = date('Y-m-d H:i:s');

    if (!ot_aberta($o) && !($novo === 'reabrir' && pode('gerir'))) { flash('OT já encerrada.', 'warning'); ot_voltar((int)$o['id']); }

    switch ($novo) {
        case 'iniciar':
            update('ordens_trabalho', ['status' => 'em_execucao', 'data_inicio' => $o['data_inicio'] ?: $agora], (int)$o['id']);
            break;
        case 'aguardar':
            update('ordens_trabalho', ['status' => 'aguardando_pecas'], (int)$o['id']);
            break;
        case 'retomar':
            update('ordens_trabalho', ['status' => 'em_execucao'], (int)$o['id']);
            break;
        case 'cancelar':
            exigir('gerir');
            // devolve ao estoque materiais já baixados
            db()->beginTransaction();
            foreach (q('SELECT * FROM ot_materiais WHERE ot_id = ? AND baixado = 1', [$o['id']]) as $m) {
                movimentar_estoque((int)$m['artigo_id'], 'entrada', (float)$m['quantidade'], (float)$m['custo_unitario'], (int)$o['id'], $o['numero'], 'Estorno — OT cancelada');
                qx('UPDATE ot_materiais SET baixado = 0 WHERE id = ?', [$m['id']]);
            }
            update('ordens_trabalho', ['status' => 'cancelada', 'solucao' => trim(($o['solucao'] ?? '') . "\n[Cancelada] " . post('motivo'))], (int)$o['id']);
            db()->commit();
            break;
        case 'reabrir':
            update('ordens_trabalho', ['status' => 'em_execucao', 'data_fim' => null], (int)$o['id']);
            break;
        case 'concluir':
            $ini = post_datetime('data_inicio') ?? ($o['data_inicio'] ?: $agora);
            $fim = post_datetime('data_fim') ?? $agora;
            if ($fim < $ini) { flash('A data de término não pode ser anterior ao início.', 'danger'); ot_voltar((int)$o['id'], 'concluir'); }
            $pendTarefas = (int)qv('SELECT COUNT(*) FROM ot_tarefas WHERE ot_id = ? AND concluida = 0', [$o['id']]);
            if ($pendTarefas && !post('ignorar_tarefas')) { flash("Há $pendTarefas tarefa(s) do checklist não marcadas. Marque-as ou confirme a conclusão mesmo assim.", 'warning'); ot_voltar((int)$o['id'], 'concluir'); }
            $leitura = post_dec('leitura_contador');
            db()->beginTransaction();
            try {
                // baixa materiais pendentes
                foreach (q('SELECT * FROM ot_materiais WHERE ot_id = ? AND baixado = 0', [$o['id']]) as $m) {
                    $custo = movimentar_estoque((int)$m['artigo_id'], 'saida', (float)$m['quantidade'], null, (int)$o['id'], $o['numero'], 'Consumo OT');
                    qx('UPDATE ot_materiais SET baixado = 1, custo_unitario = ? WHERE id = ?', [$custo, $m['id']]);
                }
                $horasParada = post_dec('horas_parada');
                if ($horasParada === null && $o['ativo_parado']) {
                    $base = $o['pedido_em'] ?: $ini;
                    $horasParada = round((strtotime($fim) - strtotime($base)) / 3600, 2);
                }
                update('ordens_trabalho', [
                    'status' => 'concluida', 'data_inicio' => $ini, 'data_fim' => $fim,
                    'horas_parada' => $horasParada ?? 0, 'causa_falha' => post('causa_falha') ?: null,
                    'solucao' => post('solucao') ?: null, 'custo_externo' => post_dec('custo_externo') ?? $o['custo_externo'],
                    'leitura_contador' => $leitura,
                ], (int)$o['id']);
                // registra leitura do contador do plano
                if ($leitura !== null && $o['contador_id']) {
                    insert('leituras_contador', ['contador_id' => $o['contador_id'], 'valor' => $leitura, 'data_leitura' => $fim, 'usuario_id' => $u['id'], 'observacao' => 'Leitura na conclusão da ' . $o['numero']]);
                    qx('UPDATE contadores SET valor_atual = GREATEST(valor_atual, ?), atualizado_em = ? WHERE id = ?', [$leitura, $fim, $o['contador_id']]);
                }
                db()->commit();
            } catch (RuntimeException $ex) {
                db()->rollBack();
                flash('Não foi possível concluir: ' . $ex->getMessage() . ' Registre a entrada no estoque ou remova o material.', 'danger');
                ot_voltar((int)$o['id'], 'materiais');
            }
            reprogramar_plano_apos_ot((int)$o['id']);
            break;
        default:
            ot_voltar((int)$o['id']);
    }
    atualizar_status_ativo($o['ativo_id'] ? (int)$o['ativo_id'] : null);
    auditar('status_' . $novo, 'ordens_trabalho', (int)$o['id']);
    flash('Status atualizado.');
    ot_voltar((int)$o['id']);
}

/* ================= MÃO DE OBRA ================= */
if ($acao === 'mo_add' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    $tec = q1('SELECT * FROM tecnicos WHERE id = ?', [post_int('tecnico_id')]);
    $horas = post_dec('horas');
    if ($o && ot_aberta($o) && $tec && $horas > 0) {
        insert('ot_mao_obra', ['ot_id' => $o['id'], 'tecnico_id' => $tec['id'], 'data' => post_date('data') ?? date('Y-m-d'), 'horas' => $horas, 'custo_hora' => $tec['custo_hora'], 'observacao' => post('observacao') ?: null]);
        if ($o['status'] === 'aberta' || $o['status'] === 'planejada') update('ordens_trabalho', ['status' => 'em_execucao', 'data_inicio' => $o['data_inicio'] ?: date('Y-m-d H:i:s')], (int)$o['id']);
        atualizar_status_ativo($o['ativo_id'] ? (int)$o['ativo_id'] : null);
    } else flash('Dados de mão de obra inválidos.', 'danger');
    ot_voltar((int)post_int('id'), 'maoobra');
}
if ($acao === 'mo_del' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    if ($o && ot_aberta($o)) qx('DELETE FROM ot_mao_obra WHERE id = ? AND ot_id = ?', [post_int('item_id'), $o['id']]);
    ot_voltar((int)post_int('id'), 'maoobra');
}

/* ================= MATERIAIS ================= */
if ($acao === 'mat_add' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    $art = q1('SELECT * FROM artigos WHERE id = ?', [post_int('artigo_id')]);
    $qtd = post_dec('quantidade');
    if ($o && ot_aberta($o) && $art && $qtd > 0) {
        db()->beginTransaction();
        try {
            $custo = movimentar_estoque((int)$art['id'], 'saida', $qtd, null, (int)$o['id'], $o['numero'], 'Requisição para OT');
            insert('ot_materiais', ['ot_id' => $o['id'], 'artigo_id' => $art['id'], 'quantidade' => $qtd, 'custo_unitario' => $custo, 'baixado' => 1]);
            db()->commit();
        } catch (RuntimeException $ex) {
            db()->rollBack();
            flash($ex->getMessage(), 'danger');
        }
    } else flash('Dados de material inválidos.', 'danger');
    ot_voltar((int)post_int('id'), 'materiais');
}
if ($acao === 'mat_del' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    $m = q1('SELECT * FROM ot_materiais WHERE id = ? AND ot_id = ?', [post_int('item_id'), post_int('id')]);
    if ($o && $m && ot_aberta($o)) {
        db()->beginTransaction();
        if ($m['baixado']) movimentar_estoque((int)$m['artigo_id'], 'entrada', (float)$m['quantidade'], (float)$m['custo_unitario'], (int)$o['id'], $o['numero'], 'Devolução da OT');
        qx('DELETE FROM ot_materiais WHERE id = ?', [$m['id']]);
        db()->commit();
    }
    ot_voltar((int)post_int('id'), 'materiais');
}
if ($acao === 'mat_baixar' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    $m = q1('SELECT * FROM ot_materiais WHERE id = ? AND ot_id = ? AND baixado = 0', [post_int('item_id'), post_int('id')]);
    if ($o && $m && ot_aberta($o)) {
        db()->beginTransaction();
        try {
            $custo = movimentar_estoque((int)$m['artigo_id'], 'saida', (float)$m['quantidade'], null, (int)$o['id'], $o['numero'], 'Requisição para OT');
            qx('UPDATE ot_materiais SET baixado = 1, custo_unitario = ? WHERE id = ?', [$custo, $m['id']]);
            db()->commit();
        } catch (RuntimeException $ex) { db()->rollBack(); flash($ex->getMessage(), 'danger'); }
    }
    ot_voltar((int)post_int('id'), 'materiais');
}

/* ================= CHECKLIST ================= */
if ($acao === 'tarefa' && is_post()) {
    exigir('executar');
    $t = q1('SELECT t.*, o.status FROM ot_tarefas t JOIN ordens_trabalho o ON o.id=t.ot_id WHERE t.id = ?', [post_int('tarefa_id')]);
    if (!$t || in_array($t['status'], ['concluida', 'cancelada'], true)) { http_response_code(409); json_out(['ok' => false]); }
    qx('UPDATE ot_tarefas SET concluida = ? WHERE id = ?', [post('concluida') ? 1 : 0, $t['id']]);
    json_out(['ok' => true]);
}
if ($acao === 'tarefa_add' && is_post()) {
    exigir('executar');
    $o = ot_carregar((int)post_int('id'));
    if ($o && ot_aberta($o) && post('descricao')) {
        insert('ot_tarefas', ['ot_id' => $o['id'], 'descricao' => post('descricao'), 'ordem' => (int)qv('SELECT COALESCE(MAX(ordem),0)+1 FROM ot_tarefas WHERE ot_id=?', [$o['id']])]);
    }
    ot_voltar((int)post_int('id'), 'checklist');
}

/* ================= FORMULÁRIO ================= */
if ($acao === 'form') {
    exigir('executar');
    $id = (int)get('id', 0);
    $o = $id ? ot_carregar($id) : ['tipo' => 'corretiva', 'prioridade' => 'media', 'ativo_id' => get('ativo'), 'data_prevista' => date('Y-m-d'), 'custo_externo' => 0, 'responsavel_id' => $u['tecnico_id']];
    if ($id && (!$o || !ot_aberta($o))) redirect('ots/ver', ['id' => $id]);
    view_header($id ? 'Editar OT' : 'Nova OT', 'ots'); ?>
    <div class="page-title"><h1><?= $id ? 'Editar ' . e($o['numero']) : 'Nova ordem de trabalho' ?></h1>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e($id ? url('ots/ver', ['id' => $id]) : url('ots')) ?>">Voltar</a></div>
    <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('ots/salvar')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?: '' ?>">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Ativo</label><?= sel('ativo_id', arvore_opcoes('ativos'), $o['ativo_id'] ?? null) ?></div>
        <div class="col-md-3"><label class="form-label">Tipo</label><?= sel('tipo', lista_rotulos('tipo_ot'), $o['tipo'], false) ?></div>
        <div class="col-md-3"><label class="form-label">Prioridade</label><?= sel('prioridade', lista_rotulos('prioridade'), $o['prioridade'], false, pode('gerir') ? '' : 'disabled') ?></div>
        <div class="col-12"><label class="form-label">Título *</label><input class="form-control" name="titulo" required maxlength="150" value="<?= e($o['titulo'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Descrição do trabalho</label><textarea class="form-control" name="descricao" rows="4"><?= e($o['descricao'] ?? '') ?></textarea></div>
        <div class="col-md-4"><label class="form-label">Responsável</label><?= sel('responsavel_id', $tecnicos, $o['responsavel_id'] ?? null, true, pode('gerir') ? '' : 'disabled') ?></div>
        <div class="col-md-3"><label class="form-label">Data prevista</label><input type="date" class="form-control" name="data_prevista" value="<?= e($o['data_prevista'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Custo externo (R$)</label><input class="form-control" inputmode="decimal" name="custo_externo" value="<?= num($o['custo_externo'] ?? 0) ?>"></div>
        <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="ativo_parado" value="1" id="ap" <?= !empty($o['ativo_parado']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="ap">Ativo parado (conta indisponibilidade)</label></div></div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> Salvar</button>
    </form></div></div>
    <?php view_footer(); exit;
}

/* ================= DETALHE / IMPRESSÃO ================= */
if ($acao === 'ver' || $acao === 'imprimir') {
    $o = ot_carregar((int)get('id'));
    if (!$o) redirect('ots');
    $mo = q('SELECT m.*, t.nome FROM ot_mao_obra m JOIN tecnicos t ON t.id=m.tecnico_id WHERE m.ot_id = ? ORDER BY m.data, m.id', [$o['id']]);
    $mat = q('SELECT m.*, a.codigo, a.descricao, a.unidade, a.estoque_atual FROM ot_materiais m JOIN artigos a ON a.id=m.artigo_id WHERE m.ot_id = ?', [$o['id']]);
    $tar = q('SELECT * FROM ot_tarefas WHERE ot_id = ? ORDER BY ordem, id', [$o['id']]);
    $custoMO = array_sum(array_map(fn($x) => $x['horas'] * $x['custo_hora'], $mo));
    $custoMat = array_sum(array_map(fn($x) => $x['quantidade'] * $x['custo_unitario'], $mat));
    $horasMO = array_sum(array_column($mo, 'horas'));
    $aberta = ot_aberta($o);
    $exec = pode('executar') && $aberta;
    $atrasada = $aberta && $o['data_prevista'] && $o['data_prevista'] < date('Y-m-d');

    view_header($o['numero'], 'ots');
    if ($acao === 'imprimir') $GLOBALS['__scripts'] = '<script>window.print()</script>'; ?>
    <div class="page-title">
      <div><div class="small text-muted"><?= badge('tipo_ot', $o['tipo']) ?> <?= badge('prioridade', $o['prioridade']) ?> <?= $atrasada ? '<span class="badge text-bg-danger">ATRASADA</span>' : '' ?></div>
        <h1><?= e($o['numero']) ?> — <?= e($o['titulo']) ?> <?= badge('ot_status', $o['status']) ?></h1></div>
      <div class="d-flex gap-2 no-print flex-wrap">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('ots/imprimir', ['id' => $o['id']])) ?>" target="_blank"><i class="bi bi-printer"></i> Imprimir</a>
        <?php if ($exec): ?><a class="btn btn-outline-primary btn-sm" href="<?= e(url('ots/form', ['id' => $o['id']])) ?>"><i class="bi bi-pencil"></i> Editar</a><?php endif; ?>
        <?php if ($exec): ?>
          <form method="post" action="<?= e(url('ots/status')) ?>" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <?php if (in_array($o['status'], ['aberta', 'planejada'])): ?><button class="btn btn-primary btn-sm" name="novo" value="iniciar"><i class="bi bi-play-fill"></i> Iniciar</button><?php endif; ?>
            <?php if ($o['status'] === 'em_execucao'): ?><button class="btn btn-warning btn-sm" name="novo" value="aguardar"><i class="bi bi-pause-fill"></i> Aguardar peças</button><?php endif; ?>
            <?php if ($o['status'] === 'aguardando_pecas'): ?><button class="btn btn-primary btn-sm" name="novo" value="retomar"><i class="bi bi-play-fill"></i> Retomar</button><?php endif; ?>
          </form>
          <a class="btn btn-success btn-sm" href="#concluir"><i class="bi bi-check2-all"></i> Concluir</a>
        <?php endif; ?>
        <?php if (!$aberta && pode('gerir')): ?>
          <form method="post" action="<?= e(url('ots/status')) ?>" data-confirm="Reabrir esta OT?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <button class="btn btn-outline-warning btn-sm" name="novo" value="reabrir">Reabrir</button></form>
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body">
          <div class="row small">
            <div class="col-md-6">
              <div><span class="text-muted">Ativo:</span> <?= $o['ativo_id'] ? '<a href="' . e(url('ativos/ver', ['id' => $o['ativo_id']])) . '">' . e($o['ativo_codigo'] . ' · ' . $o['ativo_nome']) . '</a>' : '—' ?>
                <?= $o['ativo_parado'] ? '<span class="badge text-bg-danger">parado</span>' : '' ?></div>
              <div><span class="text-muted">Local:</span> <?= e(caminho_localizacao($o['localizacao_id'] ? (int)$o['localizacao_id'] : null)) ?></div>
              <div><span class="text-muted">Responsável:</span> <?= e($o['responsavel'] ?: '—') ?></div>
              <?php if ($o['pedido_id']): ?><div><span class="text-muted">Origem:</span> <a href="<?= e(url('pedidos/ver', ['id' => $o['pedido_id']])) ?>">Pedido <?= e($o['pedido_numero']) ?></a></div><?php endif; ?>
              <?php if ($o['plano_id']): ?><div><span class="text-muted">Plano:</span> <a href="<?= e(url('planos/form', ['id' => $o['plano_id']])) ?>"><?= e($o['plano_titulo']) ?></a></div><?php endif; ?>
            </div>
            <div class="col-md-6">
              <div><span class="text-muted">Abertura:</span> <?= dt($o['data_abertura'], true) ?></div>
              <div><span class="text-muted">Prevista:</span> <?= dt($o['data_prevista']) ?></div>
              <div><span class="text-muted">Início:</span> <?= dt($o['data_inicio'], true) ?: '—' ?></div>
              <div><span class="text-muted">Término:</span> <?= dt($o['data_fim'], true) ?: '—' ?></div>
              <?php if (!$aberta): ?><div><span class="text-muted">Horas de parada:</span> <?= num($o['horas_parada'], 1) ?> h</div><?php endif; ?>
            </div>
          </div>
          <?php if ($o['descricao']): ?><hr><div style="white-space:pre-line"><?= e($o['descricao']) ?></div><?php endif; ?>
          <?php if ($o['causa_falha'] || $o['solucao']): ?><hr>
            <?php if ($o['causa_falha']): ?><div><strong>Causa:</strong> <?= e($o['causa_falha']) ?></div><?php endif; ?>
            <?php if ($o['solucao']): ?><div style="white-space:pre-line"><strong>Trabalho realizado:</strong> <?= e($o['solucao']) ?></div><?php endif; ?>
          <?php endif; ?>
        </div></div>

        <div class="card mb-3" id="checklist"><div class="card-header bg-white fw-semibold">Checklist de tarefas</div><div class="card-body checklist">
          <?php foreach ($tar as $t): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" id="t<?= $t['id'] ?>" data-tarefa="<?= $t['id'] ?>" data-url="<?= e(url('ots/tarefa')) ?>" <?= $t['concluida'] ? 'checked' : '' ?> <?= $exec ? '' : 'disabled' ?>>
              <label class="form-check-label" for="t<?= $t['id'] ?>"><?= e($t['descricao']) ?></label></div>
          <?php endforeach; if (!$tar): ?><div class="text-muted small">Sem tarefas.</div><?php endif; ?>
          <?php if ($exec): ?>
            <form method="post" action="<?= e(url('ots/tarefa_add')) ?>" class="input-group input-group-sm mt-2 no-print"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
              <input class="form-control" name="descricao" placeholder="Adicionar tarefa…"><button class="btn btn-outline-secondary">+</button></form>
          <?php endif; ?>
        </div></div>

        <div class="card mb-3" id="maoobra"><div class="card-header bg-white fw-semibold">Mão de obra</div><div class="card-body">
          <table class="table table-sm"><thead><tr><th>Data</th><th>Técnico</th><th>Horas</th><th class="text-end">Custo</th><th>Obs.</th><th></th></tr></thead><tbody>
          <?php foreach ($mo as $m): ?><tr><td><?= dt($m['data']) ?></td><td><?= e($m['nome']) ?></td><td><?= num($m['horas'], 2) ?></td><td class="text-end"><?= moeda($m['horas'] * $m['custo_hora']) ?></td><td><?= e($m['observacao']) ?></td>
            <td class="text-end"><?php if ($exec): ?><form method="post" action="<?= e(url('ots/mo_del')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="item_id" value="<?= $m['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0 no-print"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-semibold"><td colspan="2">Total</td><td><?= num($horasMO, 2) ?> h</td><td class="text-end"><?= moeda($custoMO) ?></td><td colspan="2"></td></tr>
          </tbody></table>
          <?php if ($exec): ?>
          <form method="post" action="<?= e(url('ots/mo_add')) ?>" class="row g-2 no-print"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <div class="col-md-3"><input type="date" class="form-control form-control-sm" name="data" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><?= sel('tecnico_id', $tecnicos, $u['tecnico_id'] ?? $o['responsavel_id'], true, 'required class="form-select form-select-sm"') ?></div>
            <div class="col-md-2"><input class="form-control form-control-sm" inputmode="decimal" name="horas" placeholder="Horas" required></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="observacao" placeholder="Observação"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">+</button></div>
          </form>
          <?php endif; ?>
        </div></div>

        <div class="card mb-3" id="materiais"><div class="card-header bg-white fw-semibold">Materiais / peças</div><div class="card-body">
          <table class="table table-sm"><thead><tr><th>Código</th><th>Descrição</th><th>Qtd</th><th class="text-end">Custo un.</th><th class="text-end">Total</th><th>Estoque</th><th></th></tr></thead><tbody>
          <?php foreach ($mat as $m): ?><tr><td><?= e($m['codigo']) ?></td><td><?= e($m['descricao']) ?></td><td><?= num($m['quantidade'], 2) ?> <?= e($m['unidade']) ?></td>
            <td class="text-end"><?= moeda($m['custo_unitario']) ?></td><td class="text-end"><?= moeda($m['quantidade'] * $m['custo_unitario']) ?></td>
            <td><?= $m['baixado'] ? '<span class="badge text-bg-success">baixado</span>' : '<span class="badge text-bg-warning">reservado</span> <small class="text-muted">saldo ' . num($m['estoque_atual'], 2) . '</small>' ?></td>
            <td class="text-end text-nowrap"><?php if ($exec): ?>
              <?php if (!$m['baixado']): ?><form method="post" action="<?= e(url('ots/mat_baixar')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="item_id" value="<?= $m['id'] ?>"><button class="btn btn-link btn-sm p-0 no-print">dar baixa</button></form><?php endif; ?>
              <form method="post" action="<?= e(url('ots/mat_del')) ?>" class="d-inline" data-confirm="Remover material<?= $m['baixado'] ? ' e devolver ao estoque' : '' ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="item_id" value="<?= $m['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0 ms-2 no-print"><i class="bi bi-x-lg"></i></button></form>
            <?php endif; ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-semibold"><td colspan="4">Total</td><td class="text-end"><?= moeda($custoMat) ?></td><td colspan="2"></td></tr>
          </tbody></table>
          <?php if ($exec): ?>
          <form method="post" action="<?= e(url('ots/mat_add')) ?>" class="row g-2 no-print"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <div class="col-md-8"><?= sel('artigo_id', opcoes("SELECT id, CONCAT(codigo,' · ',descricao,' (saldo ',FORMAT(estoque_atual,2,'pt_BR'),' ',unidade,')') FROM artigos WHERE ativo=1 ORDER BY codigo"), null, true, 'required class="form-select form-select-sm"') ?></div>
            <div class="col-md-2"><input class="form-control form-control-sm" inputmode="decimal" name="quantidade" placeholder="Qtd" required></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Requisitar</button></div>
          </form>
          <?php endif; ?>
        </div></div>
      </div>

      <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header bg-white fw-semibold">Custos</div><div class="card-body">
          <table class="table table-sm mb-0">
            <tr><td>Mão de obra (<?= num($horasMO, 1) ?> h)</td><td class="text-end"><?= moeda($custoMO) ?></td></tr>
            <tr><td>Materiais</td><td class="text-end"><?= moeda($custoMat) ?></td></tr>
            <tr><td>Serviços externos</td><td class="text-end"><?= moeda($o['custo_externo']) ?></td></tr>
            <tr class="fw-bold"><td>Total</td><td class="text-end"><?= moeda($custoMO + $custoMat + $o['custo_externo']) ?></td></tr>
          </table>
        </div></div>

        <?php if ($exec): ?>
        <div class="card mb-3 border-success no-print" id="concluir"><div class="card-header bg-success text-white fw-semibold">Concluir OT</div><div class="card-body">
          <form method="post" action="<?= e(url('ots/status')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="novo" value="concluir">
            <div class="mb-2"><label class="form-label">Início</label><input type="datetime-local" class="form-control form-control-sm" name="data_inicio" value="<?= e(input_dt($o['data_inicio'] ?: date('Y-m-d H:i'))) ?>"></div>
            <div class="mb-2"><label class="form-label">Término</label><input type="datetime-local" class="form-control form-control-sm" name="data_fim" value="<?= e(input_dt(date('Y-m-d H:i'))) ?>"></div>
            <?php if ($o['ativo_parado']): ?>
              <div class="mb-2"><label class="form-label">Horas de parada do ativo</label><input class="form-control form-control-sm" inputmode="decimal" name="horas_parada" placeholder="vazio = calcular automaticamente">
                <div class="form-text">Automático: da abertura do pedido (ou início) até o término.</div></div>
            <?php endif; ?>
            <?php if ($o['tipo'] === 'corretiva'): ?>
              <div class="mb-2"><label class="form-label">Causa da falha</label><input class="form-control form-control-sm" name="causa_falha" list="causas" value="<?= e($o['causa_falha']) ?>">
                <datalist id="causas"><?php foreach (q('SELECT DISTINCT causa_falha FROM ordens_trabalho WHERE causa_falha IS NOT NULL ORDER BY causa_falha LIMIT 50') as $c): ?><option value="<?= e($c['causa_falha']) ?>"><?php endforeach; ?>
                  <option value="Desgaste natural"><option value="Falta de lubrificação"><option value="Falha elétrica"><option value="Operação inadequada"><option value="Sobrecarga"><option value="Corrosão"></datalist></div>
            <?php endif; ?>
            <?php if ($o['plano_gatilho'] === 'contador'): ?>
              <div class="mb-2"><label class="form-label">Leitura do contador na execução</label><input class="form-control form-control-sm" inputmode="decimal" name="leitura_contador"></div>
            <?php endif; ?>
            <div class="mb-2"><label class="form-label">Custo externo (R$)</label><input class="form-control form-control-sm" inputmode="decimal" name="custo_externo" value="<?= num($o['custo_externo']) ?>"></div>
            <div class="mb-2"><label class="form-label">Trabalho realizado</label><textarea class="form-control form-control-sm" name="solucao" rows="3"><?= e($o['solucao']) ?></textarea></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="ignorar_tarefas" value="1" id="ig"><label class="form-check-label small" for="ig">Concluir mesmo com tarefas pendentes</label></div>
            <button class="btn btn-success w-100">Concluir ordem de trabalho</button>
          </form>
          <?php if (pode('gerir')): ?>
          <form method="post" action="<?= e(url('ots/status')) ?>" class="mt-3" data-confirm="Cancelar esta OT? Materiais baixados voltam ao estoque."><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="novo" value="cancelar">
            <div class="input-group input-group-sm"><input class="form-control" name="motivo" placeholder="Motivo do cancelamento"><button class="btn btn-outline-danger">Cancelar OT</button></div>
          </form>
          <?php endif; ?>
        </div></div>
        <?php endif; ?>
        <?php if ($acao === 'imprimir'): ?>
          <div class="mt-5 pt-4 small"><div class="border-top pt-1">Assinatura do executante</div><div class="border-top pt-1 mt-5">Assinatura do responsável pela área</div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php view_footer(); exit;
}

/* ================= LISTA ================= */
$f = get('f', 'pendentes');
$w = []; $prm = [];
match ($f) {
    'pendentes'  => $w[] = "o.status NOT IN ('concluida','cancelada')",
    'atrasadas'  => $w[] = "o.status NOT IN ('concluida','cancelada') AND o.data_prevista < CURDATE()",
    'minhas'     => $w[] = "o.status NOT IN ('concluida','cancelada') AND o.responsavel_id = " . (int)($u['tecnico_id'] ?? 0),
    'concluidas' => $w[] = "o.status = 'concluida'",
    default      => null,
};
if ($t = get('tipo')) { $w[] = 'o.tipo = ?'; $prm[] = $t; }
if ($s = get('status')) { $w[] = 'o.status = ?'; $prm[] = $s; }
if ($r = get('resp')) { $w[] = 'o.responsavel_id = ?'; $prm[] = (int)$r; }
if ($a = get('ativo')) { $w[] = 'o.ativo_id IN (' . implode(',', descendentes('ativos', (int)$a)) . ')'; }
if ($b = get('q')) { $w[] = '(o.numero LIKE ? OR o.titulo LIKE ? OR a.codigo LIKE ? OR a.nome LIKE ?)'; array_push($prm, "%$b%", "%$b%", "%$b%", "%$b%"); }
if ($di = get('de')) { $w[] = 'DATE(o.data_abertura) >= ?'; $prm[] = $di; }
if ($df = get('ate')) { $w[] = 'DATE(o.data_abertura) <= ?'; $prm[] = $df; }
$sql = "SELECT o.*, a.codigo AS ativo_codigo, a.nome AS ativo_nome, t.nome AS responsavel,
          (SELECT COALESCE(SUM(horas*custo_hora),0) FROM ot_mao_obra WHERE ot_id=o.id) + (SELECT COALESCE(SUM(quantidade*custo_unitario),0) FROM ot_materiais WHERE ot_id=o.id) + o.custo_externo AS custo
        FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id LEFT JOIN tecnicos t ON t.id=o.responsavel_id "
     . ($w ? 'WHERE ' . implode(' AND ', $w) : '')
     . ($f === 'concluidas' ? ' ORDER BY o.data_fim DESC' : " ORDER BY FIELD(o.prioridade,'urgente','alta','media','baixa'), o.data_prevista IS NULL, o.data_prevista, o.id");

if (get('csv')) {
    csv_download('ordens_trabalho', ['Nº', 'Tipo', 'Prioridade', 'Status', 'Título', 'Ativo', 'Responsável', 'Abertura', 'Prevista', 'Início', 'Término', 'Horas parada', 'Custo total'],
        array_map(fn($o) => [$o['numero'], rotulo('tipo_ot', $o['tipo']), rotulo('prioridade', $o['prioridade']), rotulo('ot_status', $o['status']), $o['titulo'],
            trim($o['ativo_codigo'] . ' ' . $o['ativo_nome']), $o['responsavel'], dt($o['data_abertura'], true), dt($o['data_prevista']), dt($o['data_inicio'], true), dt($o['data_fim'], true),
            num($o['horas_parada']), num($o['custo'])], q($sql, $prm)));
}
$pg = paginar($sql, $prm, 30);

view_header('Ordens de trabalho', 'ots'); ?>
<div class="page-title"><h1>Ordens de trabalho</h1>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="?<?= e(http_build_query($_GET + ['csv' => 1])) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
    <?php if (pode('executar')): ?><a class="btn btn-warning btn-sm" href="<?= e(url('ots/form')) ?>"><i class="bi bi-plus-lg"></i> Nova OT</a><?php endif; ?>
  </div>
</div>
<div class="btn-group btn-group-sm mb-2 flex-wrap">
  <?php foreach (['pendentes' => 'Pendentes', 'atrasadas' => 'Atrasadas', 'minhas' => 'Minhas', 'concluidas' => 'Concluídas', 'todas' => 'Todas'] as $k => $r):
      if ($k === 'minhas' && empty($u['tecnico_id'])) continue; ?>
    <a class="btn btn-outline-secondary <?= $f === $k ? 'active' : '' ?>" href="<?= e(url('ots', ['f' => $k])) ?>"><?= $r ?></a>
  <?php endforeach; ?>
</div>
<form class="card card-body py-2 mb-3" method="get"><input type="hidden" name="r" value="ots"><input type="hidden" name="f" value="<?= e($f) ?>">
  <div class="row g-2">
    <div class="col-md-3"><input class="form-control form-control-sm" name="q" placeholder="Nº, título, ativo…" value="<?= e(get('q')) ?>"></div>
    <div class="col-md-2"><?= sel('tipo', lista_rotulos('tipo_ot'), get('tipo'), true, 'class="form-select form-select-sm"') ?></div>
    <div class="col-md-2"><?= sel('status', lista_rotulos('ot_status'), get('status'), true, 'class="form-select form-select-sm"') ?></div>
    <div class="col-md-2"><?= sel('resp', $tecnicos, get('resp'), true, 'class="form-select form-select-sm"') ?></div>
    <div class="col-md-1"><input type="date" class="form-control form-control-sm" name="de" value="<?= e(get('de')) ?>" title="Aberta de"></div>
    <div class="col-md-1"><input type="date" class="form-control form-control-sm" name="ate" value="<?= e(get('ate')) ?>" title="até"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-secondary w-100">Filtrar</button></div>
  </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
<thead><tr><th>Nº</th><th>Tipo</th><th>Título</th><th>Ativo</th><th>Responsável</th><th>Prevista</th><th>Prioridade</th><th class="text-end">Custo</th><th>Status</th></tr></thead><tbody>
<?php foreach ($pg['linhas'] as $o): $atr = !in_array($o['status'], ['concluida', 'cancelada']) && $o['data_prevista'] && $o['data_prevista'] < date('Y-m-d'); ?>
  <tr data-href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>" class="<?= $atr ? 'atrasada' : '' ?>"><td class="text-nowrap"><?= e($o['numero']) ?></td><td><?= badge('tipo_ot', $o['tipo']) ?></td><td><?= e($o['titulo']) ?></td>
  <td><?= e($o['ativo_codigo']) ?></td><td><?= e($o['responsavel']) ?></td><td class="<?= $atr ? 'text-danger fw-semibold' : '' ?>"><?= dt($o['data_prevista']) ?></td>
  <td><?= badge('prioridade', $o['prioridade']) ?></td><td class="text-end"><?= moeda($o['custo']) ?></td><td><?= badge('ot_status', $o['status']) ?></td></tr>
<?php endforeach; if (!$pg['linhas']): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhuma ordem de trabalho.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white"><?= paginacao_html($pg) ?></div></div>
<?php view_footer();
