<?php
declare(strict_types=1);

/** Aplica migrações pendentes de database/migracoes/*.sql. Retorna os nomes aplicados. */
function aplicar_migracoes(?callable $log = null): array
{
    db()->exec('CREATE TABLE IF NOT EXISTS migracoes (nome VARCHAR(120) PRIMARY KEY, aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $feitas = array_column(q('SELECT nome FROM migracoes'), 'nome');
    $arquivos = glob(APP_ROOT . '/database/migracoes/*.sql') ?: [];
    sort($arquivos);
    $aplicadas = [];
    foreach ($arquivos as $arq) {
        $nome = basename($arq);
        if (in_array($nome, $feitas, true)) continue;
        $log && $log("→ $nome");
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
