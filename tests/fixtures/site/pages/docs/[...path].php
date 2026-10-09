<?php

use Golovanov\Weft\Http\Abort;
use Golovanov\Weft\Seo\Seo;

// The template decides what exists under /docs/...
Seo::set(['title' => 'Docs page', 'canonical' => 'https://example.com/elsewhere']);
if ($path === 'gone') {
    throw Abort::notFound();
}
if ($path === 'moved') {
    throw Abort::redirect(url('/about'));
}
?>
<p>docs:<?= e($path) ?>|<?= e($params['path']) ?></p>
