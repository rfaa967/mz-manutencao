<?php
exigir('ver');
$u = usuario();
$tecnicos = opcoes('SELECT id, nome FROM tecnicos WHERE ativo = 1 ORDER BY nome');

const OT_LIMITE_HORAS = 9999;        // DECIMAL(6,2) / (8,2)
const OT_LIMITE_VALOR = 9999999999;  // DECIMAL(12,2)

function ot_carregar(int $id, bool $lock = false): ?array
{
    return q1('SELECT o.*, a.codigo AS ativo_codigo, a.nome AS ativo_nome, a.localizacao_id, t.nome AS responsavel,
                 p.numero AS pedido_numero, p.criado_em AS pedido_em, pl.titulo AS plano_titulo, pl.gatilho AS plano_gatilho, pl.contador_id
               FROM ordens_trabalho o LEFT JOIN ativos a ON a.id=o.ativo_id LEFT JOIN tecnicos t ON t.id=o.responsavel_id
               LEFT JOIN pedidos p ON p.id=o.pedido_id LEFT JOIN planos_manutencao pl ON pl.id=o.plano_id WHERE o.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
}
function ot_aberta(array $o): bool { return !in_array($o['status'], ['concluida', 'cancelada'], true); }
function ot_voltar(int $id, string $ancora = ''): never { redirect_m('m/ot', ['id' => $id]); header('Location: ' . url('ots/ver', ['id' => $id]) . ($ancora ? "#$ancora" : '')); exit; }

/** Gestor executa qualquer OT; técnico só as atribuídas a ele ou ainda sem responsável. */
function ot_pode_executar(array $o): bool
{
    if (!pode('executar') || !ot_aberta($o)) return false;
    if (pode('gerir') || !$o['responsavel_id']) return true;
    return (int)$o['responsavel_id'] === (int)(usuario()['tecnico_id'] ?? 0);
}
function ot_exigir_execucao(?array $o): array
{
    if (!$o) redirect('ots');
    if (!ot_aberta($o)) { flash('Esta OT já está encerrada.', 'warning'); ot_voltar((int)$o['id']); }
    if (!ot_pode_executar($o)) { flash('Esta OT está atribuída a outro técnico. Peça ao gestor para reatribuí-la.', 'danger'); ot_voltar((int)$o['id']); }
    return $o;
}

/** Saldo livre de um artigo: estoque menos o reservado (não baixado) em outras OTs abertas. */
function saldo_disponivel(int $artigoId, int $exceptoOt = 0): float
{
    return (float)qv("SELECT a.estoque_atual - COALESCE((SELECT SUM(m.quantidade) FROM ot_materiais m JOIN ordens_trabalho o ON o.id = m.ot_id
                        WHERE m.artigo_id = a.id AND m.baixado = 0 AND m.ot_id <> ? AND o.status IN (" . STATUS_PENDENTES . ")), 0)
                      FROM artigos a WHERE a.id = ?", [$exceptoOt, $artigoId]);
}

/** Executa $fn numa transação; erros de validação/estoque viram mensagem, o resto sobe. */
function ot_transacao(int $otId, string $ancora, callable $fn): void
{
    db()->beginTransaction();
    try { $fn(); db()->commit(); }
    catch (RuntimeException $ex) {
        if (db()->inTransaction()) db()->rollBack();
        $msg = $ex instanceof PDOException ? 'Valor fora do limite permitido ou dado inválido.' : $ex->getMessage();
        flash($msg, 'danger');
        ot_voltar($otId, $ancora);
    }
}

