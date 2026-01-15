<?php
// HTTP client helper using cURL.

declare(strict_types=1);

function post_form_json(string $url, string $apiKey, string $jsonPayload): array
{
    $ch = curl_init();
    if ($ch === false) {
        return [
            'ok' => false,
            'http_code' => 0,
            'body' => null,
            'error' => 'Failed to initialize cURL',
        ];
    }

    $fields = http_build_query(['jsonData' => $jsonPayload]);

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'X-FastHelp-API-Key: ' . $apiKey,
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'body' => null,
            'error' => $err === '' ? 'Unknown cURL error' : $err,
        ];
    }

    return [
        'ok' => true,
        'http_code' => $httpCode,
        'body' => $body,
        'error' => null,
    ];
}