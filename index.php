<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';

/**
 * ------------------------------------------------------------
 * Logging
 * ------------------------------------------------------------
 */
function log_event(string $label, array $data): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $entry = [
        'time'  => date('Y-m-d H:i:s'),
        'label' => $label,
        'data'  => $data,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    file_put_contents($logDir . DIRECTORY_SEPARATOR . 'index.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * ------------------------------------------------------------
 * State store (to keep phone1 status when only log2 arrives)
 * ------------------------------------------------------------
 */
function state_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'state';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function state_path(string $callId): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $callId);
    return state_dir() . DIRECTORY_SEPARATOR . $safe . '.json';
}

function load_state(string $callId): array
{
    $p = state_path($callId);
    if (!is_file($p)) {
        return [];
    }
    $raw = file_get_contents($p);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : [];
}

function save_state(string $callId, array $state): void
{
    $state['updatedAt'] = date('Y-m-d H:i:s');
    file_put_contents(state_path($callId), json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function clear_state(string $callId): void
{
    $p = state_path($callId);
    if (is_file($p)) {
        @unlink($p);
    }
}

function parse_phone_list(array $q): array
{
    // phoneList={"phoneList":["p1","p2"]}
    if (isset($q['phoneList']) && trim((string)$q['phoneList']) !== '') {
        $decoded = json_decode((string)$q['phoneList'], true);
        if (is_array($decoded) && isset($decoded['phoneList']) && is_array($decoded['phoneList'])) {
            $phones = array_values(array_filter(array_map('strval', $decoded['phoneList'])));
            $phones = array_values(array_unique($phones));
            return $phones;
        }
    }

    // fallback: explicit fields if present
    $phones = [];
    foreach (['phone1', 'phone2', 'cstmPhone'] as $k) {
        if (isset($q[$k]) && trim((string)$q[$k]) !== '') {
            $phones[] = trim((string)$q[$k]);
        }
    }

    // last fallback: dialled/dst
    foreach (['dialledPhone', 'dstPhone'] as $k) {
        if (isset($q[$k]) && trim((string)$q[$k]) !== '') {
            $phones[] = trim((string)$q[$k]);
        }
    }

    $phones = array_values(array_unique(array_filter($phones)));
    return $phones;
}

function pick_status_now(string $systemDisposition, string $dispositionCode): string
{
    $s = trim($systemDisposition);
    if ($s !== '') return $s;

    $d = trim($dispositionCode);
    if ($d !== '') return $d;

    return 'UNKNOWN';
}

function is_connected_from_get(array $q): bool
{
    $callConnectedTime = isset($q['callConnectedTime']) ? trim((string)$q['callConnectedTime']) : '';
    if ($callConnectedTime !== '') return true;

    $callResult = isset($q['callResult']) ? strtoupper(trim((string)$q['callResult'])) : '';
    if ($callResult === 'SUCCESS') return true;

    return false;
}

/**
 * ------------------------------------------------------------
 * Request gate
 * ------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Only GET is allowed', 200);
    return;
}

log_event('incoming_get', $_GET);

/**
 * ------------------------------------------------------------
 * Read inputs
 * ------------------------------------------------------------
 */
$callId = isset($_GET['unique_id']) ? trim((string)$_GET['unique_id']) : '';
$predictiveStaffId = isset($_GET['userId']) ? trim((string)$_GET['userId']) : '';

$targetTel = '';
if (isset($_GET['dialledPhone'])) {
    $targetTel = trim((string)$_GET['dialledPhone']);
} elseif (isset($_GET['dstPhone'])) {
    $targetTel = trim((string)$_GET['dstPhone']);
}

$systemDisposition = isset($_GET['systemDisposition']) ? trim((string)$_GET['systemDisposition']) : '';
$dispositionCode = isset($_GET['dispositionCode']) ? trim((string)$_GET['dispositionCode']) : '';

$phones = parse_phone_list($_GET); // [phone1, phone2] if available
$dialIndex = isset($_GET['shareablePhonesDialIndex']) ? (int)$_GET['shareablePhonesDialIndex'] : 0;
$numAttempts = isset($_GET['numAttempts']) ? (int)$_GET['numAttempts'] : 1;

$isConnected = is_connected_from_get($_GET);
$statusNow = pick_status_now($systemDisposition, $dispositionCode);

$baseUrl = env('TEST_BASE_URL');
$apiKey  = env('TEST_API_KEY');

if ($baseUrl === null || $apiKey === null || $baseUrl === '' || $apiKey === '') {
    send_error('Server configuration missing', 200);
    return;
}

$now = date('Y-m-d H:i:s');

/**
 * ------------------------------------------------------------
 * Persist attempt result into state (so log2 can include phone1 status)
 * ------------------------------------------------------------
 */
if ($callId !== '') {
    $state = load_state($callId);

    if (!isset($state['phones']) && !empty($phones)) {
        $state['phones'] = $phones;
    } elseif (isset($state['phones']) && is_array($state['phones']) && empty($phones)) {
        // keep existing
    } elseif (!isset($state['phones'])) {
        $state['phones'] = $phones;
    }

    if (!isset($state['statusByIndex']) || !is_array($state['statusByIndex'])) {
        $state['statusByIndex'] = [];
    }
    if (!isset($state['connectedByIndex']) || !is_array($state['connectedByIndex'])) {
        $state['connectedByIndex'] = [];
    }

    // Store current attempt status + connection by dialIndex
    $state['statusByIndex'][(string)$dialIndex] = $isConnected ? 'CONNECTED' : $statusNow;
    $state['connectedByIndex'][(string)$dialIndex] = $isConnected;

    save_state($callId, $state);
}

/**
 * ------------------------------------------------------------
 * Build “both phone statuses if available”
 *
 * Conditions requested:
 *  1) phone1 connected -> send CallEnd for phone1 only (no phone2 info needed)
 *  2) phone2 connected -> send CallEnd and include phone1 failure status in errorInfo
 *  3) both not connected -> send NotAnswer:
 *       - only phone1 tried => errorInfo1 only
 *       - phone2 tried too  => errorInfo1 + errorInfo2
 * ------------------------------------------------------------
 */
if ($callId === '') {
    send_error('Missing required fields: unique_id', 200);
    return;
}

$state = load_state($callId);
$statePhones = (isset($state['phones']) && is_array($state['phones'])) ? $state['phones'] : $phones;
$statusByIndex = (isset($state['statusByIndex']) && is_array($state['statusByIndex'])) ? $state['statusByIndex'] : [];
$connectedByIndex = (isset($state['connectedByIndex']) && is_array($state['connectedByIndex'])) ? $state['connectedByIndex'] : [];

$phoneCount = is_array($statePhones) ? count($statePhones) : 0;
$hasPhone2 = ($phoneCount >= 2);

$phone1Connected = !empty($connectedByIndex['0']);
$phone2Connected = !empty($connectedByIndex['1']);

// normalized statuses
$phone1Status = $statusByIndex['0'] ?? '';
$phone2Status = $statusByIndex['1'] ?? '';

// If we have only one phone in list, treat everything as phone1.
if (!$hasPhone2) {
    $phone2Connected = false;
    $phone2Status = '';
}

// Decide endpoint by current connection, but also enforce the “phone1 connected means stop” rule.
$endpointPath = '';
$payload = [];

if ($phone1Connected && $dialIndex === 0) {
    // (1) phone1 connected -> CallEnd; do not send anything about phone2
    if ($predictiveStaffId === '' || $targetTel === '') {
        send_error('Missing required fields for CallEnd: userId, dialledPhone/dstPhone', 200);
        return;
    }

    $callStartTime = isset($_GET['callStartTime']) ? trim((string)$_GET['callStartTime']) : $now;
    $callEndTime   = isset($_GET['callEndTime']) ? trim((string)$_GET['callEndTime']) : $now;
    $subCtiHistoryId = isset($_GET['subCtiHistoryId']) ? trim((string)$_GET['subCtiHistoryId']) : (string)$callId;

    $payload = [
        'predictiveCallCreateCallEnd' => [
            'callId'            => $callId,
            'callStartTime'     => $callStartTime,
            'callEndTime'       => $callEndTime,
            'subCtiHistoryId'   => $subCtiHistoryId,
            'targetTel'         => $targetTel,
            'predictiveStaffId' => $predictiveStaffId,
            // no errorInfo needed
        ],
    ];

    $validation = validate_call_end($payload);
    if (!$validation['ok']) {
        send_error($validation['error'], 200);
        return;
    }

    $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';

    // Optional: clear state because call finished successfully on phone1
    clear_state($callId);

} elseif ($phone2Connected && $dialIndex >= 1) {
    // (2) phone2 connected -> CallEnd; include phone1 status if phone1 failed/not connected
    if ($predictiveStaffId === '' || $targetTel === '') {
        send_error('Missing required fields for CallEnd: userId, dialledPhone/dstPhone', 200);
        return;
    }

    $callStartTime = isset($_GET['callStartTime']) ? trim((string)$_GET['callStartTime']) : $now;
    $callEndTime   = isset($_GET['callEndTime']) ? trim((string)$_GET['callEndTime']) : $now;
    $subCtiHistoryId = isset($_GET['subCtiHistoryId']) ? trim((string)$_GET['subCtiHistoryId']) : (string)$callId;

    $includeErrorInfo = (!$phone1Connected && $phone1Status !== '' && strtoupper($phone1Status) !== 'CONNECTED');

    $payload = [
        'predictiveCallCreateCallEnd' => array_filter([
            'callId'            => $callId,
            'callStartTime'     => $callStartTime,
            'callEndTime'       => $callEndTime,
            'subCtiHistoryId'   => $subCtiHistoryId,
            'targetTel'         => $targetTel,
            'predictiveStaffId' => $predictiveStaffId,
            // per spec, errorInfo is used when 2nd succeeded and 1st failed
            'errorInfo'         => $includeErrorInfo ? $phone1Status : null,
        ], fn($v) => $v !== null && $v !== ''),
    ];

    $validation = validate_call_end($payload);
    if (!$validation['ok']) {
        send_error($validation['error'], 200);
        return;
    }

    $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';

    // Optional: clear state because call flow reached a connected end
    clear_state($callId);

} else {
    // (3) Not connected -> createNotAnswer
    $callTime = isset($_GET['callTime']) ? trim((string)$_GET['callTime']) : $now;

    // Determine whether we should send both statuses:
    // - If dialIndex >= 1 or numAttempts >= 2, we assume phone2 was attempted (or is being attempted).
    $sendTwo = $hasPhone2 && ($dialIndex >= 1 || $numAttempts >= 2);

    $errorInfo1 = $phone1Status !== '' ? $phone1Status : ($dialIndex === 0 ? $statusNow : 'UNKNOWN');
    $errorInfo2 = '';

    if ($sendTwo) {
        // For phone2, prefer stored status, otherwise current status if current dialIndex==1
        if ($phone2Status !== '') {
            $errorInfo2 = $phone2Status;
        } elseif ($dialIndex >= 1) {
            $errorInfo2 = $statusNow;
        } else {
            $errorInfo2 = 'UNKNOWN';
        }
    }

    // If phone1 connected, we should never be in NotAnswer (safety)
    if ($phone1Connected) {
        send_error('Inconsistent state: phone1 already connected; NotAnswer not allowed', 200);
        return;
    }

    $payload = [
        'predictiveCallCreateNotAnswer' => array_filter([
            'callId'     => $callId,
            'callTime'   => $callTime,
            'errorInfo1' => $errorInfo1,
            'errorInfo2' => ($sendTwo && $errorInfo2 !== '') ? $errorInfo2 : null,
        ], fn($v) => $v !== null && $v !== ''),
    ];

    $validation = validate_not_answer($payload);
    if (!$validation['ok']) {
        send_error($validation['error'], 200);
        return;
    }

    $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json';
}

/**
 * ------------------------------------------------------------
 * Send / log
 * ------------------------------------------------------------
 */
$url = rtrim($baseUrl, '/') . $endpointPath;
$jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
if ($jsonPayload === false) {
    send_error('Failed to encode payload', 200);
    return;
}

log_event('upstream_request', [
    'url'     => $url,
    'payload' => $payload,
]);

$enableSend = strtolower((string) env('ENABLE_REAL_SEND', 'false')) === 'true';

log_event('payload_prepared', [
    'url'          => $url,
    'send_enabled' => $enableSend,
    'dialIndex'    => $dialIndex,
    'numAttempts'  => $numAttempts,
    'isConnected'  => $isConnected,
]);

if (!$enableSend) {
    send_json([
        'result'  => 'success',
        'message' => 'Upstream send disabled; payload prepared and logged.',
        'debug'   => [
            'callId'      => $callId,
            'dialIndex'   => $dialIndex,
            'numAttempts' => $numAttempts,
            'phones'      => $statePhones,
        ],
    ]);
    return;
}

// Uncomment to really send
// $post = post_form_json($url, $apiKey, $jsonPayload);
// log_event('upstream_response', [
//     'ok'        => $post['ok'],
//     'http_code' => $post['http_code'],
//     'body'      => $post['body'],
//     'error'     => $post['error'],
// ]);
// if (!$post['ok']) {
//     send_error('Upstream request failed', 200);
//     return;
// }
// http_response_code(200);
// header('Content-Type: application/json; charset=utf-8');
// echo $post['body'];
