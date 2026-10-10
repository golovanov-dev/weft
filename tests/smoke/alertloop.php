<?php

/**
 * Smoke fixture: an AlertLoop ingest API for php -S. Every request becomes a JSON line in the file
 * named by SMOKE_ALERTS and is answered 202.
 */

file_put_contents((string) getenv('SMOKE_ALERTS'), json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'path' => $_SERVER['REQUEST_URI'] ?? '',
    'key' => $_SERVER['HTTP_X_API_KEY'] ?? '',
    'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);
http_response_code(202);
header('Content-Type: application/json');
echo '{}';
