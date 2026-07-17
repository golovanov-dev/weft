<?php

/**
 * Landing page of the docs site.
 *
 * Presentation only: no business logic here. SEO defaults come from config; this page keeps the
 * configured default title (it is already the product line) and adds a WebSite schema.
 */

use App\Services\DocsService;
use Golovanov\Weft\Seo\Seo;

Seo::set(['title' => null, 'type' => 'website']);
Seo::jsonLd([
    '@type' => 'WebSite',
    'name' => config('app.seo.site_name'),
    'url' => config('app.url.base') . '/',
]);

$chapters = (new DocsService())->all();
$features = ['routing', 'seo', 'i18n', 'cache', 'trace', 'forms'];
?>

<section class="hero">
  <div class="wrap">
    <p class="eyebrow"><?= t('home.hero.eyebrow') ?></p>
    <h1><?= t('home.hero.title') ?></h1>
    <p class="lead"><?= t('home.hero.lead') ?></p>
    <div class="actions">
      <a class="btn btn-primary" href="<?= e(url('/docs')) ?>"><?= t('home.hero.cta_primary') ?></a>
      <a class="btn btn-ghost" href="<?= e(config('app.links.github')) ?>"><?= t('home.hero.cta_secondary') ?></a>
    </div>
    <p class="trust"><code>composer require golovanov/weft</code></p>
  </div>
</section>

<section class="showcase">
  <div class="wrap">
    <h2><?= t('home.showcase.title') ?></h2>
    <p class="lead"><?= t('home.showcase.lead') ?></p>
    <pre class="code"><code>&lt;?php // pages/about.php  →  /about
use Golovanov\Weft\Seo\Seo;

Seo::set(['title' =&gt; 'About', 'description' =&gt; 'What I do.']);
?&gt;
&lt;h1&gt;&lt;?= t('about.title') ?&gt;&lt;/h1&gt;
&lt;a href="&lt;?= e(url('/contact')) ?&gt;"&gt;Contact&lt;/a&gt;</code></pre>
    <p class="note"><?= t('home.showcase.note') ?></p>
  </div>
</section>

<section class="features">
  <div class="wrap">
    <h2><?= t('home.features.title') ?></h2>
    <div class="grid">
      <?php foreach ($features as $key): ?>
        <article class="card">
          <h3><?= t("home.features.$key.title") ?></h3>
          <p><?= t("home.features.$key.text") ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="chapters">
  <div class="wrap">
    <h2><?= t('home.docs.title') ?></h2>
    <ol class="chapter-list">
      <?php foreach ($chapters as $chapter): ?>
        <li>
          <a href="<?= e(url('/docs/' . $chapter['slug'])) ?>">
            <strong><?= e($chapter['title']) ?></strong>
            <span><?= e($chapter['description']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<section class="cta-band">
  <div class="wrap">
    <p><?= t('home.cta.text') ?></p>
    <a class="btn btn-primary" href="<?= e(url('/docs/getting-started')) ?>"><?= t('home.cta.button') ?></a>
  </div>
</section>
