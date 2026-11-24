<?php
/**
 * Importa dados do ManWinWin pela linha de comando.
 *   php tools/importar_manwinwin.php ARQUIVO.zip|ARQUIVO.mdb                 → simulação (nada é gravado)
 *   php tools/importar_manwinwin.php ARQUIVO.zip --aplicar                    → grava
 * Opções:
 *   --usuario=email     usuário registrado como autor (padrão: primeiro administrador)
 *   --incluir-modelo    importa também os exemplos "Modelo" que vêm com o ManWinWin
 *   --incluir-artigos   importa o catálogo de artigos (MestreArtigos) e os saldos
 *   --detalhes          lista cada registro novo e cada item ignorado
 * O .zip é gerado por tools/manwinwin/exportar_manwinwin.bat (Windows) ou exportar_manwinwin.sh (Linux/macOS).
 * Faça backup do banco antes de --aplicar.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
require APP_ROOT . '/app/importacao_manwinwin.php';

$args = array_slice($argv, 1);
$arquivo = null; $flags = [];
foreach ($args as $a) { if (str_starts_with($a, '--')) { [$k, $v] = array_pad(explode('=', substr($a, 2), 2), 2, true); $flags[$k] = $v; } else $arquivo = $a; }
if (!$arquivo || !is_file($arquivo)) { fwrite(STDERR, "Uso: php tools/importar_manwinwin.php ARQUIVO.zip|.mdb [--aplicar] [--usuario=email] [--incluir-modelo] [--incluir-artigos] [--detalhes]\n"); exit(1); }

$email = is_string($flags['usuario'] ?? null) ? mb_strtolower($flags['usuario']) : null;
$uid = $email ? qv("SELECT id FROM usuarios WHERE email = ? AND perfil IN ('admin','gestor')", [$email])
              : qv("SELECT id FROM usuarios WHERE perfil = 'admin' AND ativo = 1 ORDER BY id LIMIT 1");
if (!$uid) { fwrite(STDERR, "Usuário administrador/gestor não encontrado" . ($email ? ": $email" : '') . ".\n"); exit(1); }
try {
    if (!qv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'importacao_mapa'")) {
        throw new RuntimeException('Rode antes: php migrate.php');
    }
    $tabelas = mww_ler_arquivo($arquivo, basename($arquivo));
    $aplicar = isset($flags['aplicar']);
    $rel = (new ImportadorManWinWin($tabelas, ['incluir_modelo' => isset($flags['incluir-modelo']), 'incluir_artigos' => isset($flags['incluir-artigos'])], (int)$uid))->executar($aplicar);
} catch (Throwable $ex) {
    fwrite(STDERR, 'ERRO: ' . $ex->getMessage() . "\n");
    exit(1);
}

echo ($aplicar ? "IMPORTAÇÃO GRAVADA" : "SIMULAÇÃO (nada foi gravado — use --aplicar para gravar)") . "\n\n";
printf("%-24s %8s %10s\n", '', 'novos', 'já exist.');
foreach (MWW_ROTULOS as $t => $r) {
    $c = $rel['criados'][$t] ?? 0; $e = $rel['existentes'][$t] ?? 0;
    if ($c || $e) printf("%-24s %8d %10d\n", $r, $c, $e);
}
if (!empty($rel['planos_inativos'])) echo "\n" . count($rel['planos_inativos']) . " plano(s) importado(s) INATIVOS (sem programação no ManWinWin) — defina a próxima data e ative em Planos.\n";
if ($rel['avisos']) { echo "\nAvisos:\n"; foreach ($rel['avisos'] as $a) echo "  ! $a\n"; }
if ($rel['ignorados']) echo "\n" . count($rel['ignorados']) . " registro(s) ignorado(s)" . (isset($flags['detalhes']) ? ':' : ' (use --detalhes para listar)') . "\n";
if (isset($flags['detalhes'])) {
    foreach ($rel['ignorados'] as [$t, $i, $m]) echo '  - [' . (MWW_ROTULOS[$t] ?? $t) . "] $i: $m\n";
    foreach ($rel['novos'] as $t => $itens) { echo "\n" . (MWW_ROTULOS[$t] ?? $t) . " (novos):\n"; foreach ($itens as $i) echo "  + $i\n"; }
    if (!empty($rel['planos_inativos'])) { echo "\nPlanos inativos:\n"; foreach ($rel['planos_inativos'] as $i) echo "  · $i\n"; }
}
if ($aplicar) auditar('importacao', 'manwinwin', null, 'CLI ' . basename($arquivo) . ': ' . mww_resumo($rel));
