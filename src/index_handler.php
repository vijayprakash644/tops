<?php
declare(strict_types=1);

function handle_index_request(): void
{
    ensure_tokyo_timezone();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_error_response('Only GET is allowed');
        return;
    }

    log_event(
        'incoming_get',
        'Received dialer callback',
        [
            'client' => client_context(),
            'query' => $_GET,
        ]
    );

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
            send_error_response('Missing required fields: unique_id, userId, dialledPhone');
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
            send_error_response($validation['error']);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';
    } else {
        if ($callId === '') {
            send_error_response('Missing required fields: unique_id');
            return;
        }

        $callTime = get_param($_GET, 'callTime', $now);
        $errorInfo1 = pick_error_info($dispositionCode, $systemDisposition);

        $payload = build_not_answer_payload($callId, $callTime, $errorInfo1);

        $validation = validate_not_answer($payload);
        if (!$validation['ok']) {
            send_error_response($validation['error']);
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
        send_error_response('Server configuration missing');
        return;
    }

    $url = rtrim($baseUrl, '/') . $endpointPath;
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        send_error_response('Failed to encode payload');
        return;
    }

    log_event(
        'upstream_request',
        'Prepared upstream request',
        [
            'env' => $envPrefix,
            'url' => $url,
            'payload' => $payload,
        ]
    );

    $enableSend = strtolower((string) env('ENABLE_REAL_SEND', 'false')) === 'true';
    log_event(
        'payload_prepared',
        $enableSend ? 'Sending enabled' : 'Sending disabled (log only)',
        [
            'env' => $envPrefix,
            'url' => $url,
            'send_enabled' => $enableSend,
        ]
    );

    if (!$enableSend) {
        send_success_response('Upstream send disabled; payload prepared and logged.');
        return;
    }

    $post = post_form_json($url, $apiKey, $jsonPayload);
    log_event(
        'upstream_response',
        $post['ok'] ? 'Upstream response received' : 'Upstream request failed',
        [
            'env' => $envPrefix,
            'ok' => $post['ok'],
            'http_code' => $post['http_code'],
            'body' => $post['body'],
            'error' => $post['error'],
        ]
    );

    if (!$post['ok']) {
        send_error_response('Upstream request failed');
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

function ensure_tokyo_timezone(): void
{
    date_default_timezone_set('Asia/Tokyo');
}

function request_id(): string
{
    static $id = '';
    if ($id === '') {
        $id = bin2hex(random_bytes(8));
    }
    return $id;
}

function client_context(): array
{
    return [
        'request_id' => request_id(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
    ];
}

function send_success_response(string $message, array $extra = []): void
{
    send_json(array_merge([
        'result' => 'success',
        'message' => $message,
        'request_id' => request_id(),
    ], $extra));
}

function send_error_response(string $message): void
{
    send_json([
        'result' => 'fail',
        'message' => $message,
        'request_id' => request_id(),
    ]);
}

function log_event(string $label, string $message, array $data = []): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $time = date('Y-m-d H:i:s');
    $requestId = request_id();
    $event = $label;
    $status = isset($data['ok']) ? ($data['ok'] ? 'success' : 'fail') : '';
    $url = isset($data['url']) ? (string) $data['url'] : '';
    $payload = isset($data['payload']) ? $data['payload'] : null;

    $payloadLine = '';
    if ($payload !== null) {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $payloadLine = $payloadJson === false ? '' : $payloadJson;
    }

    $parts = [
        $time,
        '[' . $requestId . ']',
        $event,
        $message,
    ];
    if ($status !== '') {
        $parts[] = 'status=' . $status;
    }
    if ($url !== '') {
        $parts[] = 'url=' . $url;
    }
    if ($payloadLine !== '') {
        $parts[] = 'payload=' . $payloadLine;
    }

    $line = implode(' | ', $parts);
    file_put_contents($logDir . DIRECTORY_SEPARATOR . 'index.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
