<?php
/**
 * Importa as planilhas de controle de OM (OMM/OME/OMC, Excel .xlsx) pela linha de comando.
 *   php tools/importar_om.php OMM.xlsx OME.xlsx OMC.xlsx             → simulação (nada é gravado)
 *   php tools/importar_om.php OMM.xlsx OME.xlsx OMC.xlsx --aplicar   → grava
 * Opções: --usuario=email  --detalhes
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/importacao_om.php';

$arqs = []; $flags = [];
foreach (array_slice($argv, 1) as $a) { if (str_starts_with($a, '--')) { [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true); $flags[$k] = $v; } else $arqs[] = $a; }
if (!$arqs) { fwrite(STDERR, "Uso: php tools/importar_om.php ARQUIVO.xlsx [...] [--aplicar] [--usuario=email] [--detalhes]\n"); exit(1); }
foreach ($arqs as $a) if (!is_file($a)) { fwrite(STDERR, "Arquivo não encontrado: $a\n"); exit(1); }
$email = is_string($flags['usuario'] ?? null) ? mb_strtolower($flags['usuario']) : null;
if ($email && !qv("SELECT 1 FROM usuarios WHERE email = ? AND perfil IN ('admin','gestor')", [$email])) { fwrite(STDERR, "Usuário administrador/gestor não encontrado: $email\n"); exit(1); }
$uid = $email ? qv("SELECT id FROM usuarios WHERE email = ? AND perfil IN ('admin','gestor')", [$email]) : qv("SELECT id FROM usuarios WHERE perfil = 'admin' AND ativo = 1 ORDER BY id LIMIT 1");
try {
    if (!qv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ordens_trabalho' AND column_name = 'area'")) throw new RuntimeException('Rode antes: php migrate.php');
    $aplicar = isset($flags['aplicar']);
    $rel = (new ImportadorPlanilhasOM(array_map(fn($a) => ['caminho' => $a, 'nome' => basename($a)], $arqs), $uid ? (int)$uid : null))->executar($aplicar);
} catch (Throwable $ex) { fwrite(STDERR, 'ERRO: ' . $ex->getMessage() . "\n"); exit(1); }

echo ($aplicar ? 'IMPORTAÇÃO GRAVADA' : 'SIMULAÇÃO (nada foi gravado — use --aplicar)') . "\n\n";
foreach (OM_ROTULOS as $t => $r) printf("%-26s novos %5d   atualizados %5d   já importados %5d\n", $r, $rel['criados'][$t] ?? 0, $rel['atualizados'][$t] ?? 0, $rel['existentes'][$t] ?? 0);
$st = $rel['resumo']['status'] ?? [];
echo "\nSituação: " . implode(', ', array_map(fn($k, $v) => rotulo('ot_status', $k) . " $v", array_keys($st), $st)) . "\n";
echo 'Por área: ' . implode(', ', array_map(fn($k, $v) => ($k ? rotulo('area', $k) : 'sem área') . " $v", array_keys($rel['resumo']['area'] ?? []), $rel['resumo']['area'] ?? [])) . "\n";
echo 'Ligadas a ativo pelo patrimônio: ' . ($rel['resumo']['ligadas_ativo'] ?? 0) . ' · repetidas entre meses (unificadas): ' . ($rel['resumo']['repetidas'] ?? 0) . "\n";
foreach ($rel['avisos'] as $a) echo "! $a\n";
echo count($rel['ignorados']) . " linha(s) ignorada(s)\n";
if (isset($flags['detalhes'])) {
    foreach ($rel['ignorados'] as [$t, $i, $m]) echo "  - $i: $m\n";
    if ($rel['alterados']) { echo "\nOMs atualizadas:\n"; foreach ($rel['alterados'] as $i) echo "  ~ $i\n"; }
    foreach ($rel['novos'] as $t => $itens) { echo "\n" . (OM_ROTULOS[$t] ?? $t) . ":\n"; foreach ($itens as $i) echo "  + $i\n"; }
}
if ($aplicar) auditar('importacao', 'planilha_om', null, 'CLI ' . implode(', ', array_map('basename', $arqs)) . ': ' . om_resumo($rel));
