<?php
declare(strict_types=1);

/**
 * index.php
 *
 * Adds “events/conditions Efor:
 * - single phone
 * - two phones
 * - phone1 connected -> CallEnd only (stop; no phone2)
 * - phone2 connected -> CallEnd + include phone1 failure in errorInfo
 * - both not connected -> NotAnswer
 *   - if only phone1 attempted -> errorInfo1 only
 *   - if phone2 attempted -> errorInfo1 + errorInfo2
 *
 * Uses state file per unique_id to remember phone1 status when only later callback arrives.
 *
 * NOTE: createNotAnswer supports errorInfo2; createCallEnd supports errorInfo (1st phone error if 2nd connected).
 */

function handle_index_request(): void
{
    ensure_tokyo_timezone();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_error_response('Only GET is allowed');
        return;
    }

    $incomingQuery = $_GET;
    log_event('timing', 'Before dummy response', [
        'phase' => 'before_dummy_response',
        'ts' => sprintf('%.6f', microtime(true)),
    ]);
    send_dummy_response_and_continue();
    log_event('timing', 'After dummy response', [
        'phase' => 'after_dummy_response',
        'ts' => sprintf('%.6f', microtime(true)),
    ]);
    // sleep(1); // slight delay to ensure response sent before continuing

    $callId = get_param($_GET, 'unique_id');
    $customerId = to_int(get_param($_GET, 'customerId'), 0);
    $crtObjectId = get_param($_GET, 'crtObjectId');
    if ($crtObjectId === '') {
        $crtObjectId = get_param($_GET, 'customerCRTId');
    }
    if ($callId === '') {
        send_error_response('Missing required fields: unique_id');
        return;
    }

    $predictiveStaffId = get_param($_GET, 'userId');
    $cstmPhone = get_param($_GET, 'cstmPhone');

    $targetTel = get_param($_GET, 'dialledPhone');
    if ($targetTel === '') {
        $targetTel = get_param($_GET, 'dstPhone');
    }

    $systemDisposition = get_param($_GET, 'systemDisposition');
    $dispositionCode = get_param($_GET, 'dispositionCode');

    // Dialing meta
    $phones = parse_phone_list($_GET); // from phoneList JSON if present
    $dialIndex = to_int(get_param($_GET, 'shareablePhonesDialIndex', '0'), 0);
    $numAttempts = to_int(get_param($_GET, 'numAttempts', '1'), 1);
    if ($cstmPhone !== '' && ($dialIndex >= 1 || $targetTel === '')) {
        $targetTel = $cstmPhone;
    }

    // Connection detection
    $isConnected = is_connected_from_get($_GET, $systemDisposition);

    // Determine status for this attempt
    $statusNow = pick_error_info($dispositionCode, $systemDisposition);
    $now = date('Y-m-d H:i:s');

    // Very simple phone count from current request only
    $hasPhone2 = count($phones) >= 2;

    // With the current spec:
    // - API is called when phone2 dialing is finished, or
    // - single-phone scenario.
    // We no longer persist any per-call state.

    // -----------------------------
    // EVENT / CONDITION ROUTING
    // -----------------------------
    // Phone1 connected (single phone or when dialIndex=0)
    $phone1Connected = ($dialIndex === 0 && $isConnected);
    // Phone2 connected (only meaningful when 2 phones and dialIndex>=1)
    $phone2Connected = ($hasPhone2 && $dialIndex >= 1 && $isConnected);

    $shouldCallEndPhone1 = $phone1Connected;
    $shouldCallEndPhone2 = $phone2Connected;
    $shouldNotAnswer     = (!$isConnected);

    // Choose log channel
    if ($shouldCallEndPhone1 || $shouldCallEndPhone2) {
        set_log_channel('call_end');
    } elseif ($shouldNotAnswer) {
        set_log_channel('not_answer');
    } else {
        set_log_channel('general');
    }

    log_event(
        'incoming_get',
        'Received dialer callback',
        [
            'client' => client_context(),
            'query' => $incomingQuery,
        ]
    );

    $gate = request_gate_check($crtObjectId, $customerId, $callId);
    if (!$gate['ok']) {
        log_event('dedupe', 'Skipped duplicate request', [
            'reason' => $gate['reason'],
            'crtObjectId' => $crtObjectId,
            'customerId' => $customerId,
            'callId' => $callId,
        ]);
        return;
    }

    $payload = [];
    $endpointPath = '';

    if ($shouldCallEndPhone1) {
        // Single phone connected (or only phone1 used)
        if ($predictiveStaffId === '' || $targetTel === '') {
            send_error_response('Missing required fields: userId, dialledPhone/dstPhone');
            return;
        }

        $connectedRaw = get_param($_GET, 'callConnectedTime');   // e.g. 2026/01/20 11:16:41 +0900
        $connectedAt  = parse_ameyo_time($connectedRaw);

        $callStartTime = get_param($_GET, 'callStartTime', $connectedAt !== '' ? $connectedAt : $now);
        $callEndTime   = get_param($_GET, 'callEndTime', $now);
        $subCtiHistoryId = get_param($_GET, 'customerCRTId');
        if ($subCtiHistoryId === '') {
            send_error_response('Missing required fields: customerCRTId');
            return;
        }

        $payload = build_call_end_payload(
            $callId,
            $callStartTime,
            $callEndTime,
            $subCtiHistoryId,
            $targetTel,
            $predictiveStaffId,
            '' // no errorInfo when the only/first phone connected
        );

        $validation = validate_call_end($payload);
        if (!$validation['ok']) {
            send_error_response($validation['error']);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';

        log_event('decision', 'Phone1 connected -> createCallEnd (no phone2)', [
            'callId' => $callId,
            'dialIndex' => $dialIndex,
            'phones' => $phones,
        ]);

    } elseif ($shouldCallEndPhone2) {
        // 2nd phone connected -> include phone1 status in errorInfo (from DB)
        if ($predictiveStaffId === '' || $targetTel === '') {
            send_error_response('Missing required fields: userId, dialledPhone/dstPhone');
            return;
        }

        $connectedRaw = get_param($_GET, 'callConnectedTime');   // e.g. 2026/01/20 11:16:41 +0900
        $connectedAt  = parse_ameyo_time($connectedRaw);

        $callStartTime    = get_param($_GET, 'callStartTime', $connectedAt !== '' ? $connectedAt : $now);
        $callEndTime      = get_param($_GET, 'callEndTime', $now);
        $subCtiHistoryId  = get_param($_GET, 'customerCRTId');
        if ($subCtiHistoryId === '') {
            send_error_response('Missing required fields: customerCRTId');
            return;
        }

        // Resolve phone1 status from local state (set on phone1 callback)
        $phone1State = load_phone1_state(phone1_state_key($customerId, $callId));
        $errorInfo = isset($phone1State['phone1Status']) ? (string) $phone1State['phone1Status'] : '';

        $payload = build_call_end_payload(
            $callId,
            $callStartTime,
            $callEndTime,
            $subCtiHistoryId,
            $targetTel,
            $predictiveStaffId,
            $errorInfo
        );

        $validation = validate_call_end($payload);
        if (!$validation['ok']) {
            send_error_response($validation['error']);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';

        log_event('decision', 'Phone2 connected -> createCallEnd (with phone1 errorInfo from state if available)', [
            'callId' => $callId,
            'dialIndex' => $dialIndex,
            'resolvedPhone1' => $errorInfo,
            'phones' => $phones,
            'errorInfo' => $errorInfo,
        ]);

    } elseif ($shouldNotAnswer) {
        // Neither phone connected -> NotAnswer
        $callTime = get_param($_GET, 'callTime', $now);

        if ($hasPhone2 && $dialIndex === 0) {
            $phone1Status = $statusNow;
            $stateKey = phone1_state_key($customerId, $callId);
            save_phone1_state($stateKey, [
                'customerId' => $customerId,
                'callId' => $callId,
                'callTime' => $callTime,
                'phone1Status' => $phone1Status,
            ]);
            log_event('state', 'Stored phone1 status; waiting for phone2', [
                'customerId' => $customerId,
                'callId' => $callId,
                'callTime' => $callTime,
                'phone1Status' => $phone1Status,
            ]);
            request_gate_complete($gate['key'], ['ok' => true, 'status' => 'waiting_phone2']);
            return;
        }

        $phone1StateKey = '';
        $phone1State = [];
        if ($hasPhone2 && $dialIndex >= 1) {
            $phone1StateKey = phone1_state_key($customerId, $callId);
            $phone1State = load_phone1_state($phone1StateKey);
        }

        if (!$hasPhone2) {
            $errorInfo1 = $statusNow;
        } elseif (isset($phone1State['phone1Status']) && $phone1State['phone1Status'] !== '') {
            $errorInfo1 = (string) $phone1State['phone1Status'];
        } else {
            $errorInfo1 = 'UNKNOWN';
        }

        // Phone2 errorInfo only when there really is a second phone
        $errorInfo2 = '';
        if ($hasPhone2) {
            // for phone2, we just use current statusNow when dialIndex>=1
            $errorInfo2 = ($dialIndex >= 1) ? $statusNow : '';
        }

        $payload = build_not_answer_payload(
            $callId,
            $callTime,
            $errorInfo1,
            $hasPhone2 ? $errorInfo2 : ''
        );

        $validation = validate_not_answer($payload);
        if (!$validation['ok']) {
            send_error_response($validation['error']);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json';

        log_event(
            'decision',
            $hasPhone2 ? 'Not connected -> createNotAnswer (errorInfo1+2)' : 'Not connected -> createNotAnswer (errorInfo1 only)',
            [
                'callId' => $callId,
                'dialIndex' => $dialIndex,
                'numAttempts' => $numAttempts,
                'hasPhone2' => $hasPhone2,
                'errorInfo1' => $errorInfo1,
                'errorInfo2' => $hasPhone2 ? $errorInfo2 : '',
                'phones' => $phones,
                'phone1_state_used' => isset($phone1State['phone1Status']) && $phone1State['phone1Status'] !== '',
            ]
        );

    } else {
        send_error_response('Unable to determine action from parameters');
        return;
    }

    $sendResult = send_or_log_request($endpointPath, $payload);
    request_gate_complete($gate['key'], $sendResult);
    if ($hasPhone2 && $dialIndex >= 1) {
        $stateKeyToClear = phone1_state_key($customerId, $callId);
        clear_phone1_state($stateKeyToClear);
    }
}

/**
 * ----------------------------
 * Helpers
 * ----------------------------
 */

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

function to_int(string $value, int $default): int
{
    if ($value === '') return $default;
    if (!is_numeric($value)) return $default;
    return (int)$value;
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

function is_connected_from_get(array $query, string $systemDisposition): bool
{
    return strtoupper($systemDisposition) === 'CONNECTED';
}

function parse_phone_list(array $query): array
{
    $raw = get_param($query, 'phoneList');
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['phoneList']) && is_array($decoded['phoneList'])) {
            $phones = array_values(array_filter(array_map('strval', $decoded['phoneList'])));
            $phones = array_values(array_unique($phones));
            return $phones;
        }
    }

    // Fallback: build list from known params if they exist
    $phones = [];
    $p1 = get_param($query, 'phone1');
    $p2 = get_param($query, 'phone2');
    $cstm = get_param($query, 'cstmPhone');
    if ($p1 !== '') $phones[] = $p1;
    if ($p2 !== '') $phones[] = $p2;
    if ($cstm !== '') $phones[] = $cstm;

    // As last resort: use dialled/dst (single)
    $d = get_param($query, 'dialledPhone');
    if ($d === '') $d = get_param($query, 'dstPhone');
    if ($d !== '' && !in_array($d, $phones, true)) $phones[] = $d;

    return $phones;
}


