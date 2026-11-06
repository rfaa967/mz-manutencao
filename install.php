<?php
/**
 * Instalador (linha de comando)
 *   php install.php --email=admin@empresa.com.br --senha=SenhaForte123 [--nome="Administrador"] [--demo]
 *
 * ATENÇÃO: recria todas as tabelas (DROP). Use apenas na instalação.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Execute pela linha de comando.'); }

$opt = getopt('', ['email:', 'senha:', 'nome::', 'demo', 'sim']);
if (empty($opt['email']) || empty($opt['senha'])) {
    fwrite(STDERR, "Uso: php install.php --email=admin@empresa.com.br --senha=SenhaForte123 [--nome=\"Admin\"] [--demo]\n");
    exit(1);
}
if (mb_strlen($opt['senha']) < 8) { fwrite(STDERR, "A senha precisa ter ao menos 8 caracteres.\n"); exit(1); }

require __DIR__ . '/app/bootstrap.php';

if (!isset($opt['sim'])) {
    echo "Isto vai APAGAR e recriar as tabelas do banco '" . cfg('db.nome') . "'. Continuar? [s/N] ";
    if (strtolower(trim((string)fgets(STDIN))) !== 's') exit("Cancelado.\n");
}

$sql = file_get_contents(__DIR__ . '/database/schema.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) db()->exec($stmt);
db()->exec('DROP TABLE IF EXISTS migracoes, fila_emails, sequencias, login_falhas');
require __DIR__ . '/app/migracoes.php';
aplicar_migracoes();
echo "✔ Estrutura criada (schema + migrações)\n";

$adminId = insert('usuarios', ['nome' => $opt['nome'] ?? 'Administrador', 'email' => mb_strtolower($opt['email']), 'senha_hash' => password_hash($opt['senha'], PASSWORD_DEFAULT), 'perfil' => 'admin']);
echo "✔ Administrador {$opt['email']} criado\n";

if (isset($opt['demo'])) {
    require __DIR__ . '/database/demo.php';
    demo_popular($adminId, $opt['senha']);
    echo "✔ Dados de demonstração inseridos (usuários gestor@demo.local / tecnico@demo.local / solicitante@demo.local com a mesma senha do admin)\n";
}

$up = cfg('upload_dir');
if (!is_dir($up)) mkdir($up, 0775, true);
echo "✔ Pasta de anexos: $up\nPronto. Aponte o DocumentRoot para a pasta public/.\n";
