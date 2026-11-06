<?php
$u = usuario();
$titulo = $GLOBALS['__titulo'] ?? '';
$voltar = $GLOBALS['__voltar'] ?? null;
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#212529">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title><?= e($titulo) ?> · <?= e(cfg('app_nome')) ?></title>
<link rel="manifest" href="<?= e(rtrim((string)cfg('base_url', ''), '/') . '/manifest.php') ?>">
<link rel="icon" href="<?= e(asset('icone.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('icone-192.png')) ?>">
<?= cdn_css('bootstrap@5.3.3/dist/css/bootstrap.min.css') ?>
<?= cdn_css('bootstrap-icons@1.11.3/font/bootstrap-icons.min.css') ?>
<link href="<?= e(asset('mobile.css')) ?>?v=<?= (int)@filemtime(APP_ROOT . '/public/assets/mobile.css') ?>" rel="stylesheet">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
</head>
<body class="m-body" data-sw="<?= e(rtrim((string)cfg('base_url', ''), '/') . '/sw.js') ?>">
<header class="m-top">
  <?php if ($voltar): ?><a href="<?= e($voltar) ?>" class="m-top-btn" aria-label="Voltar"><i class="bi bi-chevron-left"></i></a>
  <?php else: ?><span class="m-top-btn"><i class="bi bi-gear-wide-connected text-warning"></i></span><?php endif; ?>
  <div class="m-top-titulo"><?= e($titulo) ?></div>
  <?php if (pode('ver')): ?><a href="<?= e(url('dashboard')) ?>" class="m-top-btn" aria-label="Versão completa" title="Versão completa"><i class="bi bi-display"></i></a><?php else: ?><span class="m-top-btn"></span><?php endif; ?>
</header>
<main class="m-main">
<?php foreach (flash() as [$tipo, $msg]): ?>
  <div class="alert alert-<?= e($tipo) ?> py-2"><?= e($msg) ?></div>
<?php endforeach; ?>
