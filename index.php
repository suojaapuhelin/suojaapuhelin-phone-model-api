<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/**
 * Mukaan otettavat juurituoteryhmät:
 * 248 = Puhelimen suojat
 * 348 = Tabletin suojat
 */
$ALLOWED_ROOT_IDS = [
    248,
    348
];

/**
 * Lataa paikallinen charger_cache.json.
 * Tiedoston pitää olla samassa GitHub-repossa kuin index.php.
 */
function loadChargerCache() {
    $cacheFile = __DIR__ . '/charger_cache.json';

    if (!file_exists($cacheFile)) {
        return [];
    }

    $json = file_get_contents($cacheFile);
    $data = json_decode($json, true);

    return is_array($data) ? $data : [];
}

/**
 * Tarkistaa onko tuoteryhmä näkyvissä.
 * MyCashflow API:n kentät voivat vaihdella, joten tarkistus on varovainen.
 */
function isVisibleCategory($cat) {
    if (isset($cat['visible']) && !$cat['visible']) {
        return false;
    }

    if (isset($cat['hidden']) && $cat['hidden']) {
        return false;
    }

    if (isset($cat['status']) && in_array($cat['status'], ['hidden', 'disabled', 'archived'])) {
        return false;
    }

    return true;
}

/**
 * Tarkistaa kuuluuko tuoteryhmä tietyn juurituoteryhmän alle.
 */
function isDescendantOf($categoryId, $rootId, $categoriesById) {
    $currentId = $categoryId;

    while (isset($categoriesById[$currentId])) {
        $parentId = $categoriesById[$currentId]['parent_id'] ?? 0;

        if ((int) $parentId === (int) $rootId) {
            return true;
        }

        if (!$parentId || (int) $parentId === 0) {
            return false;
        }

        $currentId = $parentId;
    }

    return false;
}

/**
 * Hakee merkkituoteryhmän juurituoteryhmän alta.
 * Esim. Puhelimen suojat → Samsung → Galaxy S24
 * palauttaa Samsung.
 */
function getTopBrandUnderRoot($categoryId, $rootId, $categoriesById) {
    $currentId = $categoryId;

    while (isset($categoriesById[$currentId])) {
        $cat = $categoriesById[$currentId];
        $parentId = $cat['parent_id'] ?? 0;

        if ((int) $parentId === (int) $rootId) {
            return $cat;
        }

        if (!$parentId || (int) $parentId === 0) {
            return null;
        }

        $currentId = $parentId;
    }

    return null;
}

/**
 * Selvittää minkä sallitun juuren alla tuoteryhmä on.
 */
function getMatchedRootId($categoryId, $allowedRootIds, $categoriesById) {
    foreach ($allowedRootIds as $rootId) {
        if (isDescendantOf($categoryId, $rootId, $categoriesById)) {
            return $rootId;
        }
    }

    return null;
}

/**
 * Pyöristää asiakkaalle näytettävän vähimmäistehon järkeviin portaisiin.
 * Esim. 27W → 25W, 45W → 45W, 66W → 65W.
 */
function getRecommendedMinWatts($wiredMaxW) {
    if (!$wiredMaxW) {
        return null;
    }

    if ($wiredMaxW <= 10) {
        return 10;
    }

    if ($wiredMaxW <= 15) {
        return 15;
    }

    if ($wiredMaxW <= 20) {
        return 20;
    }

    if ($wiredMaxW <= 27) {
        return 25;
    }

    if ($wiredMaxW <= 30) {
        return 30;
    }

    if ($wiredMaxW <= 45) {
        return 45;
    }

    if ($wiredMaxW <= 67) {
        return 65;
    }

    if ($wiredMaxW <= 100) {
        return 100;
    }

    return (int) $wiredMaxW;
}

/**
 * AI-rikastus yksittäiselle mallille.
 * Käytä tätä vain testaukseen / puuttuviin malleihin.
 */
function enrichChargingDataWithAI($modelName) {
    $apiKey = getenv('OPENAI_API_KEY');

    if (!$apiKey) {
        return [
            'status' => 'error',
            'message' => 'OPENAI_API_KEY puuttuu Railwayssä'
        ];
    }

    $payload = [
        'model' => 'gpt-4.1-mini',
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

$chargerCache = loadChargerCache();

$allCategories = [];
$categoriesById = [];

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
        $allCategories[] = $cat;
        $categoriesById[$cat['id']] = $cat;
    }

    $pageCount = $json['meta']['page_count'] ?? $page;
    $page++;

} while ($page <= $pageCount);

$all = [];

foreach ($allCategories as $cat) {
    $template = $cat['template'] ?? '';

    if (!isVisibleCategory($cat)) {
        continue;
    }

    $matchedRootId = getMatchedRootId($cat['id'], $ALLOWED_ROOT_IDS, $categoriesById);

    if (!$matchedRootId) {
        continue;
    }

    if (!str_starts_with($template, 'category/')) {
        continue;
    }

    if (!preg_match('/(usb-c|lightning|micro-usb|magsafe)/', $template)) {
        continue;
    }

    $brand = getTopBrandUnderRoot($cat['id'], $matchedRootId, $categoriesById);

    if (!$brand || !isVisibleCategory($brand)) {
        continue;
    }

    $modelKey = 'model_' . $cat['id'];
    $cached = $chargerCache[$modelKey] ?? null;
    $power = $cached['power'] ?? null;

    if ($power && isset($power['wired_max_w'])) {
        $power['recommended_min_watts'] = getRecommendedMinWatts($power['wired_max_w']);
    }

    $all[] = [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'url' => '/category/' . $cat['id'],
        'parent_id' => $cat['parent_id'],
        'root_id' => $matchedRootId,
        'brand_id' => $brand['id'],
        'brand_name' => $brand['name'],
        'template' => $template,
        'charging' => [
            'usb_c' => str_contains($template, 'usb-c'),
            'lightning' => str_contains($template, 'lightning'),
            'micro_usb' => str_contains($template, 'micro-usb'),
            'magsafe' => str_contains($template, 'magsafe')
        ],
        'power' => $power,
        'power_source' => $power ? 'charger_cache' : null,
        'power_fetched_at' => $cached['fetched_at'] ?? null
    ];
}

usort($all, function ($a, $b) {
    $brandCompare = strcasecmp($a['brand_name'], $b['brand_name']);

    if ($brandCompare !== 0) {
        return $brandCompare;
    }

    return strcasecmp($a['name'], $b['name']);
});

if ($path === '/models') {
    echo json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'status' => 'ok',
    'count' => count($all),
    'models_url' => '/models',
    'allowed_roots' => $ALLOWED_ROOT_IDS,
    'models' => $all
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

?>
