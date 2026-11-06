<?php
$u = q1('SELECT * FROM usuarios WHERE id = ?', [usuario()['id']]);

if (is_post() && post('acao') === 'notificacoes') {
    qx('UPDATE usuarios SET receber_emails = ? WHERE id = ?', [post('receber_emails') ? 1 : 0, $u['id']]);
    flash('Preferência de notificação salva.');
    redirect('perfil');
}
if (is_post()) {
    $atual = (string)post('senha_atual'); $nova = (string)post('senha_nova');
    if (!password_verify($atual, $u['senha_hash'])) {
        flash('Senha atual incorreta.', 'danger');
    } elseif (mb_strlen($nova) < 8) {
        flash('A nova senha precisa ter ao menos 8 caracteres.', 'danger');
    } elseif ($nova !== post('senha_conf')) {
        flash('A confirmação não confere.', 'danger');
    } else {
        $h = password_hash($nova, PASSWORD_DEFAULT);
        qx('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [$h, $u['id']]);
        sessao_renovar_senha($h); // as demais sessões deste usuário são encerradas
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
    <hr>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="acao" value="notificacoes">
      <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="receber_emails" value="1" id="re" <?= $u['receber_emails'] ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label" for="re">Receber notificações por e-mail</label></div>
      <div class="form-text"><?= email_ativo() ? 'Pedidos, OTs atribuídas a você, conclusões e (gestores) resumo diário.' : 'O envio de e-mails está desativado na configuração do sistema.' ?></div>
    </form>
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
