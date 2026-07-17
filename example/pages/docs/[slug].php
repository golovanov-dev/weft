<?php

/**
 * A single documentation chapter: /docs/getting-started
 *
 * This file is a DYNAMIC route. The kernel matches the last URL segment against the [slug]
 * template and hands it over as $slug (and in $params['slug']). No route table involved.
 *
 * Translations for dynamic pages come from the parent directory name — lang/<locale>/docs.php.
 */

use App\Services\DocsService;
use Golovanov\Weft\I18n\Url;
use Golovanov\Weft\Seo\Seo;

/** @var string $slug */

$docs = new DocsService();
$chapter = $docs->find($slug);

// Unknown slug: render the 404 page instead of an empty chapter.
if ($chapter === null) {
    Seo::set(['title' => t('error.404.title')]);
    Seo::noindex();
    http_response_code(404);
    echo '<section class="page-hero error-page"><div class="wrap">'
        . '<p class="eyebrow">404</p>'
        . '<h1>' . e(t('error.404.title')) . '</h1>'
        . '<p class="lead">' . e(t('docs.page.missing')) . '</p>'
        . '<a class="btn btn-primary" href="' . e(url('/docs')) . '">' . e(t('docs.page.all')) . '</a>'
        . '</div></section>';
    return;
}

Seo::set([
    'title' => $chapter['title'],
    'description' => $chapter['description'],
    'type' => 'article',
]);
Seo::jsonLd([
    '@type' => 'TechArticle',
    'headline' => $chapter['title'],
    'description' => $chapter['description'],
    'url' => Url::absolute('/docs/' . $chapter['slug']),
]);

$all = $docs->all();
$neighbours = $docs->neighbours($chapter['slug']);
?>

<div class="docs-layout wrap">
  <aside class="docs-nav" aria-label="<?= e(t('docs.page.sidebar')) ?>">
    <p class="docs-nav-title"><?= t('docs.page.sidebar') ?></p>
    <ol>
      <?php foreach ($all as $item): ?>
        <li>
          <a href="<?= e(url('/docs/' . $item['slug'])) ?>"
             class="<?= $item['slug'] === $chapter['slug'] ? 'active' : '' ?>"
             <?= $item['slug'] === $chapter['slug'] ? 'aria-current="page"' : '' ?>>
            <?= e($item['title']) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </aside>

  <article class="docs-article">
    <p class="eyebrow"><?= t('docs.page.eyebrow') ?></p>
    <div class="prose-md">
      <?= $chapter['html'] ?>
    </div>

    <nav class="docs-pager" aria-label="<?= e(t('docs.page.pager')) ?>">
      <?php if ($neighbours['prev'] !== null): ?>
        <a class="prev" href="<?= e(url('/docs/' . $neighbours['prev']['slug'])) ?>">
          <span>← <?= t('docs.page.prev') ?></span>
          <strong><?= e($neighbours['prev']['title']) ?></strong>
        </a>
      <?php endif; ?>
      <?php if ($neighbours['next'] !== null): ?>
        <a class="next" href="<?= e(url('/docs/' . $neighbours['next']['slug'])) ?>">
          <span><?= t('docs.page.next') ?> →</span>
          <strong><?= e($neighbours['next']['title']) ?></strong>
        </a>
      <?php endif; ?>
    </nav>
  </article>
</div>
