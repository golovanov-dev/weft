<?php use Golovanov\Weft\Support\Clock; ?>
<footer class="site-footer">
  <div class="wrap">
    <div class="footer-brand">
      <strong>Weft</strong>
      <span><?= t('footer.tagline') ?></span>
    </div>
    <nav class="footer-links" aria-label="Links">
      <a href="<?= e(url('/docs')) ?>"><?= t('nav.docs') ?></a>
      <a href="<?= e(config('app.links.github')) ?>" rel="noopener">GitHub</a>
      <a href="<?= e(config('app.links.issues')) ?>" rel="noopener"><?= t('footer.issues') ?></a>
      <a href="https://golovanov.dev" rel="noopener">golovanov.dev</a>
    </nav>
    <p class="footer-legal">
      © <?= Clock::date('Y') ?> Roman Golovanov · MIT · <?= t('footer.built_with') ?>
    </p>
  </div>
</footer>
