<?php
// Minimal PHP ping test - if you see JSON, PHP is serving this file correctly.
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'message' => 'PHP works !',
    'php_version' => PHP_VERSION,
    'time' => date('c'),
    'script' => __FILE__,
]);
