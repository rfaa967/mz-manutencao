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
  <form method="post" action="<?= e(url('logout')) ?>" class="d-contents" onsubmit="return confirm('Sair do sistema?')"><?= csrf_field() ?>
    <button type="submit" class="m-sair"><i class="bi bi-box-arrow-right"></i><span>Sair</span></button></form>
</nav>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('app.js')) ?>"></script>
<script>if ('serviceWorker' in navigator) navigator.serviceWorker.register(<?= json_encode(rtrim((string)cfg('base_url', ''), '/') . '/sw.js') ?>).catch(function(){});</script>
<?php if (!empty($GLOBALS['__scripts'])) echo $GLOBALS['__scripts']; ?>
</body>
</html>
