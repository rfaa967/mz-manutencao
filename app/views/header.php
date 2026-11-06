<?php
$u = usuario();
$menu = $GLOBALS['__menu'] ?? '';
$titulo = $GLOBALS['__titulo'] ?? '';
$pendPedidos = ($u && pode('gerir')) ? (int)qv("SELECT COUNT(*) FROM pedidos WHERE status = 'aberto'") : 0;
$itens = [
    ['dashboard', 'speedometer2', 'Painel', 'ver'],
    ['pedidos', 'megaphone', 'Pedidos de trabalho', 'solicitar'],
    ['ots', 'wrench-adjustable', 'Ordens de trabalho', 'ver'],
    ['calendario', 'calendar3', 'Calendário', 'ver'],
    ['planos', 'arrow-repeat', 'Planos preventivos', 'ver'],
    ['ativos', 'diagram-3', 'Ativos', 'ver'],
    ['contadores', 'stopwatch', 'Contadores', 'executar'],
    ['estoque', 'box-seam', 'Estoque / Peças', 'ver'],
    ['relatorios', 'bar-chart-line', 'Relatórios & KPIs', 'gerir'],
];
$cad = [
    ['localizacoes', 'Localizações'], ['categorias', 'Categorias de ativo'], ['fornecedores', 'Fornecedores'],
    ['tecnicos', 'Técnicos'], ['especialidades', 'Especialidades'],
];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · <?= e(cfg('app_nome')) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(asset('app.css')) ?>" rel="stylesheet">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 16 16%27%3E%3Ccircle cx=%278%27 cy=%278%27 r=%277%27 fill=%27%23ffc107%27/%3E%3C/svg%3E">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
</head>
<body>
<?php if ($u): ?>
<nav class="navbar navbar-dark bg-dark d-lg-none px-3">
  <button class="btn btn-outline-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#sidebar"><i class="bi bi-list"></i></button>
  <span class="navbar-brand mb-0 h6"><?= e(cfg('app_nome')) ?></span>
</nav>
<div class="d-flex">
  <aside id="sidebar" class="offcanvas-lg offcanvas-start bg-dark text-white sidebar" tabindex="-1">
    <div class="p-3 border-bottom border-secondary">
      <div class="fw-bold fs-5"><i class="bi bi-gear-wide-connected text-warning"></i> <?= e(cfg('app_nome')) ?></div>
      <div class="small text-secondary"><?= e(cfg('empresa')) ?></div>
    </div>
    <ul class="nav flex-column p-2">
      <?php foreach ($itens as [$r, $ic, $rot, $perm]): if (!pode($perm)) continue; ?>
        <li class="nav-item"><a class="nav-link <?= $menu === $r ? 'active' : '' ?>" href="<?= e(url($r)) ?>"><i class="bi bi-<?= $ic ?>"></i> <?= e($rot) ?>
          <?php if ($r === 'pedidos' && $pendPedidos): ?><span class="badge text-bg-warning float-end"><?= $pendPedidos ?></span><?php endif; ?></a></li>
      <?php endforeach; ?>
      <?php if (pode('gerir')): ?>
        <li class="nav-item mt-2"><span class="nav-link text-uppercase small text-secondary">Cadastros</span></li>
        <?php foreach ($cad as [$t, $rot]): ?>
          <li class="nav-item"><a class="nav-link py-1 ps-4 <?= $menu === "cad_$t" ? 'active' : '' ?>" href="<?= e(url('cadastros', ['t' => $t])) ?>"><?= e($rot) ?></a></li>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if (pode('admin')): ?>
        <li class="nav-item"><a class="nav-link py-1 ps-4 <?= $menu === 'usuarios' ? 'active' : '' ?>" href="<?= e(url('usuarios')) ?>">Usuários</a></li>
      <?php endif; ?>
    </ul>
    <div class="mt-auto p-3 border-top border-secondary small">
      <div><i class="bi bi-person-circle"></i> <?= e($u['nome']) ?></div>
      <div class="text-secondary mb-2"><?= e(rotulo('perfil', $u['perfil'])) ?></div>
      <a href="<?= e(url('perfil')) ?>" class="link-light me-3">Minha conta</a>
      <a href="<?= e(url('logout')) ?>" class="link-warning">Sair</a>
    </div>
  </aside>
  <main class="flex-grow-1 p-3 p-lg-4 conteudo">
<?php else: ?>
<main class="container py-5">
<?php endif; ?>
<?php foreach (flash() as [$tipo, $msg]): ?>
  <div class="alert alert-<?= e($tipo) ?> alert-dismissible fade show"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