/* ================= SALVAR CABEÇALHO ================= */
if ($acao === 'salvar' && is_post()) {
    exigir('executar');
    $id = post_int('id');
    $volta = fn() => [redirect_m('m/nova_ot', ['ativo' => post_int('ativo_id')]), redirect('ots/form', $id ? ['id' => $id] : ['ativo' => post_int('ativo_id')])];
    $dados = [
        'ativo_id' => post_int('ativo_id'),
        'tipo' => array_key_exists((string)post('tipo'), ROTULOS['tipo_ot']) ? post('tipo') : 'corretiva',
        'prioridade' => array_key_exists((string)post('prioridade'), ROTULOS['prioridade']) ? post('prioridade') : 'media',
        'titulo' => mb_substr((string)post('titulo'), 0, 150), 'descricao' => post('descricao') ?: null,
        'responsavel_id' => post_int('responsavel_id'), 'data_prevista' => post_date('data_prevista'),
        'ativo_parado' => post('ativo_parado') ? 1 : 0, 'custo_externo' => post_dec_pos('custo_externo') ?? 0,
    ];
    if ($dados['titulo'] === '') { flash('Informe o título.', 'danger'); $volta(); }
    if (dec_invalido('custo_externo') || $dados['custo_externo'] > OT_LIMITE_VALOR) { flash('Custo externo inválido.', 'danger'); $volta(); }
    if ($dados['ativo_id'] && !qv('SELECT 1 FROM ativos WHERE id = ?', [$dados['ativo_id']])) { flash('Ativo inválido.', 'danger'); $volta(); }
    if ($dados['responsavel_id'] && !isset($tecnicos[$dados['responsavel_id']])) { flash('Responsável inválido.', 'danger'); $volta(); }
    if ($id) {
        $o = ot_exigir_execucao(ot_carregar($id));
        if (!pode('gerir')) unset($dados['responsavel_id'], $dados['prioridade']);
        if ($o['status'] === 'aberta' && !empty($dados['responsavel_id']) && $dados['data_prevista']) $dados['status'] = 'planejada';
        update('ordens_trabalho', $dados, $id);
        auditar('editar', 'ordens_trabalho', $id);
        if (!empty($dados['responsavel_id']) && (int)$dados['responsavel_id'] !== (int)$o['responsavel_id']) notificar_ot_atribuida($id);
        if ((int)$o['ativo_id'] !== (int)$dados['ativo_id']) atualizar_status_ativo($o['ativo_id'] ? (int)$o['ativo_id'] : null); // ativo antigo
    } else {
        if (!pode('gerir')) { $dados['responsavel_id'] = $u['tecnico_id']; $dados['prioridade'] = 'media'; }
        $dados += ['numero' => proximo_numero('ordens_trabalho', 'OT'), 'criado_por' => $u['id'],
                   'status' => ($dados['responsavel_id'] && $dados['data_prevista']) ? 'planejada' : 'aberta'];
        $id = insert('ordens_trabalho', $dados);
        auditar('criar', 'ordens_trabalho', $id);
        notificar_ot_atribuida($id);
    }
    atualizar_status_ativo($dados['ativo_id']);
    flash('Ordem de trabalho salva.');
    redirect_m('m/ot', ['id' => $id]);
    redirect('ots/ver', ['id' => $id]);
}

