<?php
declare(strict_types=1);

/**
 * Registra movimento de estoque e atualiza saldo/custo médio ponderado.
 * $qtd sempre positiva; para 'ajuste' informe o NOVO saldo em $qtd.
 * Lança RuntimeException se a saída deixar o saldo negativo.
 */
function movimentar_estoque(int $artigoId, string $tipo, float $qtd, ?float $custo = null, ?int $otId = null, ?string $doc = null, ?string $obs = null): float
{
    $a = q1('SELECT * FROM artigos WHERE id = ? FOR UPDATE', [$artigoId]);
    if (!$a) throw new RuntimeException('Artigo não encontrado');
    $saldo = (float)$a['estoque_atual'];
    $cm = (float)$a['custo_medio'];

    switch ($tipo) {
        case 'entrada':
            $custo = $custo ?? $cm;
            $novo = $saldo + $qtd;
            $cm = $novo > 0 ? (($saldo > 0 ? $saldo * $cm : 0) + $qtd * $custo) / $novo : $custo;
            $movQtd = $qtd;
            break;
        case 'saida':
            if ($qtd > $saldo + 1e-9) throw new RuntimeException("Estoque insuficiente de {$a['codigo']} (saldo " . num($saldo, 2) . " {$a['unidade']}).");
            $novo = $saldo - $qtd; $custo = $cm; $movQtd = $qtd;
            break;
        case 'ajuste':
            $novo = $qtd; $movQtd = $qtd - $saldo; $custo = $cm;
            break;
        default:
            throw new InvalidArgumentException('Tipo de movimento inválido');
    }
    qx('UPDATE artigos SET estoque_atual = ?, custo_medio = ? WHERE id = ?', [$novo, round($cm, 4), $artigoId]);
    insert('movimentos_estoque', [
        'artigo_id' => $artigoId, 'tipo' => $tipo, 'quantidade' => $movQtd, 'custo_unitario' => $custo,
        'saldo_apos' => $novo, 'ot_id' => $otId, 'documento' => $doc, 'observacao' => $obs, 'usuario_id' => usuario()['id'] ?? null,
    ]);
    return (float)$custo;
}
