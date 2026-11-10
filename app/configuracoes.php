<?php
declare(strict_types=1);

/**
 * Configurações editáveis pelo administrador (tabela `configuracoes`).
 * Os valores do banco se sobrepõem aos de config/config.php, mas SÓ para as chaves listadas em CONFIG_CAMPOS
 * (banco de dados, debug, cron_token e base_url continuam exclusivos do arquivo).
 * Segredos (senha SMTP, chave secreta do Turnstile) são gravados cifrados com AES-256-GCM.
 */

const CONFIG_CAMPOS = [
    // geral
    'app_nome'                 => ['str', 60],
    'empresa'                  => ['str', 120],
    'app_url'                  => ['origem'],
    'upload_max_mb'            => ['int', 1, 64],
    'seo.indexar'              => ['bool'],
    // aparência
    'tema.cor_menu'            => ['cor'],
    'tema.cor_destaque'        => ['cor'],
    'tema.cor_primaria'        => ['cor'],
    'marca.logo'               => ['arquivo'],
    'marca.favicon'            => ['arquivo'],
    // e-mail
    'email.ativo'              => ['bool'],
    'email.envio_imediato'     => ['bool'],
    'email.remetente'          => ['email'],
    'email.remetente_nome'     => ['str', 80],
    'email.transporte'         => ['enum', ['smtp', 'mail']],
    'email.smtp_host'          => ['host'],
    'email.smtp_porta'         => ['int', 1, 65535],
    'email.smtp_seguranca'     => ['enum', ['tls', 'ssl', '']],
    'email.smtp_usuario'       => ['str', 190],
    'email.smtp_senha'         => ['segredo'],
    'email.resumo_diario'      => ['bool'],
    // Turnstile
    'turnstile.ativo'          => ['bool'],
    'turnstile.site_key'       => ['chave'],
    'turnstile.secret_key'     => ['segredo'],
    'turnstile.formularios'    => ['conjunto', ['login', 'pedido']],
    'turnstile.modo_login'     => ['enum', ['sempre', 'apos_falhas']],
    'turnstile.falhar_aberto'  => ['bool'],
    // segurança
    'proxy'                    => ['enum', ['', 'cloudflare', 'confiavel']],
    'proxies_confiaveis'       => ['cidrs'],
    'rate_limit.ativo'         => ['bool'],
    'rate_limit.geral_ip'      => ['int', 0, 100000],
    'rate_limit.post_usuario'  => ['int', 0, 100000],
    'rate_limit.login_ip'      => ['int', 0, 100000],
    'rate_limit.pedidos_hora'  => ['int', 0, 100000],
    'rate_limit.uploads_hora'  => ['int', 0, 100000],
    'rate_limit.qr_ip'         => ['int', 0, 100000],
    'rate_limit.cron_ip'       => ['int', 0, 100000],
];

/** Padrões das chaves que não existem no config.sample.php. */
const CONFIG_PADROES = [
    'seo.indexar' => false,
    'tema.cor_menu' => '#212529', 'tema.cor_destaque' => '#ffc107', 'tema.cor_primaria' => '#0d6efd',
    'marca.logo' => null, 'marca.favicon' => null,
];

/** Define um valor no array de configuração em memória pela chave com pontos. */
function cfg_definir(string $chave, $valor): void
{
    $ref = &$GLOBALS['CFG'];
    foreach (explode('.', $chave) as $p) {
        if (!isset($ref[$p]) || !is_array($ref[$p])) $ref[$p] = $ref[$p] ?? [];
        $ref = &$ref[$p];
    }
    $ref = $valor;
}

