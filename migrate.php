<?php
/**
 * Aplica as migrações pendentes de database/migracoes/ (em ordem de nome).
 *   php migrate.php            → aplica
 *   php migrate.php --status   → lista
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/migracoes.php';

if (in_array('--status', $argv, true)) {
    db()->exec('CREATE TABLE IF NOT EXISTS migracoes (nome VARCHAR(120) PRIMARY KEY, aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $feitas = array_column(q('SELECT nome FROM migracoes'), 'nome');
    foreach (glob(__DIR__ . '/database/migracoes/*.sql') as $a) echo (in_array(basename($a), $feitas, true) ? '[x] ' : '[ ] ') . basename($a) . "\n";
    exit;
}
try {
    $ap = aplicar_migracoes(fn($m) => print("$m\n"));
    echo $ap ? count($ap) . " migração(ões) aplicada(s).\n" : "Migrações em dia.\n";
} catch (Throwable $ex) {
    fwrite(STDERR, 'ERRO: ' . $ex->getMessage() . "\n");
    exit(1);
}
