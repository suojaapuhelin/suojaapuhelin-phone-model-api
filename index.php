<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$ALLOWED_ROOT_IDS = [
    248, // Puhelimen suojat
    348  // Tabletin suojat
];

function getCacheFile() {
    return __DIR__ . '/charger_cache.json';
}

function loadChargerCache() {
    $cacheFile = getCacheFile();
    if (!file_exists($cacheFile)) return [];
    $data = json_decode(file_get_contents($cacheFile), true);
    return is_array($data) ? $data : [];
}

function saveChargerCache(array $cache) {
    file_put_contents(getCacheFile(), json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function isVisibleCategory($cat) {
    if (isset($cat['visible']) && !$cat['visible']) return false;
    if (isset($cat['hidden']) && $cat['hidden']) return false;
    if (isset($cat['status']) && in_array($cat['status'], ['hidden', 'disabled', 'archived'])) return false;
    return true;
}

function isDescendantOf($categoryId, $rootId, $categoriesById) {
    $currentId = $categoryId;
    while (isset($categoriesById[$currentId])) {
        $parentId = $categoriesById[$currentId]['parent_id'] ?? 0;
        if ((int) $parentId === (int) $rootId) return true;
        if (!$parentId || (int) $parentId === 0) return false;
        $currentId = $parentId;
    }
    return false;
}

function getTopBrandUnderRoot($categoryId, $rootId, $categoriesById) {
    $currentId = $categoryId;
    while (isset($categoriesById[$currentId])) {
        $cat = $categoriesById[$currentId];
        $parentId = $cat['parent_id'] ?? 0;
        if ((int) $parentId === (int) $rootId) return $cat;
        if (!$parentId || (int) $parentId === 0) return null;
        $currentId = $parentId;
    }
    return null;
}

function getMatchedRootId($categoryId, $allowedRootIds, $categoriesById) {
    foreach ($allowedRootIds as $rootId) {
        if (isDescendantOf($categoryId, $rootId, $categoriesById)) return $rootId;
    }
    return null;
}

function getRecommendedMinWatts($wiredMaxW) {
    if (!$wiredMaxW) return null;
    if ($wiredMaxW <= 10) return 10;
    if ($wiredMaxW <= 15) return 15;
    if ($wiredMaxW <= 20) return 20;
    if ($wiredMaxW <= 27) return 25;
    if ($wiredMaxW <= 30) return 30;
    if ($wiredMaxW <= 45) return 45;
    if ($wiredMaxW <= 67) return 65;
    if ($wiredMaxW <= 100) return 100;
    return (int) $wiredMaxW;
}

function enrichChargingDataWithAI($modelName) {
    $apiKey = getenv('ANTHROPIC_API_KEY');
    if (!$apiKey) return null;

    $systemPrompt = 'Olet asiantuntija, joka hakee puhelimien lataustehotiedot. '
        . 'Palauta AINA pelkkä JSON-objekti ilman markdown-koodilohkoja tai muuta tekstiä. '
        . 'JSON-rakenne (kaikki kentät pakollisia, null jos ei tietoa): '
        . '{"wired_max_w":<numero tai null>,"wireless_max_w":<numero tai null>,'
        . '"wireless_protocol":<"MagSafe"|"Qi2"|"Qi"|null>,'
        . '"fast_charge_tech":<"PD 3.0"|"SuperVOOC"|"65W Flash Charge"|... tai null>,'
        . '"source":<"GSMArena"|"manufacturer"|muu lyhyt merkintä>}';

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 512,
        'tools'      => [['type' => 'web_search_20250305', 'name' => 'web_search']],
        'system'     => $systemPrompt,
        'messages'   => [[
            'role'    => 'user',
            'content' => 'Hae verkosta: ' . $modelName . ' latausteho maksimi wattimäärä johtolataus ja langaton lataus. Vastaa vain JSON.'
        ]]
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: web-search-2025-03-05',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return null;

    $data = json_decode($response, true);
    $text = '';
    foreach ($data['content'] ?? [] as $block) {
        if ($block['type'] === 'text') $text .= $block['text'];
    }

    $clean  = trim(preg_replace('/```json|```/', '', $text));
    $parsed = json_decode($clean, true);
    return is_array($parsed) ? $parsed : null;
}

// ─── Endpointit ──────────────────────────────────────────────────────────────

if ($path === '/debug') {
    $key = getenv('ANTHROPIC_API_KEY');
    echo json_encode([
        'key_set'     => !empty($key),
        'key_preview' => $key ? substr($key, 0, 15) . '...' : null
    ]);
    exit;
}

if ($path === '/enrich') {
    $model = $_GET['model'] ?? '';
    if (!$model) {
        echo json_encode(['status' => 'error', 'message' => 'Anna malli muodossa /enrich?model=iPhone%2015'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    $power = enrichChargingDataWithAI($model);
    echo json_encode(['model' => $model, 'power' => $power], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ─── MCF API ─────────────────────────────────────────────────────────────────

$apiUrl = rtrim(getenv('MCF_API_URL'), '/');
$user   = getenv('MCF_API_USER');
$key    = getenv('MCF_API_KEY');

$chargerCache   = loadChargerCache();
$allCategories  = [];
$categoriesById = [];
$page     = 1;
$pageSize = 100;

do {
    $endpoint = $apiUrl . '/categories?page_size=' . $pageSize . '&page=' . $page;
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $user . ':' . $key,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        echo json_encode(['status' => 'error', 'endpoint' => $endpoint, 'http_code' => $httpCode, 'error' => $error ?: null], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    $json = json_decode($response, true);
    foreach ($json['data'] ?? [] as $cat) {
        $allCategories[]            = $cat;
        $categoriesById[$cat['id']] = $cat;
    }

    $pageCount = $json['meta']['page_count'] ?? $page;
    $page++;
} while ($page <= $pageCount);

// ─── Mallilista + automaattinen AI-rikastus ───────────────────────────────────

$all          = [];
$cacheUpdated = false;

foreach ($allCategories as $cat) {
    $template = $cat['template'] ?? '';

    if (!isVisibleCategory($cat)) continue;

    $matchedRootId = getMatchedRootId($cat['id'], $ALLOWED_ROOT_IDS, $categoriesById);
    if (!$matchedRootId) continue;

    if (!str_starts_with($template, 'category/')) continue;
    if (!preg_match('/(usb-c|lightning|micro-usb|magsafe)/', $template)) continue;

    $brand = getTopBrandUnderRoot($cat['id'], $matchedRootId, $categoriesById);
    if (!$brand || !isVisibleCategory($brand)) continue;

    $nameLower = mb_strtolower($cat['name']);
    if (str_contains($nameLower, 'laturit') || str_contains($nameLower, 'kaapelit') ||
        str_contains($nameLower, 'latauskaapelit') || str_contains($nameLower, 'panssarilasit') ||
        str_contains($nameLower, 'suojakuoret') || str_contains($nameLower, 'suojakotelot')) {
        continue;
    }

    $modelKey = 'model_' . $cat['id'];
    $cached   = $chargerCache[$modelKey] ?? null;
    $power    = $cached['power'] ?? null;

    if ($power === null && getenv('ANTHROPIC_API_KEY')) {
        $power = enrichChargingDataWithAI($cat['name']);
        if ($power !== null) {
            $chargerCache[$modelKey] = [
                'power'      => $power,
                'fetched_at' => date('c'),
            ];
            $cacheUpdated = true;
        }
    }

    if ($power && isset($power['wired_max_w'])) {
        $power['recommended_min_watts'] = getRecommendedMinWatts($power['wired_max_w']);
    }

    $all[] = [
        'id'               => $cat['id'],
        'name'             => $cat['name'],
        'url'              => '/category/' . $cat['id'],
        'parent_id'        => $cat['parent_id'],
        'root_id'          => $matchedRootId,
        'brand_id'         => $brand['id'],
        'brand_name'       => $brand['name'],
        'template'         => $template,
        'charging'         => [
            'usb_c'     => str_contains($template, 'usb-c'),
            'lightning' => str_contains($template, 'lightning'),
            'micro_usb' => str_contains($template, 'micro-usb'),
            'magsafe'   => str_contains($template, 'magsafe'),
        ],
        'power'            => $power,
        'power_source'     => $power ? ($cached ? 'charger_cache' : 'ai_realtime') : null,
        'power_fetched_at' => $chargerCache[$modelKey]['fetched_at'] ?? null,
    ];
}

if ($cacheUpdated) {
    saveChargerCache($chargerCache);
}

usort($all, function ($a, $b) {
    $brandCompare = strcasecmp($a['brand_name'], $b['brand_name']);
    return $brandCompare !== 0 ? $brandCompare : strcasecmp($a['name'], $b['name']);
});

if ($path === '/models') {
    echo json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode([
    'status'        => 'ok',
    'count'         => count($all),
    'models_url'    => '/models',
    'allowed_roots' => $ALLOWED_ROOT_IDS,
    'models'        => $all
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
