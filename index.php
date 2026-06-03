<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$apiUrl = rtrim(getenv('MCF_API_URL'), '/');
$user = getenv('MCF_API_USER');
$key = getenv('MCF_API_KEY');

$endpoint = $apiUrl . '/categories';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $user . ':' . $key,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo json_encode([
    'status' => $httpCode >= 200 && $httpCode < 300 ? 'ok' : 'error',
    'endpoint' => $endpoint,
    'http_code' => $httpCode,
    'curl_error' => $error ?: null,
    'response_preview' => $response ? mb_substr($response, 0, 1000) : null
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
