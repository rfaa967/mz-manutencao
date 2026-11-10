<?php
declare(strict_types=1);

/** Aplica migrações pendentes de database/migracoes/*.sql. Retorna os nomes aplicados. */
function migracoes_tabela(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS migracoes (nome VARCHAR(120) PRIMARY KEY, aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

/** Verificações que precisam passar ANTES de uma migração (dados que a fariam falhar no meio). */
function migracao_prechecagem(string $nome): void
{
    if (str_starts_with($nome, '005_')) {
        $dup = q('SELECT pedido_id, GROUP_CONCAT(numero ORDER BY id) AS ots FROM ordens_trabalho WHERE pedido_id IS NOT NULL GROUP BY pedido_id HAVING COUNT(*) > 1');
        if ($dup) {
            throw new RuntimeException("A migração $nome exige no máximo uma OT por pedido. Desvincule as OTs duplicadas (UPDATE ordens_trabalho SET pedido_id = NULL WHERE numero = '...') e rode de novo:\n"
                . implode("\n", array_map(fn($d) => "  pedido #{$d['pedido_id']}: {$d['ots']}", $dup)));
        }
    }
}

function aplicar_migracoes(?callable $log = null): array
{
    // trava: duas execuções simultâneas (duplo clique, dois admins, cron + web) reexecutariam os mesmos comandos
    if ((int)qv("SELECT GET_LOCK('mzmanut_migracoes', 0)") !== 1) throw new RuntimeException('Já existe uma atualização do banco em andamento. Aguarde e recarregue a página.');
    @set_time_limit(0);
    ignore_user_abort(true);
    try { return aplicar_migracoes_trava($log); }
    finally { qv("SELECT RELEASE_LOCK('mzmanut_migracoes')"); }
}

function aplicar_migracoes_trava(?callable $log): array
{
    migracoes_tabela();
    $feitas = array_column(q('SELECT nome FROM migracoes'), 'nome');
    $arquivos = glob(APP_ROOT . '/database/migracoes/*.sql') ?: [];
    sort($arquivos);
    $aplicadas = [];
    foreach ($arquivos as $arq) {
        $nome = basename($arq);
        if (in_array($nome, $feitas, true)) continue;
        $log && $log("→ $nome");
        migracao_prechecagem($nome);
        $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents($arq));
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            try { db()->exec($stmt); }
            catch (PDOException $ex) {
                // tolera reaplicação parcial: coluna/índice/tabela/FK já existente ou já removida
                if (!in_array((int)($ex->errorInfo[1] ?? 0), [1060, 1061, 1050, 1826, 1022, 1091], true)) throw $ex;
            }
        }
        insert('migracoes', ['nome' => $nome]);
        $aplicadas[] = $nome;
    }
    return $aplicadas;
}
