<?php

use Golovanov\Weft\Http\Abort;

// A 404 page that tries to end itself early: the engine answers with its built-in 404 instead.
if (str_contains($request->uri, 'abort-404=not-found')) {
    throw Abort::notFound();
}
if (str_contains($request->uri, 'abort-404=redirect')) {
    throw Abort::redirect('/about');
}
?>
<p>custom-404</p>
