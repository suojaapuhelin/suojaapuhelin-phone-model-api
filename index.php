<?php

header('Content-Type: application/json; charset=utf-8');

$apiUrl = rtrim(getenv('MCF_API_URL'), '/');
$user = getenv('MCF_API_USER');
$key = getenv('MCF_API_KEY');

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $user . ':' . $key,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_HEADER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo json_encode([
    'http_code' => $httpCode,
    'response' => substr($response, 0, 3000)
], JSON_PRETTY_PRINT);