function parse_ameyo_time(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') return '';

    // Example: "2026/01/20 11:16:41 +0900"
    $dt = DateTime::createFromFormat('Y/m/d H:i:s O', $raw);
    if ($dt instanceof DateTime) {
        $dt->setTimezone(new DateTimeZone('Asia/Tokyo'));
        return $dt->format('Y-m-d H:i:s');
    }

    // If already "Y-m-d H:i:s"
    $dt2 = DateTime::createFromFormat('Y-m-d H:i:s', $raw, new DateTimeZone('Asia/Tokyo'));
    if ($dt2 instanceof DateTime) {
        return $dt2->format('Y-m-d H:i:s');
    }

    return '';
}



/**
 * ----------------------------
 * Payload builders
 * ----------------------------
 */

function build_call_end_payload(
    string $callId,
    string $callStartTime,
    string $callEndTime,
    string $subCtiHistoryId,
    string $targetTel,
    string $predictiveStaffId,
    string $errorInfo = ''
): array {
    $body = [
        'callId' => $callId,
        'callStartTime' => $callStartTime,
        'callEndTime' => $callEndTime,
        'subCtiHistoryId' => $subCtiHistoryId,
        'targetTel' => $targetTel,
        'predictiveStaffId' => $predictiveStaffId,
    ];

    if (trim($errorInfo) !== '') {
        // Used when 2nd phone succeeded and 1st failed
        $body['errorInfo'] = trim($errorInfo);
    }

    return [
        'predictiveCallCreateCallEnd' => $body,
    ];
}

