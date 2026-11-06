<?php
if (usuario()) redirect('dashboard');

$_SESSION['tentativas'] = $_SESSION['tentativas'] ?? [];
$_SESSION['tentativas'] = array_filter($_SESSION['tentativas'], fn($t) => $t > time() - 900);
$bloqueado = count($_SESSION['tentativas']) >= 8;

$erro = '';
if (is_post()) {
    if ($bloqueado) {
        $erro = 'Muitas tentativas. Aguarde 15 minutos.';
    } elseif (login((string)post('email'), (string)post('senha'))) {
        unset($_SESSION['tentativas']);
        redirect(usuario()['perfil'] === 'solicitante' ? 'pedidos' : 'dashboard');
    } else {
        $_SESSION['tentativas'][] = time();
        $erro = 'E-mail ou senha inválidos.';
    }
}

view_header('Entrar');
?>
<div class="row justify-content-center">
  <div class="col-sm-8 col-md-5 col-lg-4">
    <div class="text-center mb-4">
      <i class="bi bi-gear-wide-connected text-warning" style="font-size:3rem"></i>
      <h1 class="h4 mt-2"><?= e(cfg('app_nome')) ?></h1>
      <div class="text-muted small">Gestão de Manutenção · <?= e(cfg('empresa')) ?></div>
    </div>
    <div class="card"><div class="card-body p-4">
      <?php if ($erro): ?><div class="alert alert-danger py-2"><?= e($erro) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">E-mail</label><input type="email" name="email" class="form-control" required autofocus value="<?= e(post('email')) ?>"></div>
        <div class="mb-3"><label class="form-label">Senha</label><input type="password" name="senha" class="form-control" required></div>
        <button class="btn btn-warning w-100 fw-semibold">Entrar</button>
      </form>
    </div></div>
  </div>
</div>
<?php view_footer();
