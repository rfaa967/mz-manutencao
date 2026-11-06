<?php
/**
 * Instalador (linha de comando)
 *   php install.php --email=admin@empresa.com.br [--nome="Administrador"] [--demo]
 *   (a senha é pedida no terminal; em scripts use --senha=... ou a variável MZ_ADMIN_SENHA)
 *
 * ATENÇÃO: recria todas as tabelas (DROP). Use apenas na instalação; para atualizar use migrate.php.
 * Se o banco já tiver usuários, é preciso confirmar digitando o nome do banco (ou --recriar em scripts).
 *   --demo     dados de demonstração (usuários *@demo.local com a MESMA senha do admin — só homologação)
 *   --sim      não pergunta nada (apenas para bancos vazios ou junto com --recriar)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Execute pela linha de comando.'); }
if (!extension_loaded('mbstring') || !extension_loaded('pdo_mysql') || !extension_loaded('fileinfo')) {
    fwrite(STDERR, "Extensões PHP obrigatórias: pdo_mysql, mbstring, fileinfo (faltando: "
        . implode(', ', array_filter(['pdo_mysql', 'mbstring', 'fileinfo'], fn($x) => !extension_loaded($x))) . ").\n");
    exit(1);
}

$opt = getopt('', ['email:', 'senha:', 'nome::', 'demo', 'sim', 'recriar']);
if (empty($opt['email']) || !filter_var($opt['email'], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php install.php --email=admin@empresa.com.br [--nome=\"Admin\"] [--demo]\n");
    exit(1);
}
$senha = $opt['senha'] ?? getenv('MZ_ADMIN_SENHA') ?: null;
if ($senha === null) {
    // lê sem eco (não fica no histórico do shell nem na lista de processos)
    echo 'Senha do administrador (mín. 8): ';
    system('stty -echo 2>/dev/null'); $senha = trim((string)fgets(STDIN)); system('stty echo 2>/dev/null'); echo "\n";
}
if (mb_strlen($senha) < 8) { fwrite(STDERR, "A senha precisa ter ao menos 8 caracteres.\n"); exit(1); }

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/migracoes.php';

$banco = (string)cfg('db.nome');
$emUso = (bool)qv("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'usuarios'")
      && (int)qv('SELECT COUNT(*) FROM usuarios') > 0;
if ($emUso && !isset($opt['recriar'])) {
    echo "O banco '$banco' JÁ TEM DADOS (" . (int)qv('SELECT COUNT(*) FROM usuarios') . " usuário(s), "
       . (int)qv('SELECT COUNT(*) FROM ordens_trabalho') . " OT(s)). Para atualizar uma instalação use: php migrate.php\n"
       . "Para APAGAR TUDO e reinstalar, digite o nome do banco: ";
    if (trim((string)fgets(STDIN)) !== $banco) { fwrite(STDERR, "Cancelado.\n"); exit(1); }
} elseif (!isset($opt['sim'])) {
    echo "Isto vai criar as tabelas no banco '$banco' (tabelas existentes do sistema serão recriadas). Continuar? [s/N] ";
    if (strtolower(trim((string)fgets(STDIN))) !== 's') { fwrite(STDERR, "Cancelado.\n"); exit(1); }
}

try {
    $sql = preg_replace('/^--.*$/m', '', (string)file_get_contents(__DIR__ . '/database/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
    db()->exec('DROP TABLE IF EXISTS migracoes, fila_emails, sequencias, login_falhas, rate_limites');
    aplicar_migracoes();
    echo "✔ Estrutura criada (schema + migrações)\n";

    $adminId = insert('usuarios', ['nome' => $opt['nome'] ?? 'Administrador', 'email' => mb_strtolower($opt['email']), 'senha_hash' => password_hash($senha, PASSWORD_DEFAULT), 'perfil' => 'admin']);
    echo "✔ Administrador {$opt['email']} criado\n";

    if (isset($opt['demo'])) {
        require __DIR__ . '/database/demo.php';
        demo_popular($adminId, $senha);
        echo "✔ Dados de demonstração inseridos (gestor@demo.local / tecnico@demo.local / solicitante@demo.local com a mesma senha do admin)\n";
    }
} catch (Throwable $ex) {
    fwrite(STDERR, 'ERRO: ' . $ex->getMessage() . "\n");
    exit(1);
}

$up = cfg('upload_dir');
if (!is_dir($up)) mkdir($up, 0770, true);
echo "✔ Pasta de anexos: $up\n";
if (function_exists('posix_getpwuid') && ($dono = posix_getpwuid(fileowner($up))['name'] ?? '') && !in_array($dono, ['www-data', 'apache', 'nginx', 'nobody'], true)) {
    echo "! A pasta storage/ pertence a '$dono'. O servidor web precisa gravar nela:\n"
       . "  sudo chown -R www-data:www-data " . realpath(APP_ROOT . '/storage') . " && sudo chmod -R 770 " . realpath(APP_ROOT . '/storage') . "\n";
}
echo "Pronto. Aponte o DocumentRoot para a pasta public/.\n";