/* ================= TRANSIÇÕES DE STATUS ================= */
if ($acao === 'status' && is_post()) {
    exigir('executar');
    $otId = (int)post_int('id');
    $o = ot_carregar($otId);
    if (!$o) redirect('ots');
    $novo = (string)post('novo');
    $agora = date('Y-m-d H:i:s');

    // máquina de estados: de onde cada ação pode partir
    $permitido = [
        'iniciar' => ['aberta', 'planejada'], 'aguardar' => ['em_execucao'], 'retomar' => ['aguardando_pecas'],
        'concluir' => ['aberta', 'planejada', 'em_execucao', 'aguardando_pecas'],
        'cancelar' => ['aberta', 'planejada', 'em_execucao', 'aguardando_pecas'], 'reabrir' => ['concluida'],
    ];
    if (!isset($permitido[$novo])) ot_voltar($otId);
    if (!in_array($o['status'], $permitido[$novo], true)) { flash('Esta ação não é possível no status atual da OT (' . rotulo('ot_status', $o['status']) . ').', 'warning'); ot_voltar($otId); }
    if (in_array($novo, ['cancelar', 'reabrir'], true)) exigir('gerir'); else ot_exigir_execucao($o);

    switch ($novo) {
        case 'iniciar':
            qx("UPDATE ordens_trabalho SET status = 'em_execucao', data_inicio = COALESCE(data_inicio, ?) WHERE id = ? AND status IN ('aberta','planejada')", [$agora, $otId]);
            break;
        case 'aguardar':
            qx("UPDATE ordens_trabalho SET status = 'aguardando_pecas' WHERE id = ? AND status = 'em_execucao'", [$otId]);
            break;
        case 'retomar':
            qx("UPDATE ordens_trabalho SET status = 'em_execucao' WHERE id = ? AND status = 'aguardando_pecas'", [$otId]);
            break;

        case 'cancelar':
            ot_transacao($otId, '', function () use ($otId) {
                $o = ot_carregar($otId, true);                       // trava a OT: só um cancelamento passa
                if (!ot_aberta($o)) throw new RuntimeException('A OT já foi encerrada por outro usuário.');
                foreach (q('SELECT * FROM ot_materiais WHERE ot_id = ? AND baixado = 1 FOR UPDATE', [$otId]) as $m) {
                    movimentar_estoque((int)$m['artigo_id'], 'entrada', (float)$m['quantidade'], (float)$m['custo_unitario'], $otId, $o['numero'], 'Estorno — OT cancelada');
                }
                qx('UPDATE ot_materiais SET baixado = 0 WHERE ot_id = ?', [$otId]);
                recalcular_custos_ot($otId);
                update('ordens_trabalho', ['status' => 'cancelada', 'data_fim' => null,
                    'solucao' => trim(($o['solucao'] ?? '') . "\n[Cancelada] " . mb_substr((string)post('motivo'), 0, 300))], $otId);
            });
            // preventiva cancelada: por padrão pula esta ocorrência (senão o gerador recria a mesma OT)
            if ($o['plano_id'] && post('pular_ocorrencia', '1') === '1') reprogramar_plano_apos_ot($otId, true);
            break;

        case 'reabrir':
            if ($o['plano_id'] && qv("SELECT COUNT(*) FROM ordens_trabalho WHERE plano_id = ? AND id <> ? AND status IN (" . STATUS_PENDENTES . ")", [$o['plano_id'], $otId])) {
                flash('O plano desta OT já tem outra OT pendente; reabrir criaria duplicidade.', 'warning'); ot_voltar($otId);
            }
            update('ordens_trabalho', ['status' => 'em_execucao', 'data_fim' => null], $otId);
            break;

        case 'concluir':
            $ini = post_datetime('data_inicio') ?? ($o['data_inicio'] ?: $agora);
            $fim = post_datetime('data_fim') ?? $agora;
            $erro = null;
            if ($fim < $ini) $erro = 'A data de término não pode ser anterior ao início.';
            elseif ($fim > date('Y-m-d H:i:s', time() + 600)) $erro = 'A data de término não pode estar no futuro.';
            elseif (dec_invalido('horas_parada') || dec_invalido('custo_externo') || dec_invalido('leitura_contador')) $erro = 'Horas de parada, custo e leitura devem ser números positivos.';
            elseif ((post_dec('horas_parada') ?? 0) > OT_LIMITE_HORAS * 100 || (post_dec('custo_externo') ?? 0) > OT_LIMITE_VALOR) $erro = 'Valor acima do limite permitido.';
            if ($erro) { flash($erro, 'danger'); ot_voltar($otId, 'concluir'); }

            $pendTarefas = (int)qv('SELECT COUNT(*) FROM ot_tarefas WHERE ot_id = ? AND concluida = 0', [$otId]);
            if ($pendTarefas && !post('ignorar_tarefas')) { flash(($pendTarefas === 1 ? 'Há 1 tarefa do checklist não marcada.' : "Há $pendTarefas tarefas do checklist não marcadas.") . ' Marque-as ou confirme a conclusão mesmo assim.', 'warning'); ot_voltar($otId, 'concluir'); }

            $leitura = post_dec_pos('leitura_contador');
            if ($leitura !== null && $o['contador_id']) {
                $atual = (float)qv('SELECT valor_atual FROM contadores WHERE id = ?', [$o['contador_id']]);
                if ($leitura < $atual) { flash('A leitura informada (' . num($leitura, 1) . ') é menor que o valor atual do contador (' . num($atual, 1) . ').', 'danger'); ot_voltar($otId, 'concluir'); }
            }
            ot_transacao($otId, 'materiais', function () use ($otId, $ini, $fim, $leitura, $u) {
                $o = ot_carregar($otId, true);
                if (!ot_aberta($o)) throw new RuntimeException('A OT já foi encerrada por outro usuário.');
                foreach (q('SELECT * FROM ot_materiais WHERE ot_id = ? AND baixado = 0 FOR UPDATE', [$otId]) as $m) {
                    $custo = movimentar_estoque((int)$m['artigo_id'], 'saida', (float)$m['quantidade'], null, $otId, $o['numero'], 'Consumo OT');
                    qx('UPDATE ot_materiais SET baixado = 1, custo_unitario = ? WHERE id = ?', [$custo, $m['id']]);
                }
                $horasParada = post_dec_pos('horas_parada');
                if ($horasParada === null && $o['ativo_parado']) {
                    // da comunicação da falha (pedido) ou da abertura da OT até o término
                    $base = min($o['pedido_em'] ?: $o['data_abertura'], $fim);
                    $horasParada = max(0, round((strtotime($fim) - strtotime($base)) / 3600, 2));
                }
                update('ordens_trabalho', [
                    'status' => 'concluida', 'data_inicio' => $ini, 'data_fim' => $fim,
                    'horas_parada' => min($horasParada ?? 0, 999999), 'causa_falha' => mb_substr((string)post('causa_falha'), 0, 150) ?: null,
                    'solucao' => post('solucao') ?: null, 'custo_externo' => post_dec_pos('custo_externo') ?? $o['custo_externo'],
                    'leitura_contador' => $leitura,
                ], $otId);
                // leitura do contador do plano (uma por OT, mesmo se reaberta e concluída de novo)
                if ($leitura !== null && $o['contador_id']) {
                    qx('DELETE FROM leituras_contador WHERE contador_id = ? AND ot_id = ?', [$o['contador_id'], $otId]);
                    insert('leituras_contador', ['contador_id' => $o['contador_id'], 'valor' => $leitura, 'data_leitura' => $fim, 'usuario_id' => $u['id'], 'ot_id' => $otId, 'observacao' => 'Leitura na conclusão da ' . $o['numero']]);
                    qx('UPDATE contadores SET valor_atual = GREATEST(valor_atual, ?), atualizado_em = GREATEST(COALESCE(atualizado_em, ?), ?) WHERE id = ?', [$leitura, $fim, $fim, $o['contador_id']]);
                }
                recalcular_custos_ot($otId);
                reprogramar_plano_apos_ot($otId);   // mesma transação: OT e plano mudam juntos
            });
            notificar_ot_concluida($otId);
            break;
    }
    atualizar_status_ativo($o['ativo_id'] ? (int)$o['ativo_id'] : null);
    auditar('status_' . $novo, 'ordens_trabalho', $otId);
    flash($novo === 'concluir' ? 'Ordem de trabalho concluída.' : 'Status atualizado.');
    ot_voltar($otId);
}

