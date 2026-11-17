<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

echo json_encode(array(
    'status' => 'ok',
    'runtime' => 'FrankenPHP',
    'php' => PHP_VERSION,
    'curl' => function_exists('curl_init')
), JSON_UNESCAPED_SLASHES);
