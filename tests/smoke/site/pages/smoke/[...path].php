<?php

/**
 * Smoke fixture: a catch-all page. /smoke/missing is a 404 the page decides on, after setting a
 * header that must not go out with the 404; /smoke/moved sets a cookie that must go out with its
 * redirect. /smoke/answered sets a cookie that must go out with the JSON it answers with instead of
 * the page. /smoke/canonical sets its canonical with url().
 */

use Golovanov\Weft\Http\Abort;
use Golovanov\Weft\Http\Response;
use Golovanov\Weft\Seo\Seo;

/** @var string $path */

if ($path === 'missing') {
    header('X-Smoke-Leak: 1');
    throw Abort::notFound();
}
if ($path === 'moved') {
    setcookie('smoke_moved', '1');
    throw Abort::redirect(url('/smoke/list'));
}
if ($path === 'answered') {
    setcookie('smoke_answered', '1');
    echo '<p>half a page</p>';
    throw Abort::response(Response::json(['saved' => false], 422));
}
if ($path === 'canonical') {
    Seo::set(['canonical' => url('/smoke/list')]);
}
?>
<p>catch-all path=<?= e($path) ?></p>
