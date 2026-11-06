<?php
declare(strict_types=1);

/**
 * Motor de manutenção preventiva.
 *  - Planos por TEMPO: gera OT quando (proxima_data - antecedência) <= hoje.
 *  - Planos por CONTADOR: gera OT quando valor_atual >= (proximo_valor - antecedência em unidades).
 *  - Nunca gera nova OT se o plano já tiver OT pendente (checagem sob lock do plano).
 *  - Ao concluir a OT, o plano é reprogramado (base fixa = calendário; base conclusão = a partir da execução).
 *  - Ao cancelar a OT, a ocorrência pode ser "pulada" (reprograma sem registrar execução).
 */

/**
 * Soma $qtd períodos a $data. Para mês/ano, usa o dia-âncora (dia original do plano) limitado ao
 * último dia do mês, para que 31/01 → 28/02 → 31/03 (e não 28/03).
 */
function somar_periodo(string $data, int $qtd, string $unidade, ?int $diaAncora = null): string
{
    $d = new DateTime($data);
    if ($unidade === 'mes' || $unidade === 'ano') {
        $dia = $diaAncora ?: (int)$d->format('d');
        $meses = $unidade === 'ano' ? 12 * $qtd : $qtd;
        $d->modify('first day of this month')->modify("+$meses month");
        $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($dia, (int)$d->format('t')));
    } else {
        $d->modify('+' . ($unidade === 'semana' ? 7 * $qtd : $qtd) . ' day');
    }
    return $d->format('Y-m-d');
}

function periodo_em_dias(int $qtd, string $unidade): int
{
    return $qtd * (['dia' => 1, 'semana' => 7, 'mes' => 28, 'ano' => 365][$unidade] ?? 1);
}

function plano_tem_ot_pendente(int $planoId): bool
{
    return (bool)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE plano_id = ? AND status IN (" . STATUS_PENDENTES . ")", [$planoId]);
}

/**
 * Cria a OT do plano (tarefas e materiais copiados). Trava o plano e confere de novo se já
 * existe OT pendente, então chamadas simultâneas (cron + botão + leitura) não duplicam.
 * Retorna o id da OT, ou null se outra execução já criou.
 */