/* ================= MÃO DE OBRA ================= */
if ($acao === 'mo_add' && is_post()) {
    exigir('executar');
    $o = ot_exigir_execucao(ot_carregar((int)post_int('id')));
    $tecId = post_int('tecnico_id') ?: $u['tecnico_id'];
    if (!pode('gerir') && (int)$tecId !== (int)$u['tecnico_id']) { flash('Você só pode apontar as suas próprias horas.', 'danger'); ot_voltar((int)$o['id'], 'maoobra'); }
    $tec = $tecId ? q1('SELECT * FROM tecnicos WHERE id = ? AND ativo = 1', [$tecId]) : null;
    $horas = post_dec_pos('horas');
    $data = post_date('data') ?? date('Y-m-d');
    if (!$tec) flash('Selecione o técnico.', 'danger');
    elseif ($horas === null || round($horas, 2) <= 0 || $horas > 24) flash('Informe as horas (entre 0,01 e 24 por lançamento).', 'danger');
    elseif ($data > date('Y-m-d')) flash('A data do apontamento não pode estar no futuro.', 'danger');
    else {
        insert('ot_mao_obra', ['ot_id' => $o['id'], 'tecnico_id' => $tec['id'], 'data' => $data, 'horas' => round($horas, 2), 'custo_hora' => $tec['custo_hora'], 'observacao' => mb_substr((string)post('observacao'), 0, 255) ?: null]);
        if (in_array($o['status'], ['aberta', 'planejada'], true)) qx("UPDATE ordens_trabalho SET status = 'em_execucao', data_inicio = COALESCE(data_inicio, NOW()) WHERE id = ?", [$o['id']]);
        atualizar_status_ativo($o['ativo_id'] ? (int)$o['ativo_id'] : null);
        recalcular_custos_ot((int)$o['id']);
        flash(num($horas, 2) . ' h apontadas.');
    }
    ot_voltar((int)$o['id'], 'maoobra');
}
if ($acao === 'mo_del' && is_post()) {
    exigir('executar');
    $o = ot_exigir_execucao(ot_carregar((int)post_int('id')));
    $cond = pode('gerir') ? '' : ' AND tecnico_id = ' . (int)$u['tecnico_id'];
    qx('DELETE FROM ot_mao_obra WHERE id = ? AND ot_id = ?' . $cond, [post_int('item_id'), $o['id']]);
    recalcular_custos_ot((int)$o['id']);
    ot_voltar((int)$o['id'], 'maoobra');
}

