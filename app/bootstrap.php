<?php
declare(strict_types=1);

ini_set('display_errors', '0');   // antes de ler o config: erro de sintaxe nele não aparece na tela
define('APP_ROOT', dirname(__DIR__));
const APP_VERSAO = '3.1.0';

$cfgFile = APP_ROOT . '/config/config.php';
if (!is_file($cfgFile)) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "config/config.php não encontrado. Copie config/config.sample.php e ajuste.\n"); exit(1); }
    http_response_code(500);
    exit('Arquivo config/config.php não encontrado. Copie config/config.sample.php e rode install.php.');
}
$GLOBALS['CFG'] = require $cfgFile;

date_default_timezone_set(cfg('timezone', 'America/Sao_Paulo'));
error_reporting(E_ALL);
ini_set('display_errors', (cfg('debug') && PHP_SAPI === 'cli') ? '1' : '0'); // na web, erros vão só para o log
ini_set('log_errors', '1');

require APP_ROOT . '/app/helpers.php';
require APP_ROOT . '/app/auth.php';
require APP_ROOT . '/app/seguranca.php';
require APP_ROOT . '/app/preventiva.php';
require APP_ROOT . '/app/estoque.php';
require APP_ROOT . '/app/kpi.php';
require APP_ROOT . '/app/email.php';
require APP_ROOT . '/app/tabela.php';
require APP_ROOT . '/app/configuracoes.php';

config_db_aplicar(); // valores definidos na tela Configurações (sobrepõem o config.php nas chaves permitidas)

function cfg(string $chave, $padrao = null)
{
    $v = $GLOBALS['CFG'];
    foreach (explode('.', $chave) as $p) {
        if (!is_array($v) || !array_key_exists($p, $v)) return $padrao;
        $v = $v[$p];
    }
    return $v;
}

function db_conectado(): bool { return isset($GLOBALS['__pdo']); }

function db(): PDO
{
    $pdo = &$GLOBALS['__pdo'];
    if ($pdo === null) {
        $d = cfg('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $d['host'], $d['port'] ?? 3306, $d['nome'], $d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['usuario'], $d['senha'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}
