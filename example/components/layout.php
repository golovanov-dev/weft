<?php

/**
 * Page layout. Receives $content (the rendered page) from the Kernel.
 *
 * SEO is under the hood: seo() emits title, description, canonical, robots, Open Graph,
 * Twitter, hreflang alternates and JSON-LD — pages only set what differs. Assets are wired
 * through Vite (dev server with HMR when DEV_MODE is on, hashed manifest files otherwise).
 */

use Golovanov\Weft\Assets\Vite;
use Golovanov\Weft\Config\Config;
use Golovanov\Weft\Security\Captcha;

/** @var string $content */
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <?= seo() ?>

  <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  <?= Vite::tags(Config::get('app.vite.entry', 'src/main.js')) ?>

  <?php if (!Vite::devMode() && Config::env('G_ANALYTICS', '')): ?>
  <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e(Config::env('G_ANALYTICS')) ?>"></script>
  <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '<?= e(Config::env('G_ANALYTICS')) ?>');
  </script>
  <?php endif; ?>
</head>
<body data-recaptcha-sitekey="<?= e(Captcha::siteKey()) ?>">
  <a class="skip-link" href="#main">Skip to content</a>

  <?php require __DIR__ . '/header.php'; ?>

  <main id="main">
    <?= $content ?>
  </main>

  <?php require __DIR__ . '/footer.php'; ?>

  <?php if (Captcha::enabled()): ?>
  <script src="https://www.google.com/recaptcha/api.js?render=<?= e(Captcha::siteKey()) ?>" async defer></script>
  <?php endif; ?>
</body>
</html>