/* ================= MATERIAIS ================= */
if ($acao === 'mat_add' && is_post()) {
    exigir('executar');
    $o = ot_exigir_execucao(ot_carregar((int)post_int('id')));
    $art = q1('SELECT * FROM artigos WHERE id = ? AND ativo = 1', [post_int('artigo_id')]);
    $qtd = post_dec_pos('quantidade');
    if (!$art || $qtd === null || round($qtd, 3) <= 0 || $qtd > 999999) { flash('Selecione o material e informe uma quantidade válida.', 'danger'); ot_voltar((int)$o['id'], 'materiais'); }
    $qtd = round($qtd, 3);
    ot_transacao((int)$o['id'], 'materiais', function () use ($o, $art, $qtd) {
        qv('SELECT id FROM artigos WHERE id = ? FOR UPDATE', [$art['id']]);
        $livre = saldo_disponivel((int)$art['id'], (int)$o['id']);
        if ($qtd > $livre + 1e-9) {
            $res = (float)$art['estoque_atual'] - $livre;
            throw new RuntimeException("Saldo disponível de {$art['codigo']}: " . num(max(0, $livre), 2) . " {$art['unidade']}"
                . ($res > 0 ? ' (' . num($res, 2) . ' reservado(s) para outras OTs).' : '.'));
        }
        $custo = movimentar_estoque((int)$art['id'], 'saida', $qtd, null, (int)$o['id'], $o['numero'], 'Requisição para OT');
        insert('ot_materiais', ['ot_id' => $o['id'], 'artigo_id' => $art['id'], 'quantidade' => $qtd, 'custo_unitario' => $custo, 'baixado' => 1]);
        recalcular_custos_ot((int)$o['id']);
    });
    ot_voltar((int)$o['id'], 'materiais');
}
if ($acao === 'mat_del' && is_post()) {
    exigir('executar');
    $o = ot_exigir_execucao(ot_carregar((int)post_int('id')));
    ot_transacao((int)$o['id'], 'materiais', function () use ($o) {
        // trava a linha: dois cliques/abas não devolvem a mesma peça duas vezes
        $m = q1('SELECT * FROM ot_materiais WHERE id = ? AND ot_id = ? FOR UPDATE', [post_int('item_id'), $o['id']]);
        if (!$m) return;
        if (qx('DELETE FROM ot_materiais WHERE id = ?', [$m['id']]) === 1 && $m['baixado']) {
            movimentar_estoque((int)$m['artigo_id'], 'entrada', (float)$m['quantidade'], (float)$m['custo_unitario'], (int)$o['id'], $o['numero'], 'Devolução da OT');
        }
        recalcular_custos_ot((int)$o['id']);
    });
    ot_voltar((int)$o['id'], 'materiais');
}
if ($acao === 'mat_baixar' && is_post()) {
    exigir('executar');
    $o = ot_exigir_execucao(ot_carregar((int)post_int('id')));
    ot_transacao((int)$o['id'], 'materiais', function () use ($o) {
        $m = q1('SELECT * FROM ot_materiais WHERE id = ? AND ot_id = ? AND baixado = 0 FOR UPDATE', [post_int('item_id'), $o['id']]);
        if (!$m) return;
        $custo = movimentar_estoque((int)$m['artigo_id'], 'saida', (float)$m['quantidade'], null, (int)$o['id'], $o['numero'], 'Requisição para OT');
        qx('UPDATE ot_materiais SET baixado = 1, custo_unitario = ? WHERE id = ?', [$custo, $m['id']]);
        recalcular_custos_ot((int)$o['id']);
    });
    ot_voltar((int)$o['id'], 'materiais');
}

/* ================= CHECKLIST ================= */
if ($acao === 'tarefa' && is_post()) {
    exigir('executar');
    $t = q1('SELECT t.id, t.ot_id FROM ot_tarefas t WHERE t.id = ?', [post_int('tarefa_id')]);
    $o = $t ? ot_carregar((int)$t['ot_id']) : null;
    if (!$o || !ot_pode_executar($o)) { http_response_code(409); json_out(['ok' => false]); }
    qx('UPDATE ot_tarefas SET concluida = ? WHERE id = ?', [post('concluida') ? 1 : 0, $t['id']]);
    json_out(['ok' => true]);
}
if ($acao === 'tarefa_add' && is_post()) {
    exigir('executar');
    $o = ot_exigir_execucao(ot_carregar((int)post_int('id')));
    if (post('descricao')) {
        insert('ot_tarefas', ['ot_id' => $o['id'], 'descricao' => mb_substr((string)post('descricao'), 0, 255), 'ordem' => (int)qv('SELECT COALESCE(MAX(ordem),0)+1 FROM ot_tarefas WHERE ot_id=?', [$o['id']])]);
    }
    ot_voltar((int)$o['id'], 'checklist');
}

