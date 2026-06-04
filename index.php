<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/enrich') {
    $model = $_GET['model'] ?? '';

    if (!$model) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Anna malli muodossa /enrich?model=iPhone%2015'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    echo json_encode(
        enrichChargingDataWithAI($model),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
    exit;
}

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
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo json_encode([
            'status' => 'error',
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'error' => $error ?: null
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

if ($path === '/models') {
    echo json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'count' => count($all),
    'models_url' => '/models',
    'models' => $all
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
function enrichChargingDataWithAI($modelName) {
    $apiKey = getenv('OPENAI_API_KEY');

    if (!$apiKey) {
        return [
            'status' => 'error',
            'message' => 'OPENAI_API_KEY puuttuu Railwayssä'
        ];
    }

    $payload = [
        'model' => 'gpt-5.5-mini',
        'tools' => [
            ['type' => 'web_search']
        ],
        'input' => [
            [
                'role' => 'system',
                'content' => 'Olet tuotetietojen rikastaja suomalaiselle puhelintarvikeverkkokaupalle. Selvitä vain luotettavat lataustiedot. Älä keksi. Jos tieto on epävarma, merkitse confidence matalaksi.'
            ],
            [
                'role' => 'user',
                'content' => 'Selvitä puhelinmallin "' . $modelName . '" lataustiedot. Palauta JSON: model, charging_port, recommended_min_watts, estimated_max_watts, wireless_charging, magsafe_or_magnetic, confidence, sources, notes.'
            ]
        ]
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 90,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $httpCode >= 200 && $httpCode < 300 ? 'ok' : 'error',
        'http_code' => $httpCode,
        'curl_error' => $error ?: null,
        'raw_response' => json_decode($response, true)
    ];
}
?>
