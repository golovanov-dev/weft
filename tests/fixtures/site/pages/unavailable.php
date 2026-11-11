<?php

use Golovanov\Weft\Http\ServiceUnavailable;

throw new ServiceUnavailable('payments down', 30);
