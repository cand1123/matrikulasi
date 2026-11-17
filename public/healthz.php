<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(array(
    'status' => 'ok',
    'runtime' => 'FrankenPHP',
    'php' => PHP_VERSION
));
