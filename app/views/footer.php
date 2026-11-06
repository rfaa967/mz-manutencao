<?php unset($_SESSION['old']); ?><?php if (usuario()): ?>
  </main>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<?= cdn_js('bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js') ?>
<?php if (!empty($GLOBALS['__scripts'])) echo $GLOBALS['__scripts']; /* bibliotecas da página (cdn_js) */ ?>
<?php if (!empty($GLOBALS['__turnstile'])): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="<?= e(csp_nonce()) ?>"></script><?php endif; ?>
<?= js_local('app.js') ?>
</body>
</html>
