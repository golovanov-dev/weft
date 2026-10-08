<p>half a page</p>
<?php

ob_start(); // a buffer the template opened itself and never closed
echo 'nested';
throw new \RuntimeException('render failed');
