<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';

function log_event(string $label, array $data): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Only GET is allowed', 200);
    return;
}

log_event('incoming_get', $_GET);

$callId = isset($_GET['unique_id']) ? trim((string) $_GET['unique_id']) : '';
$predictiveStaffId = isset($_GET['userId']) ? trim((string) $_GET['userId']) : '';
$targetTel = '';
if (isset($_GET['dialledPhone'])) {
    $targetTel = trim((string) $_GET['dialledPhone']);
} elseif (isset($_GET['dstPhone'])) {
    $targetTel = trim((string) $_GET['dstPhone']);
}

$systemDisposition = isset($_GET['systemDisposition']) ? trim((string) $_GET['systemDisposition']) : '';
$dispositionCode = isset($_GET['dispositionCode']) ? trim((string) $_GET['dispositionCode']) : '';

$isConnected = strtoupper($systemDisposition) === 'CONNECTED';

$baseUrl = env('PROD_BASE_URL');
$apiKey = env('PROD_API_KEY');

if ($baseUrl === null || $apiKey === null || $baseUrl === '' || $apiKey === '') {
    send_error('Server configuration missing', 200);
    return;
}

$now = date('Y-m-d H:i:s');

if ($isConnected) {
    if ($callId === '' || $predictiveStaffId === '' || $targetTel === '') {
        send_error('Missing required fields: unique_id, userId, dialledPhone', 200);
        return;
    }

    $callStartTime = isset($_GET['callStartTime']) ? trim((string) $_GET['callStartTime']) : $now;
    $callEndTime = isset($_GET['callEndTime']) ? trim((string) $_GET['callEndTime']) : $now;
    $subCtiHistoryId = isset($_GET['subCtiHistoryId']) ? trim((string) $_GET['subCtiHistoryId']) : (string) $callId;

    $payload = [
        'predictiveCallCreateCallEnd' => [
            'callId' => $callId,
            'callStartTime' => $callStartTime,
            'callEndTime' => $callEndTime,
            'subCtiHistoryId' => $subCtiHistoryId,
            'targetTel' => $targetTel,
            'predictiveStaffId' => $predictiveStaffId,
        ],
    ];

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

    $callTime = isset($_GET['callTime']) ? trim((string) $_GET['callTime']) : $now;

    $payload = [
        'predictiveCallCreateNotAnswer' => [
            'callId' => $callId,
            'callTime' => $callTime,
            'errorInfo1' => $dispositionCode !== '' ? $dispositionCode : $systemDisposition,
        ],
    ];

    $validation = validate_not_answer($payload);
    if (!$validation['ok']) {
        send_error($validation['error'], 200);
        return;
    }

    $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json';
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