function build_not_answer_payload(string $callId, string $callTime, string $errorInfo1, string $errorInfo2 = ''): array
{
    $body = [
        'callId' => $callId,
        'callTime' => $callTime,
        'errorInfo1' => $errorInfo1,
    ];

    // Optional second phone status
    if (trim($errorInfo2) !== '') {
        $body['errorInfo2'] = trim($errorInfo2);
    }

    return [
        'predictiveCallCreateNotAnswer' => $body,
    ];
}

/**
 * ----------------------------
 * State store
 * ----------------------------
 */

function state_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'state';
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

/**
 * ----------------------------
 * Phone1 state store
 * ----------------------------
 */

function phone1_state_key(int $customerId, string $callId): string
{
    return sha1($customerId . '|' . $callId);
}

function phone1_state_path(string $key): string
{
    return state_dir() . DIRECTORY_SEPARATOR . 'phone1_' . $key . '.json';
}

function load_phone1_state(string $key): array
{
    $p = phone1_state_path($key);
    if (!is_file($p)) {
        return [];
    }
    $raw = file_get_contents($p);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) {
        return [];
    }
    $ttl = (int) env('PHONE1_STATE_TTL_SECONDS', '600');
    $updatedAt = isset($j['updatedAt']) ? strtotime((string) $j['updatedAt']) : 0;
    if ($updatedAt > 0 && (time() - $updatedAt) > $ttl) {
        @unlink($p);
        return [];
    }
    return $j;
}