/** Carrega as configurações do banco por cima do arquivo. Silencioso se a tabela ainda não existir. */
function config_db_aplicar(): void
{
    foreach (CONFIG_PADROES as $k => $v) if (cfg($k) === null) cfg_definir($k, $v);
    try {
        $linhas = q('SELECT chave, valor FROM configuracoes');
    } catch (Throwable) {
        return; // instalação antiga sem a migração 009 (o módulo Sistema oferece aplicar)
    }
    foreach ($linhas as $l) {
        $tipo = CONFIG_CAMPOS[$l['chave']][0] ?? null;
        if ($tipo === null) continue;
        $v = json_decode((string)$l['valor'], true);
        if ($tipo === 'segredo') $v = is_string($v) ? config_decifrar($v) : '';
        cfg_definir($l['chave'], $v);
    }
    // saída de emergência: criar o arquivo storage/DESATIVAR_TURNSTILE (pelo gerenciador de arquivos) desliga o Turnstile
    if (is_file(APP_ROOT . '/storage/DESATIVAR_TURNSTILE')) cfg_definir('turnstile.ativo', false);
}

/* ---------- segredos ---------- */

function config_chave_cripto(): string
{
    static $k = null;
    if ($k !== null) return $k;
    $doArquivo = (string)cfg('app_key', '');
    if (strlen($doArquivo) >= 32) return $k = hash('sha256', $doArquivo, true);
    $arq = APP_ROOT . '/storage/app.key';
    if (!is_file($arq)) {
        $nova = random_bytes(32);
        if (@file_put_contents($arq, base64_encode($nova), LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar storage/app.key');
        @chmod($arq, 0600);
    }
    $bin = base64_decode(trim((string)file_get_contents($arq)), true);
    if ($bin === false || strlen($bin) !== 32) throw new RuntimeException('storage/app.key inválido');
    return $k = $bin;
}

function config_cifrar(string $texto): string
{
    if ($texto === '') return '';
    $iv = random_bytes(12);
    $ct = openssl_encrypt($texto, 'aes-256-gcm', config_chave_cripto(), OPENSSL_RAW_DATA, $iv, $tag, 'mzmanut-config');
    if ($ct === false) throw new RuntimeException('Falha ao cifrar.');
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function config_decifrar(string $valor): string
{
    if ($valor === '' || !str_starts_with($valor, 'v1:')) return '';
    try {
        $bin = base64_decode(substr($valor, 3), true);
        if ($bin === false || strlen($bin) < 29) return '';
        $pt = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', config_chave_cripto(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16), 'mzmanut-config');
    } catch (Throwable $ex) {
        error_log('[MZManut] segredo de configuração ilegível: ' . $ex->getMessage());
        return '';
    }
    return $pt === false ? '' : $pt;
}

/* ---------- validação ---------- */

/**
 * Valida/normaliza um valor de formulário para a chave. Lança InvalidArgumentException com mensagem amigável.
 * @return mixed
 */
function config_validar(string $chave, $bruto)
{
    $def = CONFIG_CAMPOS[$chave] ?? throw new InvalidArgumentException("Chave desconhecida: $chave");
    $s = is_string($bruto) ? trim($bruto) : '';
    switch ($def[0]) {
        case 'str':
            if (preg_match('/[\x00-\x1F\x7F]/u', $s) || !mb_check_encoding($s, 'UTF-8')) throw new InvalidArgumentException('Caracteres inválidos.');
            if (mb_strlen($s) > $def[1]) throw new InvalidArgumentException("Máximo de {$def[1]} caracteres.");
            return $s;
        case 'int':
            if (!preg_match('/^\d{1,9}$/', $s) || (int)$s < $def[1] || (int)$s > $def[2]) throw new InvalidArgumentException("Informe um número entre {$def[1]} e {$def[2]}.");
            return (int)$s;
        case 'bool':
            return $bruto !== null && $bruto !== '' && $bruto !== '0';
        case 'enum':
            if (!in_array($s, $def[1], true)) throw new InvalidArgumentException('Opção inválida.');
            return $s;
        case 'conjunto':
            $lista = is_array($bruto) ? array_values(array_intersect($def[1], array_map('strval', $bruto))) : [];
            return $lista;
        case 'email':
            if ($s !== '' && !filter_var($s, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('E-mail inválido.');
            return mb_strtolower($s);
        case 'host':
            if ($s !== '' && !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', $s) && !filter_var($s, FILTER_VALIDATE_IP))
                throw new InvalidArgumentException('Servidor inválido (use só o nome, ex.: smtp.empresa.com.br).');
            return strtolower($s);
        case 'origem':
            if ($s === '') return '';
            $p = parse_url($s);
            if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host']) || isset($p['user']) || isset($p['pass'])
                || isset($p['query']) || isset($p['fragment']) || (isset($p['path']) && $p['path'] !== '' && $p['path'] !== '/')) {
                throw new InvalidArgumentException('Endereço inválido: use só protocolo e domínio, ex.: https://manutencao.empresa.com.br');
            }
            return strtolower($p['scheme'] . '://' . $p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
        case 'cor':
            if (!preg_match('/^#[0-9a-f]{6}$/i', $s)) throw new InvalidArgumentException('Cor inválida (use #RRGGBB).');
            return strtolower($s);
        case 'chave':
            if ($s !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,120}$/', $s)) throw new InvalidArgumentException('Chave em formato inválido.');
            return $s;
        case 'cidrs':
            $itens = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $s) ?: [])));
            if (count($itens) > 50) throw new InvalidArgumentException('No máximo 50 faixas.');
            foreach ($itens as $c) {
                [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
                $v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
                if (!filter_var($ip, FILTER_VALIDATE_IP) || ($bits !== null && (!ctype_digit($bits) || (int)$bits > ($v6 ? 128 : 32))))
                    throw new InvalidArgumentException("Faixa inválida: $c");
            }
            return $itens;
        case 'segredo':
            if (strlen($s) > 500 || preg_match('/[\x00-\x1F\x7F]/', $s)) throw new InvalidArgumentException('Valor inválido.');
            return $s;
    }
    throw new InvalidArgumentException('Tipo não suportado.');
}

/** Grava (ou remove, com null) um conjunto de chaves já validadas. */
function config_gravar(array $valores): void
{
    foreach ($valores as $chave => $v) {
        if (!isset(CONFIG_CAMPOS[$chave])) throw new InvalidArgumentException("Chave desconhecida: $chave");
        if ($v === null) { qx('DELETE FROM configuracoes WHERE chave = ?', [$chave]); continue; }
        if (CONFIG_CAMPOS[$chave][0] === 'segredo') $v = config_cifrar((string)$v);
        qx('INSERT INTO configuracoes (chave, valor, usuario_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), usuario_id = VALUES(usuario_id), atualizado_em = CURRENT_TIMESTAMP',
           [$chave, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), usuario()['id'] ?? null]);
        cfg_definir($chave, CONFIG_CAMPOS[$chave][0] === 'segredo' ? config_decifrar($v) : $v);
    }
}

/* ---------- marca (logo, favicon, cores) ---------- */

const MARCA_DIR = APP_ROOT . '/storage/marca';

/** URL pública versionada de um arquivo da marca, ou null se não houver. */
function marca_url(string $tipo): ?string
{
    $m = cfg("marca.$tipo");
    if (!is_array($m) || empty($m['arq']) || !is_file(MARCA_DIR . '/' . basename((string)$m['arq']))) return null;
    return rtrim((string)cfg('base_url', ''), '/') . '/marca.php?t=' . $tipo . '&v=' . (int)($m['v'] ?? 0);
}

function marca_favicon_url(): string { return marca_url('favicon') ?? asset('icone.svg'); }

/** CSS do tema (null se o tema padrão não foi alterado). */
function tema_css(): ?string
{
    $menu = (string)cfg('tema.cor_menu'); $dest = (string)cfg('tema.cor_destaque'); $prim = (string)cfg('tema.cor_primaria');
    if ($menu === CONFIG_PADROES['tema.cor_menu'] && $dest === CONFIG_PADROES['tema.cor_destaque'] && $prim === CONFIG_PADROES['tema.cor_primaria']) return null;
    foreach ([$menu, $dest, $prim] as $c) if (!preg_match('/^#[0-9a-f]{6}$/i', $c)) return null;
    $txtMenu = cor_texto($menu); $txtDest = cor_texto($dest); $txtPrim = cor_texto($prim);
    $menuHover = cor_ajustar($menu, $txtMenu === '#ffffff' ? 0.12 : -0.08);
    $mutedMenu = $txtMenu === '#ffffff' ? 'rgba(255,255,255,.72)' : 'rgba(0,0,0,.65)';
    $destEsc = cor_ajustar($dest, -0.12); $primEsc = cor_ajustar($prim, -0.15);
    return ":root{--mz-menu:$menu;--mz-destaque:$dest;--mz-primaria:$prim}\n"
        . ".sidebar,.navbar.bg-dark,.m-top{background:$menu!important;color:$txtMenu}\n"
        . ".sidebar .nav-link{color:$mutedMenu}.sidebar .nav-link:hover{background:$menuHover;color:$txtMenu}\n"
        . ".sidebar .text-secondary{color:$mutedMenu!important}.sidebar .link-light{color:$txtMenu!important}\n"
        . ".sidebar .nav-link.active{background:$dest;color:$txtDest}\n"
        . ".m-top,.m-top-btn,.navbar.bg-dark .navbar-brand{color:$txtMenu}\n"
        . ".text-warning.marca-icone{color:$dest!important}\n"
        . ".btn-warning{--bs-btn-bg:$dest;--bs-btn-border-color:$dest;--bs-btn-color:$txtDest;--bs-btn-hover-bg:$destEsc;--bs-btn-hover-border-color:$destEsc;--bs-btn-hover-color:$txtDest;--bs-btn-active-bg:$destEsc;--bs-btn-active-border-color:$destEsc;--bs-btn-active-color:$txtDest}\n"
        . ".btn-primary{--bs-btn-bg:$prim;--bs-btn-border-color:$prim;--bs-btn-color:$txtPrim;--bs-btn-hover-bg:$primEsc;--bs-btn-hover-border-color:$primEsc;--bs-btn-hover-color:$txtPrim;--bs-btn-active-bg:$primEsc;--bs-btn-active-border-color:$primEsc;--bs-btn-active-color:$txtPrim}\n"
        . ".btn-outline-primary{--bs-btn-color:$prim;--bs-btn-border-color:$prim;--bs-btn-hover-bg:$prim;--bs-btn-hover-border-color:$prim;--bs-btn-hover-color:$txtPrim;--bs-btn-active-bg:$prim;--bs-btn-active-color:$txtPrim}\n"
        . "a:not(.btn):not(.nav-link):not(.list-group-item){color:$prim}\n"
        . ".page-link{color:$prim}.active>.page-link,.page-link.active{background:$prim;border-color:$prim;color:$txtPrim}\n"
        . ".form-check-input:checked{background-color:$prim;border-color:$prim}\n";
}

function tema_versao(): int
{
    return crc32((string)cfg('tema.cor_menu') . cfg('tema.cor_destaque') . cfg('tema.cor_primaria'));
}

/** Link do CSS do tema para o <head> (vazio se o tema é o padrão). */
function tema_link(): string
{
    if (tema_css() === null) return '';
    return '<link href="' . e(rtrim((string)cfg('base_url', ''), '/') . '/marca.php?t=css&v=' . tema_versao()) . '" rel="stylesheet">';
}

/** Cor de texto legível (preto/branco) sobre o fundo informado (contraste WCAG). */
function cor_texto(string $hex): string
{
    $lum = function (string $h): float {
        $c = array_map(fn($x) => hexdec($x) / 255, str_split(ltrim($h, '#'), 2));
        $c = array_map(fn($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $l = $lum($hex);
    return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.05) ? '#ffffff' : '#000000';
}

/** Clareia (fator > 0) ou escurece (fator < 0) uma cor. */
function cor_ajustar(string $hex, float $fator): string
{
    $c = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    $c = array_map(fn($v) => (int)round($fator >= 0 ? $v + (255 - $v) * $fator : $v * (1 + $fator)), $c);
    return sprintf('#%02x%02x%02x', ...array_map(fn($v) => max(0, min(255, $v)), $c));
}

/**
 * Processa a imagem enviada (logo ou favicon): valida pelo conteúdo, reprocessa com GD quando disponível
 * (remove metadados e qualquer conteúdo embutido) e grava em storage/marca. SVG não é aceito (pode conter script).
 * @return array{arq:string, mime:string, v:int}
 */
function marca_salvar_upload(array $f, string $tipo): array
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new InvalidArgumentException('Falha no envio do arquivo.');
    if ($f['size'] > 2 * 1048576) throw new InvalidArgumentException('Imagem maior que 2 MB.');
    $info = @getimagesize((string)$f['tmp_name']);
    $mimes = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp'];
    if (!$info || !isset($mimes[$info[2]])) throw new InvalidArgumentException('Envie uma imagem PNG, JPG ou WEBP.');
    if ($info[0] < 16 || $info[1] < 16 || $info[0] > 4000 || $info[1] > 4000) throw new InvalidArgumentException('Dimensões inválidas (16 a 4000 px).');
    if (!is_dir(MARCA_DIR) && !mkdir(MARCA_DIR, 0770, true)) throw new RuntimeException('Não foi possível criar storage/marca.');

    $max = $tipo === 'favicon' ? [256, 256] : [800, 240];
    $destino = MARCA_DIR . "/$tipo.png";
    if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
        $img = @imagecreatefromstring((string)file_get_contents((string)$f['tmp_name']));
        if (!$img) throw new InvalidArgumentException('Imagem corrompida ou não suportada.');
        [$w, $h] = [imagesx($img), imagesy($img)];
        $esc = min(1, $max[0] / $w, $max[1] / $h);
        $nw = max(1, (int)round($w * $esc)); $nh = max(1, (int)round($h * $esc));
        $novo = imagecreatetruecolor($nw, $nh);
        imagealphablending($novo, false); imagesavealpha($novo, true);
        imagefill($novo, 0, 0, imagecolorallocatealpha($novo, 0, 0, 0, 127));
        imagecopyresampled($novo, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $tmp = $destino . '.tmp';
        if (!imagepng($novo, $tmp, 9)) throw new RuntimeException('Não foi possível gravar a imagem.');
        rename($tmp, $destino);
        $mime = 'image/png';
    } else {
        // sem GD: guarda o arquivo original (já validado pelo conteúdo) com a extensão do tipo real
        $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2]];
        $destino = MARCA_DIR . "/$tipo.$ext";
        if (!move_uploaded_file((string)$f['tmp_name'], $destino)) throw new RuntimeException('Não foi possível gravar a imagem.');
        $mime = $mimes[$info[2]];
    }
    @chmod($destino, 0640);
    foreach (glob(MARCA_DIR . "/$tipo.*") ?: [] as $velho) if ($velho !== $destino && !str_ends_with($velho, '.tmp')) @unlink($velho);
    return ['arq' => basename($destino), 'mime' => $mime, 'v' => time()];
}

function marca_remover(string $tipo): void
{
    foreach (glob(MARCA_DIR . "/$tipo.*") ?: [] as $a) @unlink($a);
    config_gravar(["marca.$tipo" => null]);
}

/** Cabeçalho/meta de indexação conforme a configuração. */
function seo_meta(): string
{
    return cfg('seo.indexar') ? '' : '<meta name="robots" content="noindex, nofollow">';
}
