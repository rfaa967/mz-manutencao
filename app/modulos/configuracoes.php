<?php
exigir('admin');
require_once APP_ROOT . '/app/migracoes.php';

const CONFIG_ABAS = [
    'geral'     => ['Geral', 'sliders'],
    'aparencia' => ['Aparência', 'palette'],
    'email'     => ['E-mail', 'envelope'],
    'turnstile' => ['Turnstile', 'shield-check'],
    'seguranca' => ['Segurança', 'shield-lock'],
    'sistema'   => ['Sistema', 'cpu'],
];
/** Abas cujas alterações exigem reconfirmar a senha (vale por 15 min). */
const CONFIG_ABAS_SENSIVEIS = ['geral', 'email', 'turnstile', 'seguranca'];   // geral: app_url define os links de e-mails e etiquetas
const REAUTH_SEG = 900;

$aba = is_string(get('aba')) && isset(CONFIG_ABAS[get('aba')]) ? get('aba') : 'geral';
$tabelaOk = (bool)qv("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'configuracoes' AND column_name = 'usuario_id'");
$voltar = fn(string $a = '') => redirect('configuracoes', ['aba' => $a ?: $aba]);

/** Confirma a senha do administrador para ações sensíveis. Retorna null se ok ou a mensagem de erro. */
function config_reautenticar(): ?string
{
    if (($_SESSION['reauth_ate'] ?? 0) > time()) return null;
    $u = q1('SELECT email, senha_hash FROM usuarios WHERE id = ?', [usuario()['id']]);
    $email = mb_strtolower((string)$u['email']);
    if (login_bloqueado($email)) return 'Muitas tentativas. Aguarde 15 minutos.';
    if (!password_verify((string)($_POST['senha_confirmacao'] ?? ''), (string)$u['senha_hash'])) {
        insert('login_falhas', ['email' => $email, 'ip' => ip_cliente()]);
        auditar('reauth_falha', 'configuracoes');
        return 'Confirme sua senha atual para alterar esta seção.';
    }
    $_SESSION['reauth_ate'] = time() + REAUTH_SEG;
    return null;
}

/* ===================== ações ===================== */

if (is_post() && $acao === 'migrar') {
    if ($erro = config_reautenticar()) { flash($erro, 'danger'); $voltar('sistema'); }
    try {
        $feitas = aplicar_migracoes();
        auditar('migracoes', 'sistema', null, implode(',', $feitas));
        flash($feitas ? 'Banco atualizado: ' . implode(', ', $feitas) : 'O banco já estava atualizado.');
    } catch (Throwable $ex) {
        error_log('[MZManut] migração: ' . $ex);
        flash('Falha ao atualizar o banco: ' . $ex->getMessage(), 'danger');
    }
    $voltar('sistema');
}

if (is_post() && !$tabelaOk) { flash('Atualize o banco de dados (aba Sistema) antes de alterar configurações.', 'warning'); $voltar('sistema'); }

