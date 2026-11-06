<?php
$u = q1('SELECT * FROM usuarios WHERE id = ?', [usuario()['id']]);

if (is_post()) {
    $atual = (string)post('senha_atual'); $nova = (string)post('senha_nova');
    if (!password_verify($atual, $u['senha_hash'])) {
        flash('Senha atual incorreta.', 'danger');
    } elseif (mb_strlen($nova) < 8) {
        flash('A nova senha precisa ter ao menos 8 caracteres.', 'danger');
    } elseif ($nova !== post('senha_conf')) {
        flash('A confirmação não confere.', 'danger');
    } else {
        qx('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [password_hash($nova, PASSWORD_DEFAULT), $u['id']]);
        auditar('senha', 'usuarios', (int)$u['id']);
        flash('Senha alterada.');
    }
    redirect('perfil');
}

view_header('Minha conta');
?>
<div class="page-title"><h1>Minha conta</h1></div>
<div class="row g-3">
  <div class="col-md-5"><div class="card"><div class="card-body">
    <dl class="mb-0">
      <dt>Nome</dt><dd><?= e($u['nome']) ?></dd>
      <dt>E-mail</dt><dd><?= e($u['email']) ?></dd>
      <dt>Perfil</dt><dd><?= badge('perfil', $u['perfil']) ?></dd>
      <dt>Último acesso</dt><dd><?= dt($u['ultimo_login'], true) ?></dd>
    </dl>
  </div></div></div>
  <div class="col-md-5"><div class="card"><div class="card-body">
    <h2 class="h6">Alterar senha</h2>
    <form method="post"><?= csrf_field() ?>
      <div class="mb-2"><label class="form-label">Senha atual</label><input type="password" name="senha_atual" class="form-control" required></div>
      <div class="mb-2"><label class="form-label">Nova senha (mín. 8)</label><input type="password" name="senha_nova" class="form-control" minlength="8" required></div>
      <div class="mb-3"><label class="form-label">Confirmar</label><input type="password" name="senha_conf" class="form-control" required></div>
      <button class="btn btn-primary">Salvar</button>
    </form>
  </div></div></div>
</div>
<?php view_footer();
