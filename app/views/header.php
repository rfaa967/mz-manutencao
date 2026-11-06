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
<?= cdn_css('bootstrap@5.3.3/dist/css/bootstrap.min.css') ?>
<?= cdn_css('bootstrap-icons@1.11.3/font/bootstrap-icons.min.css') ?>
<link href="<?= e(asset('app.css')) ?>?v=<?= (int)@filemtime(APP_ROOT . '/public/assets/app.css') ?>" rel="stylesheet">
<link rel="icon" href="<?= e(asset('icone.svg')) ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
</head>
<body<?= !empty($GLOBALS['__auto_print']) ? ' data-auto-print="1"' : '' ?>>
<div class="d-none d-print-block mb-2 border-bottom pb-1"><strong><?= e(cfg('empresa')) ?></strong> · <?= e(cfg('app_nome')) ?> <span class="float-end small"><?= date('d/m/Y H:i') ?></span></div>
<?php if ($u): ?>
<nav class="navbar navbar-dark bg-dark d-lg-none px-3">
  <button class="btn btn-outline-light btn-sm" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Abrir menu"><i class="bi bi-list"></i></button>
  <span class="navbar-brand mb-0 h6"><?= e(cfg('app_nome')) ?></span>
</nav>
<div class="d-flex">
  <aside id="sidebar" class="offcanvas-lg offcanvas-start bg-dark text-white sidebar" tabindex="-1">
    <div class="p-3 border-bottom border-secondary position-relative">
      <button type="button" class="btn-close btn-close-white d-lg-none position-absolute top-0 end-0 m-3" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Fechar menu"></button>
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
      <a href="<?= e(url('m')) ?>" class="link-light me-3"><i class="bi bi-phone"></i> Mobile</a>
      <a href="<?= e(url('perfil')) ?>" class="link-light me-3">Minha conta</a>
      <form method="post" action="<?= e(url('logout')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-link link-warning p-0 align-baseline small">Sair</button></form>
    </div>
  </aside>
  <main class="flex-grow-1 p-3 p-lg-4 conteudo">
    <?php if (in_array($u['perfil'], ['tecnico', 'solicitante'], true)): ?><a href="<?= e(url('m')) ?>" class="alert alert-warning d-block d-md-none py-2 small text-decoration-none no-print"><i class="bi bi-phone"></i> Está no celular? Abra a <strong>versão mobile</strong>, com leitor de QR Code.</a><?php endif; ?>
<?php else: ?>
<main class="container py-5">
<?php endif; ?>
<?php foreach (flash() as [$tipo, $msg]): ?>
  <div class="alert alert-<?= e($tipo) ?> alert-dismissible fade show"><?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
