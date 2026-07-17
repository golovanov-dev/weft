<?php

use Golovanov\Weft\I18n\Locale;
use Golovanov\Weft\I18n\Url;
?>
<header class="site-header">
  <div class="wrap">
    <a class="logo" href="<?= e(url('/')) ?>">weft<span>.</span></a>

    <nav class="nav" aria-label="Main">
      <a href="<?= e(url('/docs')) ?>" class="<?= is_active('docs') ?>"><?= t('nav.docs') ?></a>
      <a href="<?= e(url('/contact')) ?>" class="<?= is_active('contact') ?>"><?= t('nav.contact') ?></a>
      <a class="nav-cta" href="<?= e(config('app.links.github')) ?>" rel="noopener">GitHub</a>
    </nav>

    <?php if (Locale::enabled()): ?>
      <div class="lang-switch">
        <?php foreach (Locale::supported() as $code): ?>
          <a href="<?= e(Url::inLocale($code, Url::currentPath())) ?>"
             class="<?= $code === Locale::current() ? 'active' : '' ?>"
             hreflang="<?= e($code) ?>"><?= e(strtoupper($code)) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</header>
