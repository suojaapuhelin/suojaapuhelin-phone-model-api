<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

echo json_encode([
    "status" => "ok",
    "url" => getenv('MCF_API_URL'),
    "user" => getenv('MCF_API_USER'),
    "api_key_exists" => !empty(getenv('MCF_API_KEY'))
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
