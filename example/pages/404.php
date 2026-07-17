<?php

use Golovanov\Weft\Seo\Seo;

Seo::set(['title' => t('error.404.title')]);
Seo::noindex();
?>

<section class="page-hero error-page">
  <div class="wrap">
    <p class="eyebrow">404</p>
    <h1><?= t('error.404.title') ?></h1>
    <p class="lead"><?= t('error.404.text') ?></p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>"><?= t('error.404.home') ?></a>
  </div>
</section>