if (is_post() && $acao === 'email_teste') {
    limitar('email_teste', 'u' . usuario()['id'], 5, 3600);
    $para = (string)usuario()['email'];
    try {
        if (cfg('email.transporte', 'smtp') === 'smtp' && trim((string)cfg('email.smtp_host')) === '') throw new RuntimeException('Informe o servidor SMTP.');
        if (!filter_var((string)cfg('email.remetente'), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe o e-mail do remetente.');
        email_enviar($para, 'Teste de e-mail — ' . cfg('app_nome'), email_layout('Teste de envio', '<p>Se você recebeu esta mensagem, o envio de e-mails do sistema está funcionando.</p>'));
        auditar('email_teste', 'configuracoes', null, $para);
        flash("E-mail de teste enviado para $para.");
    } catch (Throwable $ex) {
        flash('Falha no envio: ' . mb_substr($ex->getMessage(), 0, 300), 'danger');
    }
    $voltar('email');
}

if (is_post() && $acao === 'remover_imagem') {
    $tipo = post('tipo') === 'favicon' ? 'favicon' : 'logo';
    marca_remover($tipo);
    auditar('config', 'aparencia', null, "remover $tipo");
    flash($tipo === 'logo' ? 'Logotipo removido.' : 'Favicon removido.');
    $voltar('aparencia');
}

if (is_post() && $acao === 'salvar') {
    $sec = (string)post('aba');
    if (!isset(CONFIG_ABAS[$sec]) || $sec === 'sistema') { flash('Seção inválida.', 'danger'); $voltar(); }
    if (in_array($sec, CONFIG_ABAS_SENSIVEIS, true) && ($erro = config_reautenticar())) { flash($erro, 'danger'); $voltar($sec); }

    $campos = [
        'geral'     => ['app_nome', 'empresa', 'app_url', 'upload_max_mb', 'upload_cota_mb', 'seo.indexar'],
        'aparencia' => ['tema.cor_menu', 'tema.cor_destaque', 'tema.cor_primaria'],
        'email'     => ['email.ativo', 'email.envio_imediato', 'email.remetente', 'email.remetente_nome', 'email.transporte', 'email.smtp_host',
                        'email.smtp_porta', 'email.smtp_seguranca', 'email.smtp_usuario', 'email.smtp_senha', 'email.resumo_diario'],
        'turnstile' => ['turnstile.ativo', 'turnstile.site_key', 'turnstile.secret_key', 'turnstile.formularios', 'turnstile.modo_login', 'turnstile.falhar_aberto'],
        'seguranca' => ['proxy', 'proxies_confiaveis', 'rate_limit.ativo', 'rate_limit.geral_ip', 'rate_limit.post_usuario', 'rate_limit.login_ip',
                        'rate_limit.pedidos_hora', 'rate_limit.uploads_hora', 'rate_limit.qr_ip', 'rate_limit.cron_ip'],
    ][$sec];

    $novos = []; $erros = [];
    foreach ($campos as $k) {
        $nomeCampo = str_replace('.', '__', $k);
        $bruto = $_POST[$nomeCampo] ?? null;
        $tipo = CONFIG_CAMPOS[$k][0];
        // segredo em branco = mantém o atual; a caixa "apagar" remove
        if ($tipo === 'segredo') {
            if (post($nomeCampo . '__apagar')) { $novos[$k] = ''; continue; }
            if (!is_string($bruto) || $bruto === '') continue;
        }
        if ($tipo === 'bool' && is_array($bruto)) $bruto = null;
        try { $novos[$k] = config_validar($k, $bruto); }
        catch (InvalidArgumentException $ex) { $erros[] = rotulo_config($k) . ': ' . $ex->getMessage(); }
    }

    if (!$erros && $sec === 'aparencia') {
        foreach (['logo', 'favicon'] as $t) {
            $f = $_FILES[$t] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            try { $novos["marca.$t"] = marca_salvar_upload($f, $t); }
            catch (Throwable $ex) { $erros[] = ($t === 'logo' ? 'Logotipo' : 'Favicon') . ': ' . $ex->getMessage(); }
        }
    }

    if (!$erros && $sec === 'geral' && ($novos['upload_max_mb'] ?? 0) > 0) {
        $limiteIni = (int)min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size'))) / 1048576;
        if ($limiteIni > 0 && $novos['upload_max_mb'] > $limiteIni) $erros[] = "Tamanho máximo de anexo: o PHP do servidor aceita no máximo {$limiteIni} MB (upload_max_filesize/post_max_size).";
    }

    if (!$erros && $sec === 'email') {
        $ativo = $novos['email.ativo'] ?? false;
        $appUrl = trim((string)cfg('app_url'));
        if ($ativo && $appUrl === '') $erros[] = 'Defina o endereço do sistema (aba Geral) antes de ligar os e-mails — ele é usado nos links das mensagens.';
        if ($ativo && !filter_var($novos['email.remetente'] ?? '', FILTER_VALIDATE_EMAIL)) $erros[] = 'Informe o e-mail do remetente.';
        if ($ativo && ($novos['email.transporte'] ?? 'smtp') === 'smtp' && ($novos['email.smtp_host'] ?? '') === '') $erros[] = 'Informe o servidor SMTP.';
        $temUsuario = ($novos['email.smtp_usuario'] ?? '') !== '';
        if ($temUsuario && ($novos['email.smtp_seguranca'] ?? '') === '') $erros[] = 'Com autenticação, use TLS ou SSL (a senha não pode trafegar sem criptografia).';
    }

    if (!$erros && $sec === 'turnstile') {
        $ligar = $novos['turnstile.ativo'] ?? false;
        $siteNova = $novos['turnstile.site_key'] ?? '';
        $secretNova = array_key_exists('turnstile.secret_key', $novos) ? $novos['turnstile.secret_key'] : (string)cfg('turnstile.secret_key');
        if ($ligar && ($siteNova === '' || $secretNova === '')) $erros[] = 'Informe as duas chaves antes de ativar.';
        $mudouChave = $siteNova !== (string)cfg('turnstile.site_key') || array_key_exists('turnstile.secret_key', $novos);
        // ao ligar (ou trocar as chaves com ele ligado), exige que o próprio admin passe no desafio com as chaves novas:
        // assim uma chave errada não tranca todos para fora do login
        if (!$erros && $ligar && (!cfg('turnstile.ativo') || $mudouChave)) {
            if ($siteNova !== (string)cfg('turnstile.site_key')) {
                $erros[] = 'Salve primeiro as chaves com o Turnstile desligado; depois resolva o desafio que aparece nesta tela e ative.';
            } else {
                $token = $_POST['cf-turnstile-response'] ?? '';
                $j = is_string($token) && $token !== '' && strlen($token) <= 2048 ? turnstile_siteverify($secretNova, $token) : null;
                if (!$j || empty($j['success'])) {
                    $codigos = $j ? implode(', ', (array)($j['error-codes'] ?? [])) : 'sem resposta';
                    $erros[] = "O desafio de teste não foi validado com essas chaves ($codigos). Confira a chave secreta e o domínio cadastrado na Cloudflare.";
                }
            }
        }
    }

    if ($erros) {
        foreach ($erros as $er) flash($er, 'danger');
        $voltar($sec);
    }

    $antes = [];
    foreach ($novos as $k => $v) $antes[$k] = cfg($k);
    db()->beginTransaction();
    config_gravar($novos);
    db()->commit();
    $mudou = array_keys(array_filter($novos, fn($v, $k) => $v !== $antes[$k], ARRAY_FILTER_USE_BOTH));
    auditar('config', $sec, null, implode(', ', $mudou)); // só os nomes das chaves; nunca valores (podem ser segredos)
    flash($mudou ? 'Configurações salvas.' : 'Nada foi alterado.');
    $voltar($sec);
}

/* ===================== tela ===================== */

function rotulo_config(string $k): string
{
    return [
        'app_nome' => 'Nome do sistema', 'empresa' => 'Empresa', 'app_url' => 'Endereço do sistema', 'upload_max_mb' => 'Tamanho máximo de anexo', 'upload_cota_mb' => 'Espaço total para anexos (MB)',
        'tema.cor_menu' => 'Cor do menu', 'tema.cor_destaque' => 'Cor de destaque', 'tema.cor_primaria' => 'Cor primária',
        'email.remetente' => 'Remetente', 'email.remetente_nome' => 'Nome do remetente', 'email.smtp_host' => 'Servidor SMTP',
        'email.smtp_porta' => 'Porta', 'email.smtp_usuario' => 'Usuário SMTP', 'email.smtp_senha' => 'Senha SMTP',
        'turnstile.site_key' => 'Site key', 'turnstile.secret_key' => 'Secret key', 'proxies_confiaveis' => 'Proxies confiáveis',
        'turnstile.modo_login' => 'Modo no login', 'email.transporte' => 'Transporte', 'email.smtp_seguranca' => 'Segurança da conexão', 'proxy' => 'Proxy / CDN',
        'rate_limit.geral_ip' => 'Páginas por IP', 'rate_limit.post_usuario' => 'Envios por usuário', 'rate_limit.login_ip' => 'Logins por IP',
        'rate_limit.pedidos_hora' => 'Pedidos por hora', 'rate_limit.uploads_hora' => 'Anexos por hora', 'rate_limit.qr_ip' => 'Leituras de QR por IP', 'rate_limit.cron_ip' => 'Cron por IP',
    ][$k] ?? $k;
}

function ini_bytes(string $v): int
{
    $n = (int)$v; $u = strtolower(substr(trim($v), -1));
    return match ($u) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
}

/** Campo de formulário padronizado. */
function cfg_campo(string $k, string $tipoHtml = 'text', string $ajuda = '', string $attrs = ''): string
{
    $nome = str_replace('.', '__', $k);
    $v = cfg($k);
    if (is_array($v)) $v = implode("\n", $v);
    $id = 'c_' . $nome;
    $html = '<label class="form-label" for="' . $id . '">' . e(rotulo_config($k)) . '</label>';
    $html .= '<input type="' . $tipoHtml . '" class="form-control' . ($tipoHtml === 'color' ? ' form-control-color w-100' : '') . '" id="' . $id . '" name="' . $nome . '" value="' . e($v) . '" ' . $attrs . '>';
    if ($ajuda) $html .= '<div class="form-text">' . $ajuda . '</div>';
    return $html;
}

function cfg_check(string $k, string $rotulo, string $ajuda = ''): string
{
    $nome = str_replace('.', '__', $k);
    return '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="c_' . $nome . '" name="' . $nome . '" value="1"' . (cfg($k) ? ' checked' : '') . '>'
         . '<label class="form-check-label" for="c_' . $nome . '">' . e($rotulo) . '</label>' . ($ajuda ? '<div class="form-text">' . $ajuda . '</div>' : '') . '</div>';
}

function cfg_select(string $k, array $opcoes): string
{
    $nome = str_replace('.', '__', $k);
    return '<label class="form-label" for="f_' . $nome . '">' . e(rotulo_config($k)) . '</label>' . sel($nome, $opcoes, (string)cfg($k), false);
}

function cfg_segredo(string $k): string
{
    $nome = str_replace('.', '__', $k);
    $definido = (string)cfg($k) !== '';
    return '<label class="form-label" for="c_' . $nome . '">' . e(rotulo_config($k)) . '</label>'
         . '<input type="password" class="form-control" id="c_' . $nome . '" name="' . $nome . '" autocomplete="new-password" placeholder="' . ($definido ? '•••••••• (definida — deixe em branco para manter)' : 'não definida') . '">'
         . ($definido ? '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" id="c_' . $nome . '_ap" name="' . $nome . '__apagar" value="1"><label class="form-check-label small" for="c_' . $nome . '_ap">apagar a senha/chave salva</label></div>' : '');
}

function cfg_reauth(string $aba): string
{
    if ((!in_array($aba, CONFIG_ABAS_SENSIVEIS, true) && $aba !== 'migrar') || ($_SESSION['reauth_ate'] ?? 0) > time()) return '';
    return '<div class="col-md-6"><label class="form-label" for="senha_confirmacao">Sua senha atual <span class="text-danger">*</span></label>'
         . '<input type="password" class="form-control" id="senha_confirmacao" name="senha_confirmacao" required autocomplete="current-password">'
         . '<div class="form-text">Por segurança, confirme sua senha para alterar esta seção (vale por 15 minutos).</div></div>';
}

if ($aba === 'turnstile' && cfg('turnstile.site_key')) {
    // widget de teste nesta tela: a CSP precisa liberar a Cloudflare mesmo com o Turnstile ainda desligado
    header('Content-Security-Policy: ' . csp_politica(true));
    $GLOBALS['__turnstile'] = true;
}

view_header('Configurações', 'configuracoes');
?>
<div class="page-title"><h1><i class="bi bi-gear"></i> Configurações</h1></div>
<?php if ($ilegiveis = $GLOBALS['__cfg_segredo_ilegivel'] ?? []): ?><div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i> <strong>Não foi possível ler: <?= e(implode(', ', array_map('rotulo_config', $ilegiveis))) ?>.</strong>
  A chave de criptografia (<code>storage/app.key</code>) foi perdida ou trocada. Digite essas senhas/chaves de novo nas abas correspondentes. <?php if (in_array('turnstile.secret_key', $ilegiveis, true)): ?>Enquanto isso o Turnstile <strong>não está sendo exigido</strong>.<?php endif; ?></div><?php endif; ?>
<?php if (!$tabelaOk): ?><div class="alert alert-warning">O banco de dados precisa ser atualizado para salvar configurações. Abra a aba <a href="<?= e(url('configuracoes', ['aba' => 'sistema'])) ?>">Sistema</a> e clique em <strong>Atualizar banco de dados</strong>.</div><?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach (CONFIG_ABAS as $k => [$rot, $ic]): ?>
    <li class="nav-item"><a class="nav-link <?= $aba === $k ? 'active' : '' ?>" href="<?= e(url('configuracoes', ['aba' => $k])) ?>"><i class="bi bi-<?= $ic ?>"></i> <?= e($rot) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($aba !== 'sistema'): ?>
<form method="post" action="<?= e(url('configuracoes/salvar')) ?>" enctype="multipart/form-data" class="card"><div class="card-body">
  <?= csrf_field() ?><input type="hidden" name="aba" value="<?= e($aba) ?>">
  <div class="row g-3">
<?php endif; ?>

<?php if ($aba === 'geral'): ?>
    <div class="col-md-6"><?= cfg_campo('app_nome', 'text', 'Aparece no menu, no título das páginas e nos e-mails.', 'maxlength="60" required') ?></div>
    <div class="col-md-6"><?= cfg_campo('empresa', 'text', '', 'maxlength="120" required') ?></div>
    <div class="col-md-6"><?= cfg_campo('app_url', 'url', 'Protocolo e domínio, sem barra no final. Usado nos links dos e-mails e nas etiquetas QR.', 'placeholder="https://manutencao.empresa.com.br"') ?></div>
    <div class="col-md-3"><?= cfg_campo('upload_max_mb', 'number', 'Limite do PHP: ' . e(ini_get('upload_max_filesize')) . ' / ' . e(ini_get('post_max_size')), 'min="1" max="64" required') ?></div>
    <div class="col-md-3"><?= cfg_campo('upload_cota_mb', 'number', 'Em uso: ' . e(num((int)qv('SELECT COALESCE(SUM(tamanho), 0) FROM ativo_documentos') / 1048576, 1)) . ' MB', 'min="100" max="1000000" required') ?></div>
    <div class="col-12"><?= cfg_check('seo.indexar', 'Permitir indexação por buscadores (Google, Bing…)', 'Desligado (recomendado para sistema interno): envia <code>noindex</code> em todas as páginas e o <code>robots.txt</code> bloqueia tudo. Ligado: só a tela de login pode aparecer nos buscadores — as demais páginas exigem login de qualquer forma.') ?></div>

<?php elseif ($aba === 'aparencia'): ?>
    <div class="col-md-6">
      <label class="form-label" for="logo">Logotipo</label>
      <?php if ($l = marca_url('logo')): ?><div class="border rounded p-2 mb-2 text-center" style="background:<?= e((string)cfg('tema.cor_menu')) ?>"><img src="<?= e($l) ?>" alt="Logotipo atual" style="max-height:80px;max-width:100%"></div><?php endif; ?>
      <input type="file" class="form-control" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
      <div class="form-text">PNG (fundo transparente), JPG ou WEBP, até 2 MB. Exibido no menu, na tela de login e na impressão. Reduzido para até 800×240 px.</div>
    </div>
    <div class="col-md-6">
      <label class="form-label" for="favicon">Favicon / ícone</label>
      <?php if ($f = marca_url('favicon')): ?><div class="mb-2"><img src="<?= e($f) ?>" alt="Favicon atual" width="48" height="48" class="border rounded"></div><?php endif; ?>
      <input type="file" class="form-control" id="favicon" name="favicon" accept="image/png,image/jpeg,image/webp">
      <div class="form-text">Imagem quadrada (ideal 256×256 px). Aparece na aba do navegador e no topo da versão mobile.</div>
    </div>
    <div class="col-md-4"><?= cfg_campo('tema.cor_menu', 'color', 'Fundo do menu lateral e da barra do celular.') ?></div>
    <div class="col-md-4"><?= cfg_campo('tema.cor_destaque', 'color', 'Item ativo do menu e botão Entrar.') ?></div>
    <div class="col-md-4"><?= cfg_campo('tema.cor_primaria', 'color', 'Botões principais, links e paginação.') ?></div>
    <div class="col-12 small text-muted">A cor do texto sobre cada cor (preto ou branco) é escolhida automaticamente para manter o contraste.</div>

<?php elseif ($aba === 'email'): ?>
    <div class="col-12"><?= cfg_check('email.ativo', 'Enviar notificações por e-mail') ?></div>
    <div class="col-md-6"><?= cfg_campo('email.remetente', 'email', 'Use um endereço do mesmo domínio do servidor SMTP (SPF/DKIM).') ?></div>
    <div class="col-md-6"><?= cfg_campo('email.remetente_nome', 'text', '', 'maxlength="80"') ?></div>
    <div class="col-md-4"><?= cfg_select('email.transporte', ['smtp' => 'SMTP (recomendado)', 'mail' => 'mail() do PHP']) ?></div>
    <div class="col-md-5"><?= cfg_campo('email.smtp_host', 'text', '', 'placeholder="smtp.empresa.com.br"') ?></div>
    <div class="col-md-3"><?= cfg_campo('email.smtp_porta', 'number', '587 (TLS) ou 465 (SSL)', 'min="1" max="65535" required') ?></div>
    <div class="col-md-4"><?= cfg_select('email.smtp_seguranca', ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', '' => 'Nenhuma (só servidor local)']) ?></div>
    <div class="col-md-4"><?= cfg_campo('email.smtp_usuario', 'text', '', 'autocomplete="off"') ?></div>
    <div class="col-md-4"><?= cfg_segredo('email.smtp_senha') ?></div>
    <div class="col-md-6"><?= cfg_check('email.envio_imediato', 'Tentar enviar logo após cada ação', 'Além do cron a cada 5 minutos.') ?></div>
    <div class="col-md-6"><?= cfg_check('email.resumo_diario', 'Resumo diário para gestores') ?></div>

<?php elseif ($aba === 'turnstile'): ?>
    <div class="col-12 small text-muted">Crie as chaves em <strong>dash.cloudflare.com → Turnstile → Add site</strong>, cadastrando o domínio <code><?= e(parse_url((string)cfg('app_url'), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '')) ?></code>.
      Para ativar: <strong>1)</strong> salve as chaves com o Turnstile desligado; <strong>2)</strong> resolva o desafio que aparece abaixo e marque <em>Ativar</em>. Isso garante que as chaves funcionam antes de exigir o desafio no login.</div>
    <div class="col-md-6"><?= cfg_campo('turnstile.site_key', 'text', '', 'autocomplete="off" spellcheck="false"') ?></div>
    <div class="col-md-6"><?= cfg_segredo('turnstile.secret_key') ?></div>
    <div class="col-md-6">
      <span class="form-label d-block">Exigir em</span>
      <?php foreach (['login' => 'Tela de login', 'pedido' => 'Abertura de pedido de trabalho'] as $fk => $fr): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="turnstile__formularios[]" value="<?= $fk ?>" id="ts_<?= $fk ?>"<?= in_array($fk, (array)cfg('turnstile.formularios', []), true) ? ' checked' : '' ?>><label class="form-check-label" for="ts_<?= $fk ?>"><?= e($fr) ?></label></div>
      <?php endforeach; ?>
    </div>
    <div class="col-md-6"><?= cfg_select('turnstile.modo_login', ['sempre' => 'No login: sempre', 'apos_falhas' => 'No login: só após 3 falhas']) ?></div>
    <div class="col-12"><?= cfg_check('turnstile.falhar_aberto', 'Se a Cloudflare não responder, deixar passar', 'Desligado (padrão) = bloqueia enquanto a Cloudflare estiver fora do ar.') ?></div>
    <div class="col-12"><?= cfg_check('turnstile.ativo', 'Ativar Turnstile') ?></div>
    <?php if (cfg('turnstile.site_key')): ?>
      <div class="col-12"><div class="cf-turnstile" data-sitekey="<?= e((string)cfg('turnstile.site_key')) ?>" data-language="pt-br" data-action="config"></div>
        <div class="form-text">Desafio de teste com a site key salva — necessário ao ativar ou trocar as chaves.</div></div>
    <?php endif; ?>
    <div class="col-12 small text-muted"><i class="bi bi-life-preserver"></i> Se ficar sem acesso ao login por causa do Turnstile, crie pelo gerenciador de arquivos o arquivo vazio <code>storage/DESATIVAR_TURNSTILE</code>; apague-o depois de corrigir as chaves.</div>

<?php elseif ($aba === 'seguranca'): ?>
    <div class="col-md-6"><?= cfg_select('proxy', ['' => 'Acesso direto (sem proxy)', 'cloudflare' => 'Atrás da Cloudflare (nuvem laranja)', 'confiavel' => 'Atrás de proxy reverso próprio']) ?>
      <div class="form-text">Define de onde vem o IP real do visitante (rate limit, bloqueio de login e auditoria). Seu IP visto pelo sistema agora: <code><?= e(ip_cliente()) ?></code></div></div>
    <div class="col-md-6"><label class="form-label" for="c_proxies_confiaveis">Proxies confiáveis</label>
      <textarea class="form-control font-monospace" rows="3" id="c_proxies_confiaveis" name="proxies_confiaveis" placeholder="10.0.0.0/8&#10;192.168.1.10"><?= e(implode("\n", (array)cfg('proxies_confiaveis', []))) ?></textarea>
      <div class="form-text">Só para "proxy reverso próprio": IPs/faixas cujo X-Forwarded-For é aceito.</div></div>
    <div class="col-12"><?= cfg_check('rate_limit.ativo', 'Limitar requisições (rate limit)', 'Ao estourar, o sistema responde HTTP 429. 0 desliga a regra.') ?></div>
    <?php foreach (['geral_ip' => 'Páginas por IP (por minuto)', 'post_usuario' => 'Envios de formulário por usuário (por minuto)', 'login_ip' => 'Tentativas de login por IP (por minuto)',
        'pedidos_hora' => 'Pedidos de trabalho por usuário (por hora)', 'uploads_hora' => 'Anexos por usuário (por hora)', 'qr_ip' => 'Leituras de QR por IP (por minuto)', 'cron_ip' => 'Cron por URL por IP (por minuto)'] as $rk => $rr): ?>
      <div class="col-sm-6 col-lg-3"><label class="form-label small" for="c_rate_limit__<?= $rk ?>"><?= e($rr) ?></label>
        <input type="number" class="form-control" min="0" max="100000" required id="c_rate_limit__<?= $rk ?>" name="rate_limit__<?= $rk ?>" value="<?= (int)cfg("rate_limit.$rk", 0) ?>"></div>
    <?php endforeach; ?>
    <div class="col-12 small text-muted">Bloqueio de login (fixo): 8 falhas por e-mail+IP, 50 por IP ou 40 por e-mail em 15 minutos.</div>

<?php elseif ($aba === 'sistema'):
    $migr = glob(APP_ROOT . '/database/migracoes/*.sql') ?: [];
    sort($migr);
    try { $feitas = array_column(q('SELECT nome FROM migracoes'), 'nome'); } catch (Throwable) { $feitas = []; }
    $pend = array_values(array_diff(array_map('basename', $migr), $feitas));
    $exts = ['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'gd', 'json', 'session'];
    $cronTxt = function (string $arq, int $toleranciaSeg): string {
        $t = @filemtime(APP_ROOT . "/storage/$arq");
        if (!$t) return '<span class="text-warning">nunca rodou</span>';
        return '<span class="' . (time() - $t > $toleranciaSeg ? 'text-danger' : 'text-success') . '">' . e(date('d/m/Y H:i', $t)) . '</span>';
    };
?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h6">Banco de dados</h2>
    <?php if ($pend): ?>
      <div class="alert alert-warning py-2"><strong><?= count($pend) ?> atualização(ões) pendente(s):</strong> <?= e(implode(', ', $pend)) ?></div>
      <p class="small text-muted mb-2">Faça backup do banco antes (CyberPanel → Backups ou phpMyAdmin → Exportar).</p>
      <form method="post" action="<?= e(url('configuracoes/migrar')) ?>"><?= csrf_field() ?>
        <div class="row g-2 align-items-end"><?= cfg_reauth('migrar') ?>
        <div class="col-auto"><button class="btn btn-warning" data-confirm="Aplicar as atualizações do banco de dados agora?"><i class="bi bi-database-up"></i> Atualizar banco de dados</button></div></div></form>
    <?php else: ?>
      <p class="text-success mb-0"><i class="bi bi-check-circle"></i> Banco atualizado (<?= count($feitas) ?> migrações aplicadas).</p>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body small">
    <h2 class="h6">Ambiente</h2>
    <dl class="row mb-0">
      <dt class="col-5">Versão</dt><dd class="col-7"><?= e(APP_VERSAO) ?></dd>
      <dt class="col-5">PHP</dt><dd class="col-7"><?= e(PHP_VERSION) ?> (<?= e(PHP_SAPI) ?>)</dd>
      <dt class="col-5">Banco</dt><dd class="col-7"><?= e((string)qv('SELECT VERSION()')) ?></dd>
      <dt class="col-5">Extensões</dt><dd class="col-7"><?php foreach ($exts as $x): ?><span class="badge text-bg-<?= extension_loaded($x) ? 'success' : ($x === 'gd' ? 'warning' : 'danger') ?> me-1"><?= e($x) ?></span><?php endforeach; ?></dd>
      <dt class="col-5">Upload (PHP)</dt><dd class="col-7"><?= e(ini_get('upload_max_filesize')) ?> / post <?= e(ini_get('post_max_size')) ?></dd>
      <dt class="col-5">HTTPS</dt><dd class="col-7"><?= https_ativo() ? 'sim' : '<span class="text-danger">não</span>' ?></dd>
      <dt class="col-5">storage/ gravável</dt><dd class="col-7"><?= is_writable(APP_ROOT . '/storage') ? 'sim' : '<span class="text-danger">não</span>' ?></dd>
      <dt class="col-5">Cron preventivas</dt><dd class="col-7"><?= $cronTxt('cron_preventivas.ok', 26 * 3600) ?></dd>
      <dt class="col-5">Cron e-mails</dt><dd class="col-7"><?= $cronTxt('cron_emails.ok', 900) ?></dd>
      <dt class="col-5">Fila de e-mails</dt><dd class="col-7"><?= (int)qv("SELECT COUNT(*) FROM fila_emails WHERE status = 'pendente'") ?> pendente(s), <?= (int)qv("SELECT COUNT(*) FROM fila_emails WHERE status = 'erro'") ?> com erro</dd>
    </dl>
  </div></div></div>
</div>
<?php endif; ?>

<?php if ($aba !== 'sistema'): ?>
    <?= cfg_reauth($aba) ?>
  </div>
  <div class="mt-4 d-flex gap-2 flex-wrap">
    <button class="btn btn-primary" <?= $tabelaOk ? '' : 'disabled' ?>><i class="bi bi-check-lg"></i> Salvar</button>
  </div>
</div></form>

<?php if ($aba === 'aparencia' && (marca_url('logo') || marca_url('favicon'))): ?>
<div class="mt-3 d-flex gap-2">
  <?php foreach (['logo' => 'Remover logotipo', 'favicon' => 'Remover favicon'] as $t => $r): if (!marca_url($t)) continue; ?>
    <form method="post" action="<?= e(url('configuracoes/remover_imagem')) ?>"><?= csrf_field() ?><input type="hidden" name="tipo" value="<?= $t ?>">
      <button class="btn btn-outline-danger btn-sm" data-confirm="<?= e($r) ?>?"><i class="bi bi-trash"></i> <?= e($r) ?></button></form>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($aba === 'aparencia'): ?>
<form method="post" action="<?= e(url('configuracoes/salvar')) ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="aba" value="aparencia">
  <input type="hidden" name="tema__cor_menu" value="<?= e(CONFIG_PADROES['tema.cor_menu']) ?>"><input type="hidden" name="tema__cor_destaque" value="<?= e(CONFIG_PADROES['tema.cor_destaque']) ?>"><input type="hidden" name="tema__cor_primaria" value="<?= e(CONFIG_PADROES['tema.cor_primaria']) ?>">
  <button class="btn btn-link btn-sm px-0" data-confirm="Voltar às cores padrão?">Restaurar cores padrão</button></form>
<?php endif; ?>
<?php if ($aba === 'email'): ?>
<form method="post" action="<?= e(url('configuracoes/email_teste')) ?>" class="mt-3"><?= csrf_field() ?>
  <button class="btn btn-outline-secondary" <?= $tabelaOk ? '' : 'disabled' ?>><i class="bi bi-send"></i> Enviar e-mail de teste para <?= e(usuario()['email']) ?></button>
  <span class="form-text ms-2">Usa as configurações salvas (salve antes de testar).</span></form>
<?php endif; ?>
<?php endif; ?>
<?php view_footer();
