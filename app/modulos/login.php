<?php
if (usuario()) redirect('dashboard');

$erro = '';
if (is_post()) {
    limitar_cfg('login_ip', ip_cliente());
    $erro = turnstile_validar('login') ?? login((string)post('email'), (string)post('senha'));
    if ($erro === null) {
        $dest = $_SESSION['destino'] ?? null; unset($_SESSION['destino']);
        if (is_array($dest) && isset($dest['r']) && preg_match('#^[a-z_]+(/[a-z_]+)?$#', (string)$dest['r']) && !str_starts_with((string)$dest['r'], 'logout')) {
            $r = (string)$dest['r']; unset($dest['r']);
            redirect($r, array_filter($dest, 'is_scalar'));
        }
        $celular = (bool)preg_match('/Android|iPhone|iPod|Mobile/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($celular && in_array(usuario()['perfil'], ['tecnico', 'solicitante'], true)) redirect('m');
        redirect(usuario()['perfil'] === 'solicitante' ? 'pedidos' : 'dashboard');
    }
}

view_header('Entrar');
?>
<div class="row justify-content-center">
  <div class="col-sm-8 col-md-5 col-lg-4">
    <div class="text-center mb-4">
      <?php if ($logo = marca_url('logo')): ?><img src="<?= e($logo) ?>" alt="<?= e(cfg('empresa')) ?>" class="img-fluid" style="max-height:96px">
      <?php else: ?><i class="bi bi-gear-wide-connected text-warning marca-icone" style="font-size:3rem"></i><?php endif; ?>
      <h1 class="h4 mt-2"><?= e(cfg('app_nome')) ?></h1>
      <div class="text-muted small">Gestão de Manutenção · <?= e(cfg('empresa')) ?></div>
    </div>
    <div class="card"><div class="card-body p-4">
      <?php $dest = $_SESSION['destino'] ?? null;
        if (is_array($dest) && ($dest['r'] ?? '') === 'm/ativo' && is_scalar($dest['c'] ?? null) && preg_match('/^[\w\-.\/ ]{1,40}$/u', (string)$dest['c'])): ?>
        <div class="alert alert-info py-2 small"><i class="bi bi-qr-code"></i> Entre para abrir o equipamento <strong><?= e(mb_strtoupper((string)$dest['c'])) ?></strong>.</div>
      <?php endif; ?>
      <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" required autofocus value="<?= e(post('email')) ?>"></div>
        <div class="mb-3"><label class="form-label">Senha</label><input type="password" name="senha" class="form-control" required></div>
        <?= turnstile_widget('login') ?>
        <button class="btn btn-warning w-100 fw-semibold">Entrar</button>
      </form>
    </div></div>
  </div>
</div>
<?php view_footer();
