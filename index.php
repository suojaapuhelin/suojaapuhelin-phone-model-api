<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$apiUrl = rtrim(getenv('MCF_API_URL'), '/');
$user = getenv('MCF_API_USER');
$key = getenv('MCF_API_KEY');

$paths = [
    '/',
    '/products',
    '/product-categories',
    '/product_categories',
    '/categories',
    '/category',
    '/productgroups',
    '/product-groups',
    '/groups'
];

$results = [];

foreach ($paths as $path) {
    $endpoint = $apiUrl . $path;

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

    $results[] = [
        'path' => $path,
        'endpoint' => $endpoint,
        'http_code' => $httpCode,
        'curl_error' => $error ?: null,
        'preview' => $response ? mb_substr($response, 0, 300) : null
    ];
}

echo json_encode([
    'status' => 'tested',
    'base_url' => $apiUrl,
    'results' => $results
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