/* ================= FORMULÁRIO ================= */
if ($acao === 'form') {
    exigir('executar');
    $id = (int)get('id', 0);
    $o = $id ? ot_carregar($id) : ['tipo' => 'corretiva', 'prioridade' => 'media', 'ativo_id' => get('ativo'), 'data_prevista' => date('Y-m-d'), 'custo_externo' => 0, 'responsavel_id' => $u['tecnico_id']];
    if ($id && (!$o || !ot_pode_executar($o))) redirect('ots/ver', ['id' => $id]);
    if (!empty($_SESSION['old'])) $o = com_old($o);
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
    $custoMat = array_sum(array_map(fn($x) => $x['baixado'] ? $x['quantidade'] * $x['custo_unitario'] : 0, $mat));       // realizado
    $custoMatPrev = array_sum(array_map(fn($x) => $x['baixado'] ? 0 : $x['quantidade'] * $x['custo_unitario'], $mat));  // reservado (previsto)
    $horasMO = array_sum(array_column($mo, 'horas'));
    $aberta = ot_aberta($o);
    $exec = ot_pode_executar($o);
    $atrasada = $aberta && $o['data_prevista'] && $o['data_prevista'] < date('Y-m-d');

    view_header($o['numero'], 'ots');
    if ($aberta && pode('executar') && !$exec) echo '<div class="alert alert-info py-2 no-print">Esta OT está atribuída a ' . e($o['responsavel']) . '. Somente o responsável ou um gestor pode executá-la.</div>';
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
          <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Data</th><th>Técnico</th><th>Horas</th><th class="text-end">Custo</th><th>Obs.</th><th></th></tr></thead><tbody>
          <?php foreach ($mo as $m): ?><tr><td><?= dt($m['data']) ?></td><td><?= e($m['nome']) ?></td><td><?= num($m['horas'], 2) ?></td><td class="text-end"><?= moeda($m['horas'] * $m['custo_hora']) ?></td><td><?= e($m['observacao']) ?></td>
            <td class="text-end"><?php if ($exec): ?><form method="post" action="<?= e(url('ots/mo_del')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="item_id" value="<?= $m['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0 no-print"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-semibold"><td colspan="2">Total</td><td><?= num($horasMO, 2) ?> h</td><td class="text-end"><?= moeda($custoMO) ?></td><td colspan="2"></td></tr>
          </tbody></table></div>
          <?php if ($exec): ?>
          <form method="post" action="<?= e(url('ots/mo_add')) ?>" class="row g-2 no-print"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <div class="col-md-3"><input type="date" class="form-control form-control-sm" name="data" aria-label="Data do apontamento" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-md-3"><?php if (pode('gerir')): ?><?= sel('tecnico_id', $tecnicos, $u['tecnico_id'] ?? $o['responsavel_id'], true, 'required class="form-select form-select-sm" aria-label="Técnico"') ?>
              <?php else: ?><input type="hidden" name="tecnico_id" value="<?= (int)$u['tecnico_id'] ?>"><input class="form-control form-control-sm" aria-label="Técnico" value="<?= e($tecnicos[$u['tecnico_id']] ?? 'Usuário sem técnico vinculado') ?>" disabled><?php endif; ?></div>
            <div class="col-md-2"><input class="form-control form-control-sm" inputmode="decimal" name="horas" placeholder="Horas" required></div>
            <div class="col-md-3"><input class="form-control form-control-sm" name="observacao" placeholder="Observação"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">+</button></div>
          </form>
          <?php endif; ?>
        </div></div>

        <div class="card mb-3" id="materiais"><div class="card-header bg-white fw-semibold">Materiais / peças</div><div class="card-body">
          <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Código</th><th>Descrição</th><th>Qtd</th><th class="text-end">Custo un.</th><th class="text-end">Total</th><th>Estoque</th><th></th></tr></thead><tbody>
          <?php foreach ($mat as $m): ?><tr><td><?= e($m['codigo']) ?></td><td><?= e($m['descricao']) ?></td><td><?= num($m['quantidade'], 2) ?> <?= e($m['unidade']) ?></td>
            <td class="text-end"><?= moeda($m['custo_unitario']) ?></td><td class="text-end"><?= moeda($m['quantidade'] * $m['custo_unitario']) ?></td>
            <td><?= $m['baixado'] ? '<span class="badge text-bg-success">baixado</span>' : '<span class="badge text-bg-warning">reservado</span> <small class="text-muted">saldo ' . num($m['estoque_atual'], 2) . '</small>' ?></td>
            <td class="text-end text-nowrap"><?php if ($exec): ?>
              <?php if (!$m['baixado']): ?><form method="post" action="<?= e(url('ots/mat_baixar')) ?>" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="item_id" value="<?= $m['id'] ?>"><button class="btn btn-link btn-sm p-0 no-print">dar baixa</button></form><?php endif; ?>
              <form method="post" action="<?= e(url('ots/mat_del')) ?>" class="d-inline" data-confirm="Remover material<?= $m['baixado'] ? ' e devolver ao estoque' : '' ?>?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="item_id" value="<?= $m['id'] ?>"><button class="btn btn-link btn-sm text-danger p-0 ms-2 no-print"><i class="bi bi-x-lg"></i></button></form>
            <?php endif; ?></td></tr>
          <?php endforeach; ?>
          <tr class="fw-semibold"><td colspan="4">Total baixado<?= $custoMatPrev > 0 ? ' <span class="fw-normal text-muted small">(+ ' . moeda($custoMatPrev) . ' reservado, custo estimado)</span>' : '' ?></td><td class="text-end"><?= moeda($custoMat) ?></td><td colspan="2"></td></tr>
          </tbody></table></div>
          <?php if ($exec): ?>
          <form method="post" action="<?= e(url('ots/mat_add')) ?>" class="row g-2 no-print"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>">
            <div class="col-md-8"><?= sel('artigo_id', opcoes("SELECT id, CONCAT(codigo,' · ',descricao,' (saldo ',FORMAT(estoque_atual,2,'pt_BR'),' ',unidade,')') FROM artigos WHERE ativo=1 ORDER BY codigo"), null, true, 'required class="form-select form-select-sm" aria-label="Material"') ?></div>
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
            <?php if ($custoMatPrev > 0): ?><tr class="text-muted small"><td>Materiais reservados (previsto)</td><td class="text-end"><?= moeda($custoMatPrev) ?></td></tr><?php endif; ?>
            <tr><td>Serviços externos</td><td class="text-end"><?= moeda($o['custo_externo']) ?></td></tr>
            <tr class="fw-bold"><td>Total</td><td class="text-end"><?= moeda($custoMO + $custoMat + $o['custo_externo']) ?></td></tr>
          </table>
        </div></div>

        <?php if ($exec): ?>
        <div class="card mb-3 border-success no-print" id="concluir"><div class="card-header bg-success text-white fw-semibold">Concluir OT</div><div class="card-body">
          <form method="post" action="<?= e(url('ots/status')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="novo" value="concluir">
            <div class="mb-2"><label class="form-label">Início</label><input type="datetime-local" class="form-control form-control-sm" name="data_inicio" value="<?= e(old('data_inicio') ?: input_dt($o['data_inicio'] ?: date('Y-m-d H:i'))) ?>"></div>
            <div class="mb-2"><label class="form-label">Término</label><input type="datetime-local" class="form-control form-control-sm" name="data_fim" max="<?= e(input_dt(date('Y-m-d H:i'))) ?>" value="<?= e(old('data_fim') ?: input_dt(date('Y-m-d H:i'))) ?>"></div>
            <?php if ($o['ativo_parado']): ?>
              <div class="mb-2"><label class="form-label">Horas de parada do ativo</label><input class="form-control form-control-sm" inputmode="decimal" name="horas_parada" value="<?= e(old('horas_parada') !== null ? num(old('horas_parada')) : '') ?>" placeholder="vazio = calcular automaticamente">
                <div class="form-text">Automático: da abertura do pedido (ou início) até o término.</div></div>
            <?php endif; ?>
            <?php if ($o['tipo'] === 'corretiva'): ?>
              <div class="mb-2"><label class="form-label">Causa da falha</label><input class="form-control form-control-sm" name="causa_falha" list="causas" value="<?= e(old('causa_falha', $o['causa_falha'])) ?>">
                <datalist id="causas"><?php foreach (q('SELECT DISTINCT causa_falha FROM ordens_trabalho WHERE causa_falha IS NOT NULL ORDER BY causa_falha LIMIT 50') as $c): ?><option value="<?= e($c['causa_falha']) ?>"><?php endforeach; ?>
                  <option value="Desgaste natural"><option value="Falta de lubrificação"><option value="Falha elétrica"><option value="Operação inadequada"><option value="Sobrecarga"><option value="Corrosão"></datalist></div>
            <?php endif; ?>
            <?php if ($o['plano_gatilho'] === 'contador'): ?>
              <div class="mb-2"><label class="form-label">Leitura do contador na execução</label><input class="form-control form-control-sm" inputmode="decimal" name="leitura_contador" value="<?= e(old('leitura_contador') !== null ? num(old('leitura_contador'), 1) : '') ?>"></div>
            <?php endif; ?>
            <div class="mb-2"><label class="form-label">Custo externo (R$)</label><input class="form-control form-control-sm" inputmode="decimal" name="custo_externo" value="<?= num(old('custo_externo', $o['custo_externo'])) ?>"></div>
            <div class="mb-2"><label class="form-label">Trabalho realizado</label><textarea class="form-control form-control-sm" name="solucao" rows="3"><?= e(old('solucao', $o['solucao'])) ?></textarea></div>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="ignorar_tarefas" value="1" id="ig"><label class="form-check-label small" for="ig">Concluir mesmo com tarefas pendentes</label></div>
            <button class="btn btn-success w-100">Concluir ordem de trabalho</button>
          </form>
          <?php if (pode('gerir')): ?>
          <form method="post" action="<?= e(url('ots/status')) ?>" class="mt-3" data-confirm="Cancelar esta OT? Materiais baixados voltam ao estoque."><?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $o['id'] ?>"><input type="hidden" name="novo" value="cancelar">
            <div class="input-group input-group-sm"><input class="form-control" name="motivo" placeholder="Motivo do cancelamento"><button class="btn btn-outline-danger">Cancelar OT</button></div>
            <?php if ($o['plano_id']): ?><input type="hidden" name="pular_ocorrencia" value="0">
              <div class="form-check small mt-1"><input class="form-check-input" type="checkbox" name="pular_ocorrencia" value="1" id="pul" checked>
              <label class="form-check-label" for="pul">Pular esta ocorrência do plano (reprograma para o próximo período)</label></div><?php endif; ?>
          </form>
          <?php endif; ?>
        </div></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="assinaturas"><div>Executante: ____________________ Data: ___/___/____</div><div>Responsável pela área: ____________________</div><div>Manutenção (aprovação)</div></div>
    <?php view_footer(); exit;
}

/* ================= LISTA ================= */
$f = get('f', 'pendentes');
$w = []; $prm = [];
match ($f) {
    'pendentes'  => $w[] = "o.status IN (" . STATUS_PENDENTES . ")",
    'atrasadas'  => $w[] = "o.status IN (" . STATUS_PENDENTES . ") AND o.data_prevista < CURDATE()",
    'minhas'     => $w[] = "o.status IN (" . STATUS_PENDENTES . ") AND o.responsavel_id = " . (int)($u['tecnico_id'] ?? 0),
    'concluidas' => $w[] = "o.status = 'concluida'",
    default      => null,
};
if ($t = get('tipo')) { $w[] = 'o.tipo = ?'; $prm[] = $t; }
if ($s = get('status')) { $w[] = 'o.status = ?'; $prm[] = $s; }
if ($r = get('resp')) { $w[] = 'o.responsavel_id = ?'; $prm[] = (int)$r; }
if ($a = get('ativo')) { $w[] = 'o.ativo_id IN (' . implode(',', descendentes('ativos', (int)$a)) . ')'; }
if (is_string($b = get('q'))) {
    $b = mb_substr(trim($b), 0, 60);
    if (preg_match('/^(OT)?\d{4}-\d+$/i', $b)) { $w[] = 'o.numero LIKE ?'; $prm[] = (stripos($b, 'OT') === 0 ? strtoupper($b) : "OT$b") . '%'; }
    else { $w[] = '(o.numero LIKE ? OR o.titulo LIKE ? OR o.ativo_id IN (SELECT id FROM ativos WHERE codigo LIKE ? OR nome LIKE ?))'; array_push($prm, "%$b%", "%$b%", "$b%", "%$b%"); }
}
if (is_string($di = get('de')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $di)) { $w[] = 'o.data_abertura >= ?'; $prm[] = $di; }
if (is_string($df = get('ate')) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) { $w[] = 'o.data_abertura < ? + INTERVAL 1 DAY'; $prm[] = $df; }
$sql = "SELECT o.*, a.codigo AS ativo_codigo, a.nome AS ativo_nome, t.nome AS responsavel,
          " . SQL_CUSTO_OT . " + " . SQL_CUSTO_MAT . " + o.custo_externo AS custo
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
    <div class="col-md-2"><?= sel('tipo', lista_rotulos('tipo_ot'), get('tipo'), true, 'class="form-select form-select-sm" aria-label="Tipo"', 'Todos os tipos') ?></div>
    <div class="col-md-2"><?= sel('status', lista_rotulos('ot_status'), get('status'), true, 'class="form-select form-select-sm" aria-label="Status"', 'Todos os status') ?></div>
    <div class="col-md-2"><?= sel('resp', $tecnicos, get('resp'), true, 'class="form-select form-select-sm" aria-label="Responsável"', 'Todos os responsáveis') ?></div>
    <div class="col-6 col-md-1"><input type="date" class="form-control form-control-sm" name="de" value="<?= e(get('de')) ?>" title="Aberta a partir de" aria-label="Aberta a partir de"></div>
    <div class="col-6 col-md-1"><input type="date" class="form-control form-control-sm" name="ate" value="<?= e(get('ate')) ?>" title="Aberta até" aria-label="Aberta até"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-secondary w-100">Filtrar</button></div>
  </div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
<thead><tr><th>Nº</th><th class="d-none d-md-table-cell">Tipo</th><th>Título</th><th class="d-none d-md-table-cell">Ativo</th><th class="d-none d-lg-table-cell">Responsável</th><th>Prevista</th><th class="d-none d-md-table-cell">Prioridade</th><th class="text-end d-none d-lg-table-cell">Custo</th><th>Status</th></tr></thead><tbody>
<?php foreach ($pg['linhas'] as $o): $atr = !in_array($o['status'], ['concluida', 'cancelada']) && $o['data_prevista'] && $o['data_prevista'] < date('Y-m-d'); ?>
  <tr data-href="<?= e(url('ots/ver', ['id' => $o['id']])) ?>" class="<?= $atr ? 'atrasada' : '' ?>"><td class="text-nowrap"><?= e($o['numero']) ?></td><td class="d-none d-md-table-cell"><?= badge('tipo_ot', $o['tipo']) ?></td><td><?= e($o['titulo']) ?><div class="small text-muted d-md-none"><?= e($o['ativo_codigo']) ?></div></td>
  <td class="d-none d-md-table-cell"><?= e($o['ativo_codigo']) ?></td><td class="d-none d-lg-table-cell"><?= e($o['responsavel']) ?></td><td class="<?= $atr ? 'text-danger fw-semibold' : '' ?>"><?= dt($o['data_prevista']) ?></td>
  <td class="d-none d-md-table-cell"><?= badge('prioridade', $o['prioridade']) ?></td><td class="text-end d-none d-lg-table-cell"><?= moeda($o['custo']) ?></td><td><?= badge('ot_status', $o['status']) ?></td></tr>
<?php endforeach; if (!$pg['linhas']): ?><tr><td colspan="9" class="text-center text-muted py-4">Nenhuma ordem de trabalho.</td></tr><?php endif; ?>
</tbody></table></div><div class="card-footer bg-white"><?= paginacao_html($pg) ?></div></div>
<?php view_footer();
