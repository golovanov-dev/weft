<?php

declare(strict_types=1);

use Golovanov\Weft\Http\Abort;
use Golovanov\Weft\Http\Response;

// An endpoint that answers with its own response: the printed output and the JSON Content-Type go.
echo '{"partial":';
throw Abort::response(Response::text('accepted', 202));
