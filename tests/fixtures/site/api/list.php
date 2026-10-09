<?php

declare(strict_types=1);

use Golovanov\Weft\Http\Pagination;

/** @var Golovanov\Weft\Http\Request $request */
$pages = Pagination::fromRequest($request, 25, 10);
echo json_encode(['page' => $pages->page, 'next' => $pages->next]);
