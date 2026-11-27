<?php

use Golovanov\Weft\Http\Abort;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\Seo\Seo;

// The template decides what exists under /docs/...
Seo::set(['title' => 'Docs page', 'canonical' => 'https://example.com/elsewhere']);
if ($path === 'gone') {
    throw Abort::notFound();
}
if ($path === 'moved') {
    throw Abort::redirect(url('/about'));
}
if ($path === 'answered') {
    echo '<p>half a form</p>';
    throw Abort::response(Response::json(['saved' => false], 422)->withHeader('X-Form', 'invalid'));
}
?>
<p>docs:<?= e($path) ?>|<?= e($params['path']) ?></p>
