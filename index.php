<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$ALLOWED_ROOT_IDS = [
    248, // Puhelimen suojat
    348  // Tabletin suojat
];

function getCacheFile() {
    $dir = '/app/storage';

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir . '/charger_cache.json';
}

function loadChargerCache() {
    $cacheFile = getCacheFile();

    if (!file_exists($cacheFile)) {
        return [];
    }

    $data = json_decode(file_get_contents($cacheFile), true);

    return is_array($data) ? $data : [];
}

function saveChargerCache(array $cache) {
    return file_put_contents(
        getCacheFile(),
        json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    ) !== false;
}

function isVisibleCategory($cat) {
    if (isset($cat['visible']) && !$cat['visible']) return false;
    if (isset($cat['hidden']) && $cat['hidden']) return false;

    if (
        isset($cat['status']) &&
        in_array($cat['status'], ['hidden', 'disabled', 'archived'], true)
    ) {
        return false;
    }

    return true;
}

function isDescendantOf($categoryId, $rootId, $categoriesById) {
    $currentId = $categoryId;
    $visited = [];

    while (isset($categoriesById[$currentId])) {
        if (isset($visited[$currentId])) {
            break;
        }

        $visited[$currentId] = true;
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

function getTopBrandUnderRoot($categoryId, $rootId, $categoriesById) {
    $currentId = $categoryId;
    $visited = [];

    while (isset($categoriesById[$currentId])) {
        if (isset($visited[$currentId])) {
            break;
        }

        $visited[$currentId] = true;
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

function getMatchedRootId($categoryId, $allowedRootIds, $categoriesById) {
    foreach ($allowedRootIds as $rootId) {
        if (isDescendantOf($categoryId, $rootId, $categoriesById)) {
            return $rootId;
        }
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

/**
 * Hakee lataustiedot Anthropicilta web-haulla.
 *
 * Palauttaa:
 * - array = onnistunut JSON-vastaus
 * - null  = haku epäonnistui tai vastausta ei voitu jäsentää
 */
function enrichChargingDataWithAI($modelName) {
    $apiKey = getenv('ANTHROPIC_API_KEY');

    if (!$apiKey) {
        return null;
    }

    $systemPrompt =
        'Olet asiantuntija, joka hakee puhelimien ja tablettien lataustehotiedot. '
        . 'Palauta AINA pelkkä JSON-objekti ilman markdown-koodilohkoja tai muuta tekstiä. '
        . 'JSON-rakenne (kaikki kentät pakollisia, null jos ei tietoa): '
        . '{"wired_max_w":<numero tai null>,'
        . '"wireless_max_w":<numero tai null>,'
        . '"wireless_protocol":<"MagSafe"|"Qi2"|"Qi"|null>,'
        . '"fast_charge_tech":<"PD 3.0"|"SuperVOOC"|"65W Flash Charge"|... tai null>,'
        . '"source":<"GSMArena"|"manufacturer"|muu lyhyt merkintä>}';

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-6',
        'max_tokens' => 512,
        'tools'      => [
            [
                'type' => 'web_search_20250305',
                'name' => 'web_search'
            ]
        ],
        'system'   => $systemPrompt,
        'messages' => [
            [
                'role'    => 'user',
                'content' =>
                    'Hae verkosta mallin "' . $modelName . '" lataustiedot. '
                    . 'Tarvitsen erityisesti maksimi johtolataustehon watteina, '
                    . 'maksimi langattoman lataustehon, langattoman latauksen protokollan '
                    . 'sekä pikalataustekniikan. Vastaa vain JSON.'
            ]
        ]
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
        CURLOPT_TIMEOUT => 35,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        return null;
    }

    $text = '';

    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'] ?? '';
        }
    }

    if ($text === '') {
        return null;
    }

    $clean = trim(
        preg_replace('/```json|```/i', '', $text)
    );

    $parsed = json_decode($clean, true);

    if (!is_array($parsed)) {
        return null;
    }

    // Normalisoidaan pakolliset kentät
    $parsed = [
        'wired_max_w'       => isset($parsed['wired_max_w']) && is_numeric($parsed['wired_max_w'])
            ? (float) $parsed['wired_max_w']
            : null,
        'wireless_max_w'    => isset($parsed['wireless_max_w']) && is_numeric($parsed['wireless_max_w'])
            ? (float) $parsed['wireless_max_w']
            : null,
        'wireless_protocol' => $parsed['wireless_protocol'] ?? null,
        'fast_charge_tech'  => $parsed['fast_charge_tech'] ?? null,
        'source'            => $parsed['source'] ?? null,
    ];

    // Jos johtolataustehoa ei löytynyt, mallia ei katsota onnistuneesti rikastetuksi.
    if ($parsed['wired_max_w'] === null) {
        return null;
    }

    return $parsed;
}

function fetchAllModels($apiUrl, $user, $key, $ALLOWED_ROOT_IDS) {
    $allCategories = [];
    $categoriesById = [];
    $page = 1;
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
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            break;
        }

        $json = json_decode($response, true);

        foreach ($json['data'] ?? [] as $cat) {
            $allCategories[] = $cat;
            $categoriesById[$cat['id']] = $cat;
        }

        $pageCount = $json['meta']['page_count'] ?? $page;
        $page++;

    } while ($page <= $pageCount);

    $models = [];

    foreach ($allCategories as $cat) {
        $template = $cat['template'] ?? '';

        if (!isVisibleCategory($cat)) {
            continue;
        }

        $matchedRootId = getMatchedRootId(
            $cat['id'],
            $ALLOWED_ROOT_IDS,
            $categoriesById
        );

        if (!$matchedRootId) {
            continue;
        }

        if (!str_starts_with($template, 'category/')) {
            continue;
        }

        if (!preg_match('/(usb-c|lightning|micro-usb|magsafe)/', $template)) {
            continue;
        }

        $brand = getTopBrandUnderRoot(
            $cat['id'],
            $matchedRootId,
            $categoriesById
        );

        if (!$brand || !isVisibleCategory($brand)) {
            continue;
        }

        $nameLower = mb_strtolower($cat['name']);

        if (
            str_contains($nameLower, 'laturit') ||
            str_contains($nameLower, 'kaapelit') ||
            str_contains($nameLower, 'latauskaapelit') ||
            str_contains($nameLower, 'panssarilasit') ||
            str_contains($nameLower, 'suojakuoret') ||
            str_contains($nameLower, 'suojakotelot')
        ) {
            continue;
        }

        $models[] = [
            'cat'           => $cat,
            'brand'         => $brand,
            'matchedRootId' => $matchedRootId,
            'template'      => $template,
        ];
    }

    return $models;
}


// ─────────────────────────────────────────────────────────────────────────────
// Ympäristömuuttujat
// ─────────────────────────────────────────────────────────────────────────────

$apiUrl = rtrim(getenv('MCF_API_URL'), '/');
$user   = getenv('MCF_API_USER');
$key    = getenv('MCF_API_KEY');


// ─────────────────────────────────────────────────────────────────────────────
// /debug
// ─────────────────────────────────────────────────────────────────────────────

if ($path === '/debug') {
    $apiKey = getenv('ANTHROPIC_API_KEY');
    $cacheFile = getCacheFile();
    $dir = dirname($cacheFile);
    $cache = loadChargerCache();

    echo json_encode([
        'key_set'      => !empty($apiKey),
        'key_preview'  => $apiKey ? substr($apiKey, 0, 15) . '...' : null,
        'cache_file'   => $cacheFile,
        'dir_exists'   => is_dir($dir),
        'dir_writable' => is_writable($dir),
        'file_exists'  => file_exists($cacheFile),
        'cache_count'  => count($cache),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// /enrich?model=...
// Yksittäisen mallin testihaku.
// ─────────────────────────────────────────────────────────────────────────────

if ($path === '/enrich') {
    $model = trim($_GET['model'] ?? '');

    if (!$model) {
        echo json_encode([
            'status'  => 'error',
            'message' => 'Anna malli muodossa /enrich?model=iPhone%2018%20Pro'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        exit;
    }

    $power = enrichChargingDataWithAI($model);

    echo json_encode([
        'status' => $power !== null ? 'ok' : 'failed',
        'model'  => $model,
        'power'  => $power
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// /enrich-all
//
// Hakee puuttuvat lataustiedot.
// Max 5 mallia / kutsu.
//
// TÄRKEÄÄ:
// - Ohitetaan VAIN mallit, joilla power on oikeasti olemassa.
// - Aiemmin epäonnistuneet mallit yritetään uudelleen.
// - Epäonnistuneet eivät kasvata enriched-laskuria.
// - failed_models näyttää suoraan mitkä mallit epäonnistuivat.
// ─────────────────────────────────────────────────────────────────────────────

if ($path === '/enrich-all') {
    set_time_limit(180);

    $maxPerCall = 5;

    $cache  = loadChargerCache();
    $models = fetchAllModels(
        $apiUrl,
        $user,
        $key,
        $ALLOWED_ROOT_IDS
    );

    $processed = 0;
    $enriched  = 0;
    $failed    = 0;
    $skipped   = 0;
    $remaining = 0;

    $enrichedModels = [];
    $failedModels   = [];

    foreach ($models as $m) {
        $cat      = $m['cat'];
        $modelKey = 'model_' . $cat['id'];
        $cached   = $cache[$modelKey] ?? null;

        // Ohita vain jos power on oikeasti olemassa.
        if (
            $cached !== null &&
            isset($cached['power']) &&
            is_array($cached['power']) &&
            ($cached['power']['wired_max_w'] ?? null) !== null
        ) {
            $skipped++;
            continue;
        }

        // Tämän kutsun 5 mallin raja täynnä.
        if ($processed >= $maxPerCall) {
            $remaining++;
            continue;
        }

        $processed++;

        $power = enrichChargingDataWithAI($cat['name']);

        if (
            is_array($power) &&
            ($power['wired_max_w'] ?? null) !== null
        ) {
            $cache[$modelKey] = [
                'power'      => $power,
                'fetched_at' => date('c')
            ];

            $enriched++;

            $enrichedModels[] = [
                'id'          => $cat['id'],
                'name'        => $cat['name'],
                'wired_max_w' => $power['wired_max_w'],
                'source'      => $power['source'] ?? null,
            ];

        } else {
            // Säilytetään tieto epäonnistumisesta,
            // mutta seuraava /enrich-all saa yrittää mallia uudelleen.
            $previousAttempts = (int) ($cached['attempts'] ?? 0);

            $cache[$modelKey] = [
                'power'        => null,
                'fetched_at'   => date('c'),
                'failed'       => true,
                'attempts'     => $previousAttempts + 1,
            ];

            $failed++;

            $failedModels[] = [
                'id'       => $cat['id'],
                'name'     => $cat['name'],
                'attempts' => $previousAttempts + 1,
            ];
        }
    }

    $saved = saveChargerCache($cache);

    echo json_encode([
        'status'          => $remaining > 0 ? 'in_progress' : 'done',
        'cache_saved'     => $saved,
        'processed'       => $processed,
        'enriched'        => $enriched,
        'failed'          => $failed,
        'enriched_models' => $enrichedModels,
        'failed_models'   => $failedModels,
        'skipped'         => $skipped,
        'remaining'       => $remaining,
        'message'         => $remaining > 0
            ? 'Kutsu /enrich-all uudelleen'
            : 'Kaikki puuttuvat mallit käsitelty. Jos failed > 0, kutsu /enrich-all uudelleen.',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// /upload-cache
// ─────────────────────────────────────────────────────────────────────────────

if ($path === '/upload-cache') {
    header('Content-Type: text/html; charset=utf-8');

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_FILES['cache'])
    ) {
        $data = file_get_contents($_FILES['cache']['tmp_name']);
        $parsed = json_decode($data, true);

        if (!is_array($parsed)) {
            echo '<p style="color:red">Virheellinen JSON-tiedosto</p>';
        } else {
            $saved = saveChargerCache($parsed);

            if ($saved) {
                echo '<p style="color:green">✓ Ladattu '
                    . count($parsed)
                    . ' mallia onnistuneesti!</p>';
            } else {
                echo '<p style="color:red">Välimuistin tallennus epäonnistui.</p>';
            }
        }
    }

    echo '<form method="post" enctype="multipart/form-data">
        <p>Valitse charger_cache.json tiedosto:</p>
        <input type="file" name="cache" accept=".json">
        <button type="submit">Lähetä</button>
    </form>';

    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Mallilista
// ─────────────────────────────────────────────────────────────────────────────

$chargerCache = loadChargerCache();

$models = fetchAllModels(
    $apiUrl,
    $user,
    $key,
    $ALLOWED_ROOT_IDS
);

$all = [];

foreach ($models as $m) {
    $cat           = $m['cat'];
    $brand         = $m['brand'];
    $matchedRootId = $m['matchedRootId'];
    $template      = $m['template'];

    $modelKey = 'model_' . $cat['id'];
    $cached   = $chargerCache[$modelKey] ?? null;

    $power = (
        $cached &&
        isset($cached['power']) &&
        is_array($cached['power'])
    )
        ? $cached['power']
        : null;

    if (
        $power &&
        isset($power['wired_max_w']) &&
        $power['wired_max_w'] !== null
    ) {
        $power['recommended_min_watts'] =
            getRecommendedMinWatts($power['wired_max_w']);
    }

    $all[] = [
        'id'        => $cat['id'],
        'name'      => $cat['name'],
        'url'       => '/category/' . $cat['id'],
        'parent_id' => $cat['parent_id'],
        'root_id'   => $matchedRootId,
        'brand_id'  => $brand['id'],
        'brand_name'=> $brand['name'],
        'template'  => $template,

        'charging' => [
            'usb_c'     => str_contains($template, 'usb-c'),
            'lightning' => str_contains($template, 'lightning'),
            'micro_usb' => str_contains($template, 'micro-usb'),
            'magsafe'   => str_contains($template, 'magsafe'),
        ],

        'power'            => $power,
        'power_source'     => $power ? 'charger_cache' : null,
        'power_fetched_at' => $cached['fetched_at'] ?? null,

        // Näkyy /models-vastauksessa vain debuggausta varten.
        'power_failed'   => (bool) ($cached['failed'] ?? false),
        'power_attempts' => (int) ($cached['attempts'] ?? 0),
    ];
}

usort($all, function ($a, $b) {
    $brandCompare = strcasecmp(
        $a['brand_name'],
        $b['brand_name']
    );

    return $brandCompare !== 0
        ? $brandCompare
        : strcasecmp($a['name'], $b['name']);
});

$missingPower = count(
    array_filter(
        $all,
        fn($m) => $m['power'] === null
    )
);


// ─────────────────────────────────────────────────────────────────────────────
// /models
// ─────────────────────────────────────────────────────────────────────────────

if ($path === '/models') {
    echo json_encode(
        $all,
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    exit;
}


// ─────────────────────────────────────────────────────────────────────────────
// Etusivu / status
// ─────────────────────────────────────────────────────────────────────────────

echo json_encode([
    'status'        => 'ok',
    'count'         => count($all),
    'missing_power' => $missingPower,
    'enrich_url'    => '/enrich-all',
    'models_url'    => '/models',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
