<?php unset($_SESSION['old']); ?><?php $aba = $GLOBALS['__aba'] ?? ''; ?>
</main>
<nav class="m-bottom">
  <a href="<?= e(url('m')) ?>" class="<?= $aba === 'inicio' ? 'ativo' : '' ?>"><i class="bi bi-house"></i><span>Início</span></a>
  <a href="<?= e(url('m/scan')) ?>" class="m-scan <?= $aba === 'scan' ? 'ativo' : '' ?>"><i class="bi bi-qr-code-scan"></i><span>Ler QR</span></a>
  <?php if (pode('ver')): ?>
    <a href="<?= e(url('m/ots')) ?>" class="<?= $aba === 'ots' ? 'ativo' : '' ?>"><i class="bi bi-wrench-adjustable"></i><span>OTs</span></a>
  <?php else: ?>
    <a href="<?= e(url('m/pedidos')) ?>" class="<?= $aba === 'pedidos' ? 'ativo' : '' ?>"><i class="bi bi-megaphone"></i><span>Pedidos</span></a>
  <?php endif; ?>
  <form method="post" action="<?= e(url('logout')) ?>" class="d-contents" data-confirm="Sair do sistema?"><?= csrf_field() ?>
    <button type="submit" class="m-sair"><i class="bi bi-box-arrow-right"></i><span>Sair</span></button></form>
</nav>
<?= cdn_js('bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js') ?>
<?php if (!empty($GLOBALS['__scripts'])) echo $GLOBALS['__scripts']; /* bibliotecas da página (cdn_js) */ ?>
<?php if (!empty($GLOBALS['__turnstile'])): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="<?= e(csp_nonce()) ?>"></script><?php endif; ?>
<?= js_local('app.js') ?>
</body>
</html>
