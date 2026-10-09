<?php

use Golovanov\Weft\Http\Pagination;
use Golovanov\Weft\Seo\Seo;

Seo::set(['title' => 'List']);
$pages = Pagination::fromRequest($request, 25, 10);
?>
<p>list:<?= $pages->page ?>/<?= $pages->pages ?></p>
