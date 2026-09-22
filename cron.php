<?php

declare(strict_types=1);

$url = 'https://suojaapuhelin-phone-model-api-production.up.railway.app/enrich-all';

// Suojaa siltä, ettei mahdollinen virhetilanne aja loputtomasti.
// 20 kierrosta x 5 mallia = enintään 100 puuttuvaa mallia yhdellä cron-ajolla.
$maxRuns = 20;

echo "LaturiApuri cron käynnistyi: " . date('c') . PHP_EOL;

for ($i = 1; $i <= $maxRuns; $i++) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 120,
            'header' => [
                'Accept: application/json',
                'User-Agent: LaturiApuri-Cron/1.0',
            ],
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        echo "Virhe: /enrich-all ei vastannut." . PHP_EOL;
        exit(1);
    }

    $httpCode = null;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                $httpCode = (int) $matches[1];
                break;
            }
        }
    }

    if ($httpCode !== null && ($httpCode < 200 || $httpCode >= 300)) {
        echo "Virhe: /enrich-all palautti HTTP-koodin {$httpCode}." . PHP_EOL;
        echo "Vastaus: {$response}" . PHP_EOL;
        exit(1);
    }

    $data = json_decode($response, true);

    if (!is_array($data)) {
        echo "Virhe: /enrich-all palautti virheellisen JSON-vastauksen." . PHP_EOL;
        echo "Vastaus: {$response}" . PHP_EOL;
        exit(1);
    }

    $status    = $data['status'] ?? 'unknown';
    $processed = $data['processed'] ?? '?';
    $enriched  = $data['enriched'] ?? '?';
    $failed    = $data['failed'] ?? '?';
    $remaining = $data['remaining'] ?? '?';

    echo "Ajo {$i}: "
        . "status={$status}, "
        . "processed={$processed}, "
        . "enriched={$enriched}, "
        . "failed={$failed}, "
        . "remaining={$remaining}"
        . PHP_EOL;

    if (!empty($data['failed_models']) && is_array($data['failed_models'])) {
        foreach ($data['failed_models'] as $failedModel) {
            $id = $failedModel['id'] ?? '?';
            $name = $failedModel['name'] ?? 'Tuntematon malli';
            $attempts = $failedModel['attempts'] ?? '?';

            echo "  Epäonnistui: {$id} / {$name} / attempts={$attempts}" . PHP_EOL;
        }
    }

    // Kun mitään ei ole enää jonossa, cron on valmis.
    if (is_numeric($remaining) && (int) $remaining <= 0) {
        if (is_numeric($failed) && (int) $failed > 0) {
            echo "Ajo valmistui, mutta {$failed} mallin rikastus epäonnistui tällä kierroksella." . PHP_EOL;
            echo "Seuraava päivittäinen cron yrittää niitä uudelleen." . PHP_EOL;
        } else {
            echo "Kaikki puuttuvat mallit käsitelty onnistuneesti." . PHP_EOL;
        }

        echo "LaturiApuri cron valmis: " . date('c') . PHP_EOL;
        exit(0);
    }

    // Pieni tauko API-kutsujen väliin.
    sleep(3);
}

echo "Virhe: maksimimäärä {$maxRuns} ajoa saavutettiin, mutta malleja jäi vielä jonoon." . PHP_EOL;
exit(1);
