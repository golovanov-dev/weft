<?php

/**
 * Docs index: /docs
 *
 * A directory index page — pages/docs/index.php answers /docs, while pages/docs/[slug].php
 * answers /docs/anything.
 */

use App\Services\DocsService;
use Golovanov\Weft\Seo\Seo;

Seo::set([
    'title' => t('docs.index.meta_title'),
    'description' => t('docs.index.meta_description'),
]);

$chapters = (new DocsService())->all();
?>

<section class="page-hero">
  <div class="wrap">
    <p class="eyebrow"><?= t('docs.index.eyebrow') ?></p>
    <h1><?= t('docs.index.title') ?></h1>
    <p class="lead"><?= t('docs.index.lead') ?></p>
  </div>
</section>

<section class="chapters">
  <div class="wrap">
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
