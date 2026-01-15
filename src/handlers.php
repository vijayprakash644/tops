<?php
// Common handler for API relay endpoints.

declare(strict_types=1);

function handle_predictive_request(string $envPrefix, string $endpointPath, callable $validator): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_error('Only POST is allowed', 200);
        return;
    }

    $baseUrl = env($envPrefix . '_BASE_URL');
    $apiKey = env($envPrefix . '_API_KEY');

    if ($baseUrl === null || $apiKey === null || $baseUrl === '' || $apiKey === '') {
        send_error('Server configuration missing', 200);
        return;
    }

    $payloadResult = read_json_payload();
    if (!$payloadResult['ok']) {
        send_error($payloadResult['error'], 200);
        return;
    }

    $payload = $payloadResult['data'];
    $validation = $validator($payload);
    if (!$validation['ok']) {
        send_error($validation['error'], 200);
        return;
    }

    $url = rtrim($baseUrl, '/') . $endpointPath;
    $post = post_form_json($url, $apiKey, $payloadResult['raw']);

    if (!$post['ok']) {
        send_error('Upstream request failed', 200);
        return;
    }

    // Always return upstream body and 200 (as per upstream spec).
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo $post['body'];
}