<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$apiUrl = rtrim(getenv('MCF_API_URL'), '/');
$user = getenv('MCF_API_USER');
$key = getenv('MCF_API_KEY');

$all = [];
$page = 1;
$pageSize = 100;

do {
    $endpoint = $apiUrl . '/categories?page_size=' . $pageSize . '&page=' . $page;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $user . ':' . $key,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo json_encode([
            'status' => 'error',
            'endpoint' => $endpoint,
            'http_code' => $httpCode
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $json = json_decode($response, true);

    foreach ($json['data'] ?? [] as $cat) {
        $template = $cat['template'] ?? '';

        if (!str_starts_with($template, 'category/')) {
            continue;
        }

        if (!preg_match('/(usb-c|lightning|micro-usb|magsafe)/', $template)) {
            continue;
        }

        $all[] = [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'url' => '/category/' . $cat['id'],
            'parent_id' => $cat['parent_id'],
            'template' => $template,
            'charging' => [
                'usb_c' => str_contains($template, 'usb-c'),
                'lightning' => str_contains($template, 'lightning'),
                'micro_usb' => str_contains($template, 'micro-usb'),
                'magsafe' => str_contains($template, 'magsafe')
            ]
        ];
    }

    $pageCount = $json['meta']['page_count'] ?? $page;
    $page++;

} while ($page <= $pageCount);

echo json_encode([
    'status' => 'ok',
    'count' => count($all),
    'models' => $all
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
