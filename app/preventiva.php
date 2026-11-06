<?php
declare(strict_types=1);

/**
 * Motor de manutenção preventiva.
 *  - Planos por TEMPO: gera OT quando (proxima_data - antecedência) <= hoje.
 *  - Planos por CONTADOR: gera OT quando valor_atual >= (proximo_valor - antecedência em unidades).
 *  - Nunca gera nova OT se o plano já tiver OT pendente (não concluída/cancelada).
 *  - Ao concluir a OT, o plano é reprogramado (base fixa = calendário; base conclusão = a partir da execução).
 */

function somar_periodo(string $data, int $qtd, string $unidade): string
{
    $mapa = ['dia' => 'day', 'semana' => 'week', 'mes' => 'month', 'ano' => 'year'];
    $d = new DateTime($data);
    if ($unidade === 'mes' || $unidade === 'ano') {
        // evita 31/01 + 1 mês = 03/03
        $dia = (int)$d->format('d');
        $d->modify('first day of this month')->modify("+$qtd " . $mapa[$unidade]);
        $d->setDate((int)$d->format('Y'), (int)$d->format('m'), min($dia, (int)$d->format('t')));
    } else {
        $d->modify("+$qtd " . ($mapa[$unidade] ?? 'day'));
    }
    return $d->format('Y-m-d');
}

function plano_tem_ot_pendente(int $planoId): bool
{
    return (bool)qv("SELECT COUNT(*) FROM ordens_trabalho WHERE plano_id = ? AND status NOT IN ('concluida','cancelada')", [$planoId]);
}

