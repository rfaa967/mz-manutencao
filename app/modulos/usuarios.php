<?php
exigir('admin');

$tecnicos = opcoes('SELECT id, nome FROM tecnicos WHERE ativo = 1 ORDER BY nome');

if ($acao === 'salvar' && is_post()) {
    $id = post_int('id');
    $email = mb_strtolower((string)post('email'));
    $dados = [
        'nome' => mb_substr((string)post('nome'), 0, 120), 'email' => $email,
        'perfil' => in_array(post('perfil'), array_keys(ROTULOS['perfil']), true) ? post('perfil') : 'solicitante',
        'tecnico_id' => isset($tecnicos[(int)post_int('tecnico_id')]) ? post_int('tecnico_id') : null, 'ativo' => post('ativo') ? 1 : 0, 'receber_emails' => post('receber_emails') ? 1 : 0,
    ];
    $senha = (string)post('senha');
    if (!$dados['nome'] || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Nome e e-mail válidos são obrigatórios.', 'danger'); redirect('usuarios/form', $id ? ['id' => $id] : []); }
    if (!$id && mb_strlen($senha) < 8) { flash('Informe uma senha com ao menos 8 caracteres.', 'danger'); redirect('usuarios/form'); }
    if ($id === usuario()['id'] && ($dados['perfil'] !== 'admin' || !$dados['ativo'])) { flash('Você não pode remover seu próprio acesso de administrador.', 'danger'); redirect('usuarios/form', ['id' => $id]); }
    if ($senha !== '') {
        if (mb_strlen($senha) < 8) { flash('A senha precisa ter ao menos 8 caracteres.', 'danger'); redirect('usuarios/form', $id ? ['id' => $id] : []); }
        $dados['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
    }
    try {
        if ($id) { update('usuarios', $dados, $id); auditar('editar', 'usuarios', $id); }
        else { $id = insert('usuarios', $dados); auditar('criar', 'usuarios', $id); }
        flash('Usuário salvo.');
    } catch (PDOException $ex) {
        flash($ex->getCode() === '23000' ? 'Já existe usuário com esse e-mail.' : 'Erro ao salvar.', 'danger');
        redirect('usuarios/form', $id ? ['id' => $id] : []);
    }
    redirect('usuarios');
}

if ($acao === 'form') {
    $id = (int)get('id', 0);
    $r = $id ? q1('SELECT * FROM usuarios WHERE id = ?', [$id]) : ['ativo' => 1, 'perfil' => 'solicitante', 'receber_emails' => 1];
    if (!empty($_SESSION['old']) || !empty($GLOBALS['__old'])) $r = com_old($r);
    view_header('Usuário', 'usuarios'); ?>
    <div class="page-title"><h1><?= $id ? 'Editar usuário' : 'Novo usuário' ?></h1><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('usuarios')) ?>">Voltar</a></div>
    <div class="card"><div class="card-body">
    <form method="post" action="<?= e(url('usuarios/salvar')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?: '' ?>">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Nome *</label><input class="form-control" name="nome" required value="<?= e($r['nome'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="email" required value="<?= e($r['email'] ?? '') ?>"></div>
        <div class="col-md-4"><label class="form-label">Perfil</label><?= sel('perfil', lista_rotulos('perfil'), $r['perfil'], false) ?></div>
        <div class="col-md-4"><label class="form-label">Vincular a técnico</label><?= sel('tecnico_id', $tecnicos, $r['tecnico_id'] ?? null) ?>
          <div class="form-text">Permite ao técnico ver "Minhas OTs".</div></div>
        <div class="col-md-4"><label class="form-label">Senha <?= $id ? '(deixe vazio para manter)' : '*' ?></label><input class="form-control" type="password" name="senha" minlength="8" <?= $id ? '' : 'required' ?>></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="ativo" value="1" id="ativo" <?= $r['ativo'] ? 'checked' : '' ?>><label class="form-check-label" for="ativo">Ativo</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" name="receber_emails" value="1" id="rem" <?= !empty($r['receber_emails']) ? 'checked' : '' ?>><label class="form-check-label" for="rem">Receber notificações por e-mail</label></div></div>
      </div>
      <button class="btn btn-primary mt-3">Salvar</button>
    </form></div></div>
    <?php view_footer(); exit;
}

$linhas = q('SELECT u.*, t.nome AS tecnico FROM usuarios u LEFT JOIN tecnicos t ON t.id = u.tecnico_id ORDER BY u.nome');
view_header('Usuários', 'usuarios'); ?>
<div class="page-title"><h1>Usuários</h1><a class="btn btn-warning btn-sm" href="<?= e(url('usuarios/form')) ?>"><i class="bi bi-plus-lg"></i> Novo</a></div>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
<thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Técnico</th><th>Último acesso</th><th>Status</th></tr></thead><tbody>
<?php foreach ($linhas as $l): ?>
<tr data-href="<?= e(url('usuarios/form', ['id' => $l['id']])) ?>"><td><?= e($l['nome']) ?></td><td><?= e($l['email']) ?></td><td><?= badge('perfil', $l['perfil']) ?></td>
<td><?= e($l['tecnico']) ?></td><td><?= dt($l['ultimo_login'], true) ?></td><td><?= $l['ativo'] ? 'Ativo' : '<span class="text-muted">Inativo</span>' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php view_footer();