function save_phone1_state(string $key, array $state): void
{
    $state['updatedAt'] = date('Y-m-d H:i:s');
    file_put_contents(phone1_state_path($key), json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function clear_phone1_state(string $key): void
{
    $p = phone1_state_path($key);
    if (is_file($p)) {
        @unlink($p);
    }
}

/**
 * ----------------------------
 * Request dedupe gate
 * ----------------------------
 */

function request_key(string $crtObjectId, int $customerId, string $callId): string
{
    $raw = json_encode([
        'crtObjectId' => $crtObjectId,
        'customerId' => $customerId,
        'callId' => $callId,
    ], JSON_UNESCAPED_SLASHES);
    return sha1($raw === false ? ($crtObjectId . '|' . $customerId . '|' . $callId) : $raw);
}

function request_state_path(string $key): string
{
    return state_dir() . DIRECTORY_SEPARATOR . 'req_' . $key . '.json';
}

function load_request_state(string $key): array
{
    $p = request_state_path($key);
    if (!is_file($p)) {
        return [];
    }
    $raw = file_get_contents($p);
    $j = json_decode((string)$raw, true);
    return is_array($j) ? $j : [];
}

function save_request_state(string $key, array $state): void
{
    $state['updatedAt'] = date('Y-m-d H:i:s');
    file_put_contents(request_state_path($key), json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function request_gate_check(string $crtObjectId, int $customerId, string $callId): array
{
    $key = request_key($crtObjectId, $customerId, $callId);
    $state = load_request_state($key);

    $processingTtl = (int) env('REQUEST_PROCESSING_TTL_SECONDS', '30');
    $dedupeTtl = (int) env('REQUEST_DEDUPE_TTL_SECONDS', '300');

    $updatedAt = isset($state['updatedAt']) ? strtotime((string) $state['updatedAt']) : 0;
    $age = $updatedAt > 0 ? (time() - $updatedAt) : PHP_INT_MAX;

    if (!empty($state['status'])) {
        if ($state['status'] === 'processing' && $age < $processingTtl) {
            return ['ok' => false, 'reason' => 'processing', 'key' => $key];
        }
        if ($state['status'] === 'processed' && $age < $dedupeTtl) {
            return ['ok' => false, 'reason' => 'processed', 'key' => $key];
        }
    }

    save_request_state($key, [
        'status' => 'processing',
        'crtObjectId' => $crtObjectId,
        'customerId' => $customerId,
        'callId' => $callId,
    ]);

    return ['ok' => true, 'reason' => 'new', 'key' => $key];
}

function request_gate_complete(string $key, array $sendResult): void
{
    $status = $sendResult['ok'] ? 'processed' : 'failed';
    $state = [
        'status' => $status,
        'result' => $sendResult,
    ];
    save_request_state($key, $state);
}

/**
 * ----------------------------
 * Upstream sender
 * ----------------------------
 */

function send_or_log_request(string $endpointPath, array $payload): array
{
    $envPrefix = normalize_env_prefix(env('INDEX_ENV', 'TEST'));
    $baseUrl = env($envPrefix . '_BASE_URL');
    $apiKey = env($envPrefix . '_API_KEY');

    if ($baseUrl === null || $apiKey === null || $baseUrl === '' || $apiKey === '') {
        send_error_response('Server configuration missing');
        return ['ok' => false, 'status' => 'config_missing'];
    }

    $url = rtrim($baseUrl, '/') . $endpointPath;
    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($jsonPayload === false) {
        send_error_response('Failed to encode payload');
        return ['ok' => false, 'status' => 'encode_failed'];
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
        send_success_response('Upstream send disabled; payload prepared and logged.', [
            'debug' => [
                'endpoint' => $endpointPath,
                'payload' => $payload,
            ],
        ]);
        return ['ok' => true, 'status' => 'send_disabled'];
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
            'body_len' => is_string($post['body']) ? strlen($post['body']) : 0,
            'error' => $post['error'],
        ]
    );

    if (!$post['ok']) {
        send_error_response('Upstream request failed');
        return ['ok' => false, 'status' => 'upstream_failed', 'http_code' => $post['http_code']];
    }

    if (response_already_sent()) {
        log_event('response', 'Upstream response ignored (already responded)', [
            'http_code' => $post['http_code'],
        ]);
        return ['ok' => true, 'status' => 'responded_early', 'http_code' => $post['http_code']];
    }

    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo $post['body'];
    return ['ok' => true, 'status' => 'sent', 'http_code' => $post['http_code']];
}

function normalize_env_prefix(?string $value): string
{
    $upper = strtoupper(trim((string) $value));
    return $upper === 'PROD' ? 'PROD' : 'TEST';
}

/**
 * ----------------------------
 * Misc
 * ----------------------------
 */

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
    if (response_already_sent()) {
        log_event('response', 'Skipped success response (already sent)', [
            'message' => $message,
            'extra' => $extra,
        ]);
        return;
    }

    send_json(array_merge([
        'result' => 'success',
        'message' => $message,
        'request_id' => request_id(),
    ], $extra));
}

function send_error_response(string $message): void
{
    if (response_already_sent()) {
        log_event('response', 'Skipped error response (already sent)', [
            'message' => $message,
        ]);
        return;
    }

    send_json([
        'result' => 'fail',
        'message' => $message,
        'request_id' => request_id(),
    ]);
}

function log_event(string $label, string $message, array $data = [], ?string $channel = null): void
{
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    $channelName = $channel ?? get_log_channel();
    $safeChannel = preg_replace('/[^a-zA-Z0-9_-]/', '_', $channelName);
    if ($safeChannel === '') {
        $safeChannel = 'general';
    }
    $date = date('Y-m-d');
    $logFile = $safeChannel . '-' . $date . '.log';
    $logPath = $logDir . DIRECTORY_SEPARATOR . $logFile;

    if (!file_exists($logPath)) {
        file_put_contents($logPath, '==== ' . $date . ' ====' . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    $time = date('Y-m-d H:i:s');
    $requestId = request_id();
    $event = $label;
    $status = isset($data['ok']) ? ($data['ok'] ? 'success' : 'fail') : '';
    $url = isset($data['url']) ? (string) $data['url'] : '';
    $payload = isset($data['payload']) ? $data['payload'] : null;
    $query = isset($data['query']) ? $data['query'] : null;
    $body = isset($data['body']) ? $data['body'] : null;

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
    if ($body !== null) {
        $bodyStr = (string) $body;
        $parts[] = $bodyStr === '' ? 'body=[empty]' : 'body=' . $bodyStr;
    }
    if (isset($data['http_code'])) {
        $parts[] = 'http_code=' . (string) $data['http_code'];
    }
    if (isset($data['error']) && $data['error'] !== '') {
        $parts[] = 'error=' . (string) $data['error'];
    }
    if (isset($data['body_len'])) {
        $parts[] = 'body_len=' . (string) $data['body_len'];
    }
    if ($query !== null) {
        $queryJson = json_encode($query, JSON_UNESCAPED_SLASHES);
        if ($queryJson !== false && $queryJson !== '') {
            $parts[] = 'query=' . $queryJson;
        }
    }

    // Include any extra fields for debugging (excluding ones already handled above).
    $handled = [
        'ok', 'url', 'payload', 'query', 'body', 'body_len', 'http_code', 'error',
    ];
    foreach ($data as $key => $value) {
        if (in_array($key, $handled, true)) {
            continue;
        }
        if (is_array($value)) {
            $valueJson = json_encode($value, JSON_UNESCAPED_SLASHES);
            if ($valueJson !== false) {
                $parts[] = $key . '=' . $valueJson;
            }
            continue;
        }
        if ($value === null) {
            $parts[] = $key . '=';
            continue;
        }
        $parts[] = $key . '=' . (string) $value;
    }

    $line = implode(' | ', $parts);
    file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function response_already_sent(): bool
{
    return !empty($GLOBALS['ASYNC_RESPONSE_SENT']);
}

function set_log_channel(string $channel): void
{
    $GLOBALS['LOG_CHANNEL'] = $channel;
}

function get_log_channel(): string
{
    return isset($GLOBALS['LOG_CHANNEL']) ? (string) $GLOBALS['LOG_CHANNEL'] : 'general';
}

function send_dummy_response_and_continue(): void
{
    if (response_already_sent()) {
        return;
    }

    $GLOBALS['ASYNC_RESPONSE_SENT'] = true;

    $responseToForm = [
        'success' => true,
        'message' => 'Data Received',
    ];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($responseToForm, JSON_UNESCAPED_SLASHES);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    @ob_implicit_flush(true);
    ignore_user_abort(true);
    set_time_limit(0);
}
/**
 * Entry
 */
//handle_index_request();
