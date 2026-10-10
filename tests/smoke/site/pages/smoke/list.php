<?php

/**
 * Smoke fixture: a list of 45 items, 10 per page.
 */

use Golovanov\Weft\Http\Pagination;

/** @var \Golovanov\Weft\Http\Request $request */

$pages = Pagination::fromRequest($request, 45, 10);
?>
<p>list page <?= $pages->page ?> of <?= $pages->pages ?></p>
