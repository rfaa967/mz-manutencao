<?php unset($_SESSION['old']); ?><?php if (usuario()): ?>
  </main>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= e(asset('app.js')) ?>"></script>
<?php if (!empty($GLOBALS['__scripts'])) echo $GLOBALS['__scripts']; ?>
</body>
</html>
