<?php if ($slug === 'missing') {
    // Unknown content: the page answers 404 itself, like docs/[slug].php in the example.
    http_response_code(404);
    echo '<p>note-missing</p>';
    return;
} ?>
<p>note:<?= e($slug) ?></p>