function gerar_ot_de_plano(array $p, ?string $dataPrevista = null, ?int $usuarioId = null): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $otId = insert('ordens_trabalho', [
            'numero'         => proximo_numero('ordens_trabalho', 'OT'),
            'ativo_id'       => $p['ativo_id'],
            'plano_id'       => $p['id'],
            'tipo'           => $p['tipo'],
            'prioridade'     => $p['prioridade'],
            'status'         => 'planejada',
            'titulo'         => $p['titulo'],
            'descricao'      => $p['descricao'],
            'responsavel_id' => $p['responsavel_id'],
            'data_prevista'  => $dataPrevista ?? ($p['proxima_data'] ?: date('Y-m-d')),
            'criado_por'     => $usuarioId,
        ]);
        foreach (q('SELECT ordem, descricao FROM plano_tarefas WHERE plano_id = ? ORDER BY ordem, id', [$p['id']]) as $t) {
            insert('ot_tarefas', ['ot_id' => $otId, 'ordem' => $t['ordem'], 'descricao' => $t['descricao']]);
        }
        foreach (q('SELECT pm.artigo_id, pm.quantidade, a.custo_medio FROM plano_materiais pm JOIN artigos a ON a.id = pm.artigo_id WHERE pm.plano_id = ?', [$p['id']]) as $m) {
            insert('ot_materiais', ['ot_id' => $otId, 'artigo_id' => $m['artigo_id'], 'quantidade' => $m['quantidade'], 'custo_unitario' => $m['custo_medio'], 'baixado' => 0]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    auditar('gerar_ot', 'planos_manutencao', (int)$p['id'], "OT #$otId gerada automaticamente");
    return $otId;
}

/** Varre todos os planos ativos e gera as OTs devidas. Retorna lista de [plano, ot_id]. */
function gerar_preventivas(?int $usuarioId = null): array
{
    $hoje = date('Y-m-d');
    $geradas = [];
    $planos = q("SELECT p.*, c.valor_atual AS contador_valor
                 FROM planos_manutencao p
                 JOIN ativos a ON a.id = p.ativo_id AND a.status <> 'desativado'
                 LEFT JOIN contadores c ON c.id = p.contador_id
                 WHERE p.ativo = 1");
    foreach ($planos as $p) {
        if (plano_tem_ot_pendente((int)$p['id'])) continue;

        if ($p['gatilho'] === 'tempo') {
            if (!$p['proxima_data']) continue;
            $limite = (new DateTime($p['proxima_data']))->modify('-' . (int)$p['antecedencia_dias'] . ' day')->format('Y-m-d');
            if ($limite <= $hoje) {
                $geradas[] = ['plano' => $p, 'ot_id' => gerar_ot_de_plano($p, $p['proxima_data'], $usuarioId)];
            }
        } else {
            if ($p['proximo_valor'] === null || $p['contador_valor'] === null) continue;
            if ((float)$p['contador_valor'] >= (float)$p['proximo_valor'] - (float)$p['antecedencia_dias']) {
                $geradas[] = ['plano' => $p, 'ot_id' => gerar_ot_de_plano($p, $hoje, $usuarioId)];
            }
        }
    }
    return $geradas;
}

/** Reprograma o plano depois que a OT gerada por ele é concluída. */
function reprogramar_plano_apos_ot(int $otId): void
{
    $ot = q1('SELECT * FROM ordens_trabalho WHERE id = ?', [$otId]);
    if (!$ot || !$ot['plano_id'] || $ot['status'] !== 'concluida') return;
    $p = q1('SELECT * FROM planos_manutencao WHERE id = ?', [$ot['plano_id']]);
    if (!$p) return;

    $dataExec = substr($ot['data_fim'] ?: date('Y-m-d H:i:s'), 0, 10);
    $dados = ['ultima_execucao' => $dataExec];

    if ($p['gatilho'] === 'tempo' && $p['periodicidade']) {
        $per = (int)$p['periodicidade']; $un = $p['unidade_periodo'] ?: 'mes';
        if ($p['base_calculo'] === 'conclusao') {
            $prox = somar_periodo($dataExec, $per, $un);
        } else {
            $prox = somar_periodo($ot['data_prevista'] ?: ($p['proxima_data'] ?: $dataExec), $per, $un);
            $guard = 0;
            while ($prox <= $dataExec && $guard++ < 500) $prox = somar_periodo($prox, $per, $un);
        }
        $dados['proxima_data'] = $prox;
    }

    if ($p['gatilho'] === 'contador' && $p['intervalo_contador']) {
        $atual = $ot['leitura_contador'] !== null ? (float)$ot['leitura_contador']
               : (float)qv('SELECT valor_atual FROM contadores WHERE id = ?', [$p['contador_id']]);
        $int = (float)$p['intervalo_contador'];
        if ($p['base_calculo'] === 'conclusao' || $p['proximo_valor'] === null) {
            $prox = $atual + $int;
        } else {
            $prox = (float)$p['proximo_valor'] + $int;
            $guard = 0;
            while ($prox <= $atual && $guard++ < 10000) $prox += $int;
        }
        $dados['ultimo_valor'] = $atual;
        $dados['proximo_valor'] = $prox;
    }
    update('planos_manutencao', $dados, (int)$p['id']);
}

/** Projeta as próximas datas de um plano por tempo até $ate (para calendário/previsão de carga). */
function projetar_plano(array $p, string $ate, int $max = 60): array
{
    if ($p['gatilho'] !== 'tempo' || !$p['proxima_data'] || !$p['periodicidade']) return [];
    $datas = []; $d = $p['proxima_data'];
    while ($d <= $ate && count($datas) < $max) {
        $datas[] = $d;
        $d = somar_periodo($d, (int)$p['periodicidade'], $p['unidade_periodo'] ?: 'mes');
    }
    return $datas;
}

/** Estima a data em que um plano por contador vence, com base na média de uso das últimas leituras. */
function estimar_data_contador(array $p): ?string
{
    if ($p['gatilho'] !== 'contador' || !$p['contador_id'] || $p['proximo_valor'] === null) return null;
    $l = q('SELECT valor, data_leitura FROM leituras_contador WHERE contador_id = ? ORDER BY data_leitura DESC LIMIT 10', [$p['contador_id']]);
    if (count($l) < 2) return null;
    $ult = end($l); $pri = $l[0];
    $dias = max(1, (strtotime($pri['data_leitura']) - strtotime($ult['data_leitura'])) / 86400);
    $porDia = ((float)$pri['valor'] - (float)$ult['valor']) / $dias;
    if ($porDia <= 0) return null;
    $falta = (float)$p['proximo_valor'] - (float)$pri['valor'];
    return date('Y-m-d', (int)(strtotime($pri['data_leitura']) + max(0, $falta / $porDia) * 86400));
}
