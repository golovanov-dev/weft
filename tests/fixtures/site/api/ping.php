<?php

declare(strict_types=1);

/** @var Golovanov\Weft\Http\Request $request */
echo json_encode(['ok' => true, 'method' => $request->method]);