function gerar_ot_de_plano(array $p, ?string $dataPrevista = null, ?int $usuarioId = null): ?int
{
    $pdo = db();
    $propria = !$pdo->inTransaction();
    if ($propria) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT gerar_ot'); // falha parcial não vaza para a transação de fora
    try {
        $p = q1('SELECT * FROM planos_manutencao WHERE id = ? FOR UPDATE', [$p['id']]) ?? $p;
        if (plano_tem_ot_pendente((int)$p['id'])) { if ($propria) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT gerar_ot'); return null; }
        $otId = insert('ordens_trabalho', [
            'numero'         => proximo_numero('ordens_trabalho', 'OT'),
            'ativo_id'       => $p['ativo_id'],
            'plano_id'       => $p['id'],
            'tipo'           => $p['tipo'],
            'prioridade'     => $p['prioridade'],
            'status'         => $p['responsavel_id'] ? 'planejada' : 'aberta',
            'titulo'         => $p['titulo'],
            'descricao'      => $p['descricao'],
            'responsavel_id' => $p['responsavel_id'],
            'data_prevista'  => $dataPrevista ?? ($p['proxima_data'] ?: date('Y-m-d')),
            'valor_previsto' => $p['gatilho'] === 'contador' ? $p['proximo_valor'] : null,
            'criado_por'     => $usuarioId,
        ]);
        qx('INSERT INTO ot_tarefas (ot_id, ordem, descricao) SELECT ?, ordem, descricao FROM plano_tarefas WHERE plano_id = ? ORDER BY ordem, id', [$otId, $p['id']]);
        qx('INSERT INTO ot_materiais (ot_id, artigo_id, quantidade, custo_unitario, baixado)
            SELECT ?, pm.artigo_id, pm.quantidade, a.custo_medio, 0 FROM plano_materiais pm JOIN artigos a ON a.id = pm.artigo_id WHERE pm.plano_id = ?', [$otId, $p['id']]);
        if ($propria) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT gerar_ot');
    } catch (Throwable $ex) {
        if ($propria) { if ($pdo->inTransaction()) $pdo->rollBack(); }
        elseif ($pdo->inTransaction()) $pdo->exec('ROLLBACK TO SAVEPOINT gerar_ot');
        throw $ex;
    }
    auditar('gerar_ot', 'planos_manutencao', (int)$p['id'], "OT #$otId gerada automaticamente");
    notificar_ot_atribuida($otId);
    return $otId;
}

/** Varre os planos ativos e gera as OTs devidas. Um plano com erro não interrompe os demais. */
function gerar_preventivas(?int $usuarioId = null, ?int $contadorId = null): array
{
    $hoje = date('Y-m-d');
    $geradas = [];
    $planos = q("SELECT p.*, c.valor_atual AS contador_valor
                 FROM planos_manutencao p
                 JOIN ativos a ON a.id = p.ativo_id AND a.status <> 'desativado'
                 LEFT JOIN contadores c ON c.id = p.contador_id
                 WHERE p.ativo = 1" . ($contadorId ? ' AND p.contador_id = ' . (int)$contadorId : '') . "
                   AND ((p.gatilho = 'tempo' AND p.proxima_data <= ? + INTERVAL p.antecedencia_dias DAY)
                     OR (p.gatilho = 'contador' AND c.valor_atual >= p.proximo_valor - p.antecedencia_dias))
                   AND NOT EXISTS (SELECT 1 FROM ordens_trabalho o WHERE o.plano_id = p.id AND o.status IN (" . STATUS_PENDENTES . "))", [$hoje]);
    foreach ($planos as $p) {
        try {
            $ot = null;
            if ($p['gatilho'] === 'tempo') {
                if (!$p['proxima_data']) continue;
                $limite = (new DateTime($p['proxima_data']))->modify('-' . (int)$p['antecedencia_dias'] . ' day')->format('Y-m-d');
                if ($limite <= $hoje) $ot = gerar_ot_de_plano($p, $p['proxima_data'], $usuarioId);
            } else {
                if ($p['proximo_valor'] === null || $p['contador_valor'] === null) continue;
                if ((float)$p['contador_valor'] >= (float)$p['proximo_valor'] - (float)$p['antecedencia_dias']) $ot = gerar_ot_de_plano($p, $hoje, $usuarioId);
            }
            if ($ot) $geradas[] = ['plano' => $p, 'ot_id' => $ot];
        } catch (Throwable $ex) {
            error_log("[MZManut] falha ao gerar OT do plano #{$p['id']}: " . $ex->getMessage());
        }
    }
    return $geradas;
}

/**
 * Reprograma o plano a partir de uma OT encerrada.
 *  $pular = false → OT concluída: registra a execução e calcula a próxima.
 *  $pular = true  → OT cancelada: apenas avança para a próxima ocorrência (sem registrar execução).
 * Idempotente: usa a referência gravada na OT (data_prevista / valor_previsto), não o estado atual do plano.
 */
function reprogramar_plano_apos_ot(int $otId, bool $pular = false): void
{
    $ot = q1('SELECT * FROM ordens_trabalho WHERE id = ?', [$otId]);
    if (!$ot || !$ot['plano_id'] || $ot['status'] !== ($pular ? 'cancelada' : 'concluida')) return;
    $p = q1('SELECT * FROM planos_manutencao WHERE id = ? FOR UPDATE', [$ot['plano_id']]);
    if (!$p) return;

    $hoje = date('Y-m-d');
    $dataExec = $pular ? $hoje : substr($ot['data_fim'] ?: date('Y-m-d H:i:s'), 0, 10);
    $dados = $pular ? [] : ['ultima_execucao' => $dataExec];

    if ($p['gatilho'] === 'tempo' && $p['periodicidade']) {
        $per = (int)$p['periodicidade']; $un = $p['unidade_periodo'] ?: 'mes';
        $ancora = $p['dia_ancora'] ? (int)$p['dia_ancora'] : null;
        if ($p['base_calculo'] === 'conclusao' && !$pular) {
            $prox = somar_periodo($dataExec, $per, $un);
            $dados['dia_ancora'] = (int)substr($prox, 8, 2);
        } else {
            $ref = $ot['data_prevista'] ?: ($p['proxima_data'] ?: $dataExec);
            $prox = somar_periodo($ref, $per, $un, $ancora);
            $guard = 0;
            while ($prox <= $dataExec && $guard++ < 1000) $prox = somar_periodo($prox, $per, $un, $ancora);
        }
        $dados['proxima_data'] = $prox;
    }

    if ($p['gatilho'] === 'contador' && $p['intervalo_contador']) {
        $int = (float)$p['intervalo_contador'];
        $contAtual = (float)qv('SELECT valor_atual FROM contadores WHERE id = ?', [$p['contador_id']]);
        $exec = $ot['leitura_contador'] !== null ? (float)$ot['leitura_contador'] : $contAtual;
        $ref = $ot['valor_previsto'] !== null ? (float)$ot['valor_previsto'] : (float)($p['proximo_valor'] ?? $exec);
        if ($p['base_calculo'] === 'conclusao' && !$pular) {
            $prox = $exec + $int;
        } else {
            $prox = $ref + $int;
            $guard = 0;
            while ($prox <= $contAtual && $guard++ < 100000) $prox += $int;
        }
        if (!$pular) $dados['ultimo_valor'] = $exec;
        $dados['proximo_valor'] = $prox;
    }
    if ($dados) update('planos_manutencao', $dados, (int)$p['id']);
}

/** Projeta as próximas datas de um plano por tempo até $ate (calendário). Sem deriva de dia. */
function projetar_plano(array $p, string $ate, int $max = 60): array
{
    if ($p['gatilho'] !== 'tempo' || !$p['proxima_data'] || !$p['periodicidade']) return [];
    $un = $p['unidade_periodo'] ?: 'mes';
    $ancora = !empty($p['dia_ancora']) ? (int)$p['dia_ancora'] : (int)substr($p['proxima_data'], 8, 2);
    $datas = [];
    for ($i = 0; count($datas) < $max; $i++) {
        $d = $i === 0 ? $p['proxima_data'] : somar_periodo($p['proxima_data'], (int)$p['periodicidade'] * $i, $un, $ancora);
        if ($d > $ate) break;
        $datas[] = $d;
    }
    return $datas;
}

/**
 * Estima quando um plano por contador vence, pela taxa de uso das leituras desde a última
 * zeragem/troca do contador (usa horas reais entre leituras, não dias inteiros).
 */
function estimar_data_contador(array $p): ?string
{
    if ($p['gatilho'] !== 'contador' || !$p['contador_id'] || $p['proximo_valor'] === null) return null;
    $todas = q('SELECT valor, data_leitura FROM leituras_contador WHERE contador_id = ? ORDER BY data_leitura DESC, id DESC LIMIT 30', [$p['contador_id']]);
    $l = [];
    foreach ($todas as $x) {               // da mais recente para trás, até a zeragem
        if ($l && (float)$x['valor'] > (float)end($l)['valor']) break;
        $l[] = $x;
        if (count($l) >= 10) break;
    }
    if (count($l) < 2) return null;
    $rec = $l[0]; $ant = end($l);
    $seg = strtotime($rec['data_leitura']) - strtotime($ant['data_leitura']);
    if ($seg < 3600) return null;
    $porSeg = ((float)$rec['valor'] - (float)$ant['valor']) / $seg;
    if ($porSeg <= 0) return null;
    $falta = max(0, (float)$p['proximo_valor'] - (float)$rec['valor']);
    return date('Y-m-d', (int)(strtotime($rec['data_leitura']) + $falta / $porSeg));
}
