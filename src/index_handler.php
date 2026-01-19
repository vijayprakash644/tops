<?php
declare(strict_types=1);

function handle_index_request(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_error('Only GET is allowed', 200);
        return;
    }

    log_event('incoming_get', $_GET);

    $callId = get_param($_GET, 'unique_id');
    $predictiveStaffId = get_param($_GET, 'userId');
    $targetTel = get_param($_GET, 'dialledPhone');
    if ($targetTel === '') {
        $targetTel = get_param($_GET, 'dstPhone');
    }

    $systemDisposition = get_param($_GET, 'systemDisposition');
    $dispositionCode = get_param($_GET, 'dispositionCode');

    $isConnected = strtoupper($systemDisposition) === 'CONNECTED';
    $now = date('Y-m-d H:i:s');

    if ($isConnected) {
        if ($callId === '' || $predictiveStaffId === '' || $targetTel === '') {
            send_error('Missing required fields: unique_id, userId, dialledPhone', 200);
            return;
        }

        $callStartTime = get_param($_GET, 'callStartTime', $now);
        $callEndTime = get_param($_GET, 'callEndTime', $now);
        $subCtiHistoryId = get_param($_GET, 'subCtiHistoryId', $callId);

        $payload = build_call_end_payload(
            $callId,
            $callStartTime,
            $callEndTime,
            $subCtiHistoryId,
            $targetTel,
            $predictiveStaffId
        );

        $validation = validate_call_end($payload);
        if (!$validation['ok']) {
            send_error($validation['error'], 200);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';
    } else {
        if ($callId === '') {
            send_error('Missing required fields: unique_id', 200);
            return;
        }

        $callTime = get_param($_GET, 'callTime', $now);
        $errorInfo1 = pick_error_info($dispositionCode, $systemDisposition);

        $payload = build_not_answer_payload($callId, $callTime, $errorInfo1);

        $validation = validate_not_answer($payload);
        if (!$validation['ok']) {
            send_error($validation['error'], 200);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json';
    }

    send_or_log_request($endpointPath, $payload);
}

function get_param(array $query, string $key, string $default = ''): string
{
    if (!isset($query[$key])) {
        return $default;
    }

    $value = trim((string) $query[$key]);
    if ($value === '') {
        return $default;
    }

    return $value;
}

function pick_error_info(string $dispositionCode, string $systemDisposition): string
{
    if ($dispositionCode !== '') {
        return $dispositionCode;
    }
    if ($systemDisposition !== '') {
        return $systemDisposition;
    }
    return 'UNKNOWN';
}

function build_call_end_payload(
    string $callId,
    string $callStartTime,
    string $callEndTime,
    string $subCtiHistoryId,
    string $targetTel,
    string $predictiveStaffId
): array {
    return [
        'predictiveCallCreateCallEnd' => [
            'callId' => $callId,
            'callStartTime' => $callStartTime,
            'callEndTime' => $callEndTime,
            'subCtiHistoryId' => $subCtiHistoryId,
            'targetTel' => $targetTel,
            'predictiveStaffId' => $predictiveStaffId,
        ],
    ];
}

function build_not_answer_payload(string $callId, string $callTime, string $errorInfo1): array
{
    return [
        'predictiveCallCreateNotAnswer' => [
            'callId' => $callId,
            'callTime' => $callTime,
            'errorInfo1' => $errorInfo1,
        ],
    ];
}

function send_or_log_request(string $endpointPath, array $payload): void
{
    $envPrefix = normalize_env_prefix(env('INDEX_ENV', 'TEST'));
    $baseUrl = env($envPrefix . '_BASE_URL');
    $apiKey = env($envPrefix . '_API_KEY');

    if ($baseUrl === null || $apiKey === null || $baseUrl === '' || $apiKey === '') {
        send_error('Server configuration missing', 200);
        return;
    }

    $url = rtrim($baseUrl, '/') . $endpointPath;
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        send_error('Failed to encode payload', 200);
        return;
    }

    log_event('upstream_request', [
        'url' => $url,
        'payload' => $payload,
    ]);

    $enableSend = strtolower((string) env('ENABLE_REAL_SEND', 'false')) === 'true';
    log_event('payload_prepared', [
        'url' => $url,
        'send_enabled' => $enableSend,
        'env' => $envPrefix,
    ]);

    if (!$enableSend) {
        send_json([
            'result' => 'success',
            'message' => 'Upstream send disabled; payload prepared and logged.',
        ]);
        return;
    }

    $post = post_form_json($url, $apiKey, $jsonPayload);
    log_event('upstream_response', [
        'ok' => $post['ok'],
        'http_code' => $post['http_code'],
        'body' => $post['body'],
        'error' => $post['error'],
    ]);

    if (!$post['ok']) {
        send_error('Upstream request failed', 200);
        return;
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo $post['body'];
}

function normalize_env_prefix(?string $value): string
{
    $upper = strtoupper(trim((string) $value));
    return $upper === 'PROD' ? 'PROD' : 'TEST';
}

function log_event(string $label, array $data): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $entry = [
        'time' => date('Y-m-d H:i:s'),
        'label' => $label,
        'data' => $data,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    file_put_contents($logDir . DIRECTORY_SEPARATOR . 'index.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
