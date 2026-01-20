<?php
declare(strict_types=1);

/**
 * index.php
 *
 * Adds “events/conditions” for:
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

    log_event(
        'incoming_get',
        'Received dialer callback',
        [
            'client' => client_context(),
            'query' => $_GET,
        ]
    );
    send_dummy_response_and_continue();
    sleep(1); // slight delay to ensure response sent before continuing

    $callId = get_param($_GET, 'unique_id');
    $customerId = to_int(get_param($_GET, 'customerId'), 0);
    if ($callId === '') {
        send_error_response('Missing required fields: unique_id');
        return;
    }

    $predictiveStaffId = get_param($_GET, 'userId');
    $phone1Param = get_param($_GET, 'phone1');
    $phoneParam = get_param($_GET, 'phone');
    $cstmPhone = get_param($_GET, 'cstmPhone');

    $targetTel = get_param($_GET, 'dialledPhone');
    if ($targetTel === '') {
        $targetTel = get_param($_GET, 'dstPhone');
    }
    if ($cstmPhone !== '' && ($dialIndex >= 1 || $targetTel === '')) {
        $targetTel = $cstmPhone;
    }

    $systemDisposition = get_param($_GET, 'systemDisposition');
    $dispositionCode = get_param($_GET, 'dispositionCode');

    // Dialing meta
    $phones = parse_phone_list($_GET); // from phoneList JSON if present
    $phoneLookup = $phone1Param !== '' ? $phone1Param : (isset($phones[0]) ? (string) $phones[0] : '');
    $dialIndex = to_int(get_param($_GET, 'shareablePhonesDialIndex', '0'), 0);
    $numAttempts = to_int(get_param($_GET, 'numAttempts', '1'), 1);

    // Connection detection: prefer callConnectedTime (your real logs)
    $isConnected = is_connected_from_get($_GET, $systemDisposition);

    // Determine current attempt status
    $statusNow = pick_error_info($dispositionCode, $systemDisposition);
    $now = date('Y-m-d H:i:s');

    // Load & update state so that log2 can include phone1 status
    $state = load_state($callId);

    // Persist phones (if we have them)
    // Persist / refresh phones (prefer the longest list we have seen)
    if (!isset($state['phones']) || !is_array($state['phones'])) {
        $state['phones'] = [];
    }

    if (count($phones) > count($state['phones'])) {
        $state['phones'] = $phones;
    }


    if (!isset($state['statusByIndex']) || !is_array($state['statusByIndex'])) {
        $state['statusByIndex'] = [];
    }
    if (!isset($state['connectedByIndex']) || !is_array($state['connectedByIndex'])) {
        $state['connectedByIndex'] = [];
    }

    // Save attempt result by dialIndex
    $state['statusByIndex'][(string)$dialIndex] = $isConnected ? 'CONNECTED' : $statusNow;
    $state['connectedByIndex'][(string)$dialIndex] = $isConnected;
    $state['lastDialIndex'] = $dialIndex;
    $state['numAttempts'] = $numAttempts;

    // If you want to also store targetTel seen for each attempt:
    if (!isset($state['targetByIndex']) || !is_array($state['targetByIndex'])) {
        $state['targetByIndex'] = [];
    }
    if ($targetTel !== '') {
        $state['targetByIndex'][(string)$dialIndex] = $targetTel;
    }

    save_state($callId, $state);

    // Determine phone count from state (prefer state)
    $phonesFromState = (isset($state['phones']) && is_array($state['phones'])) ? $state['phones'] : [];
    // Force targetTel based on dialIndex when we have the phone list
    if (isset($phonesFromState[$dialIndex]) && $phonesFromState[$dialIndex] !== '') {
        $targetTel = (string)$phonesFromState[$dialIndex];
    }

    $hasPhone2 = count($phonesFromState) >= 2;

    // Evaluate “events/conditions”
    $phone1Connected = !empty($state['connectedByIndex']['0']);
    $phone2Connected = !empty($state['connectedByIndex']['1']);

    $phone1Status = $state['statusByIndex']['0'] ?? '';
    $phone2Status = $state['statusByIndex']['1'] ?? '';

    // -----------------------------
    // EVENT / CONDITION ROUTING
    // -----------------------------
    // 1) Phone1 connected -> CallEnd only, do NOT send anything for phone2
    $shouldCallEndPhone1 = ($phone1Connected && $dialIndex === 0 && $isConnected);

    // 2) Phone2 connected -> CallEnd; include phone1 failure in errorInfo
    $shouldCallEndPhone2 = ($hasPhone2 && $phone2Connected && $dialIndex >= 1 && $isConnected);

    // 3) Not connected -> NotAnswer; include both statuses if phone2 attempted
    $shouldNotAnswer = (!$isConnected);

    // Payload + endpoint
    $payload = [];
    $endpointPath = '';

    if ($shouldCallEndPhone1) {
        // phone1 connected -> must have staff and target
        if ($predictiveStaffId === '' || $targetTel === '') {
            send_error_response('Missing required fields: userId, dialledPhone/dstPhone');
            return;
        }

        $connectedRaw = get_param($_GET, 'callConnectedTime');   // e.g. 2026/01/20 11:16:41 +0900
        $connectedAt  = parse_ameyo_time($connectedRaw);

        // If dialer doesn't send callStartTime, use parsed callConnectedTime as start
        $callStartTime = get_param($_GET, 'callStartTime', $connectedAt !== '' ? $connectedAt : $now);

        // If dialer doesn't send callEndTime, use now (or parse another field if you have it)
        $callEndTime = get_param($_GET, 'callEndTime', $now);
        $subCtiHistoryId = get_param($_GET, 'subCtiHistoryId', $callId);

        // No errorInfo because phone1 succeeded
        $payload = build_call_end_payload(
            $callId,
            $callStartTime,
            $callEndTime,
            $subCtiHistoryId,
            $targetTel,
            $predictiveStaffId,
            '' // errorInfo empty
        );

        $validation = validate_call_end($payload);
        if (!$validation['ok']) {
            send_error_response($validation['error']);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json';

        // Cleanup state because final success happened
        clear_state($callId);

        log_event('decision', 'Phone1 connected -> createCallEnd (no phone2)', [
            'callId' => $callId,
            'dialIndex' => $dialIndex,
            'phones' => $phonesFromState,
        ]);

    } elseif ($shouldCallEndPhone2) {
        // phone2 connected -> include phone1 status as errorInfo if phone1 failed / not connected
        if ($predictiveStaffId === '' || $targetTel === '') {
            send_error_response('Missing required fields: userId, dialledPhone/dstPhone');
            return;
        }

        $connectedRaw = get_param($_GET, 'callConnectedTime');   // e.g. 2026/01/20 11:16:41 +0900
        $connectedAt  = parse_ameyo_time($connectedRaw);

        // If dialer doesn't send callStartTime, use parsed callConnectedTime as start
        $callStartTime = get_param($_GET, 'callStartTime', $connectedAt !== '' ? $connectedAt : $now);

        // If dialer doesn't send callEndTime, use now (or parse another field if you have it)
        $callEndTime = get_param($_GET, 'callEndTime', $now);
        $subCtiHistoryId = get_param($_GET, 'subCtiHistoryId', $callId);

        // Include errorInfo only if phone1 was not connected AND we have a status
        $phone1Failed = (!$phone1Connected && $phone1Status !== '' && strtoupper($phone1Status) !== 'CONNECTED');
        $errorInfo = $phone1Failed ? $phone1Status : '';
        $customerId = to_int(get_param($_GET, 'customerId'), 0);
        $resolvedPhone1 = fetch_phone1_status_from_db($customerId, $phoneLookup);
        $phone1Failed = ($resolvedPhone1 !== '' && strtoupper($resolvedPhone1) !== 'CONNECTED');
        // Only send errorInfo when phone1 failed
        $errorInfo = $phone1Failed ? $resolvedPhone1 : '';


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

        // Cleanup state because final success happened
        clear_state($callId);

        log_event('decision', 'Phone2 connected -> createCallEnd (with phone1 errorInfo if failed)', [
            'callId' => $callId,
            'dialIndex' => $dialIndex,
            'phone1Status' => $phone1Status,
            'resolvedPhone1' => $resolvedPhone1,
            'phones' => $phonesFromState,
            'errorInfo' => $errorInfo,
        ]);

    } elseif ($shouldNotAnswer) {
        $callTime = get_param($_GET, 'callTime', $now);

        // Decide whether to send 2 statuses:
        // - if we have 2 phones AND (dialIndex >= 1 OR numAttempts >= 2) OR we already have status for index1 in state
        $phone2Attempted = ($dialIndex >= 1) || ($numAttempts >= 2) || isset($state['statusByIndex']['1']);

        // Build errorInfo1 from phone1 status if known, else current
        //$errorInfo1 = $phone1Status !== '' ? $phone1Status : (($dialIndex === 0) ? $statusNow : 'UNKNOWN');
        $dbPhone1Status = '';

        if ($phone1Status !== '') {
            $errorInfo1 = $phone1Status;
        } elseif ($dialIndex === 0) {
            $errorInfo1 = $statusNow;
        } else {
        // Try DB as final fallback
        if ($customerId > 0) {
            $dbPhone1Status = fetch_phone1_status_from_db($customerId, $phoneLookup);
        }
            $errorInfo1 = $dbPhone1Status !== '' ? $dbPhone1Status : 'UNKNOWN';
        }
        if ($dbPhone1Status !== '') {
            log_event('decision', 'Phone1 status resolved from DB', [
            'customerId' => $customerId,
            'systemDisposition' => $dbPhone1Status,
            ]);
        }



        // Build errorInfo2 (only when 2nd attempted)
        $errorInfo2 = '';
        if ($phone2Attempted) {
            $errorInfo2 = $phone2Status !== '' ? $phone2Status : $statusNow; // for dialIndex=1, statusNow is correct
        }


        $payload = build_not_answer_payload($callId, $callTime, $errorInfo1, $phone2Attempted ? $errorInfo2 : '');

        $validation = validate_not_answer($payload);
        if (!$validation['ok']) {
            send_error_response($validation['error']);
            return;
        }

        $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createNotAnswer.json';

        log_event('decision', $phone2Attempted ? 'Not connected -> createNotAnswer (errorInfo1+2)' : 'Not connected -> createNotAnswer (errorInfo1 only)', [
            'callId' => $callId,
            'dialIndex' => $dialIndex,
            'numAttempts' => $numAttempts,
            'phone2Attempted' => $phone2Attempted,
            'errorInfo1' => $errorInfo1,
            'errorInfo2' => $phone2Attempted ? $errorInfo2 : '',
            'phones' => $phonesFromState,
        ]);

        // Optional: if phone2Attempted and not connected, you may clear state because dialing cycle ended.
        // If your dialer may retry more, keep it. Here we keep it by default.

    } else {
        // Fallback safety
        send_error_response('Unable to determine action from parameters');
        return;
    }

    send_or_log_request($endpointPath, $payload);
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
 * PostgreSQL helpers
 * ----------------------------
 */

function pg_conn(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = env('PG_HOST');
    $db   = env('PG_DB');
    $user = env('PG_USER');
    $pass = env('PG_PASSWORD');
    $port = env('PG_PORT', '5432');

    if (!$host || !$db || !$user) {
        log_event('pg_error', 'Missing PG env config');
        return null;
    }

    try {
        $dsn = "pgsql:host={$host};port={$port};dbname={$db}";
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (Throwable $e) {
        log_event('pg_error', 'Connection failed', ['error' => $e->getMessage()]);
        return null;
    }
}

function fetch_phone1_status_from_db(int $customerId, string $phone): string
{
    $pdo = pg_conn();
    if (!$pdo) {
        return '';
    }

    if ($phone === '') {
        log_event('db_lookup', 'Skipped lookup (missing phone filters)', [
            'customerId' => $customerId,
            'phone' => $phone,
        ]);
        return '';
    }

    try {
        $sql = "
            SELECT system_disposition, phone, customer_id, date_added
            FROM call_history o
            WHERE customer_id = :cid
              AND phone = :phone
              AND o.date_added >= CURRENT_DATE
              AND o.date_added < (CURRENT_DATE + INTERVAL '1 day')
            ORDER BY date_added DESC
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cid' => $customerId,
            ':phone' => $phone,
        ]);
        $row = $stmt->fetch();

        if (isset($row['system_disposition'])) {
            return trim((string)$row['system_disposition']);
        }

        log_event('db_lookup', 'No data for customer/phone, retrying after sleep', [
            'customerId' => $customerId,
            'phone' => $phone,
            'sql' => trim($sql),
        ]);

        $sleepSeconds = (int) env('DB_LOOKUP_SLEEP_SECONDS', '1');
        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':cid' => $customerId,
            ':phone' => $phone,
        ]);
        $row = $stmt->fetch();
        if (isset($row['system_disposition'])) {
            return trim((string)$row['system_disposition']);
        }

        log_event('db_lookup', 'No data after retry', [
            'customerId' => $customerId,
            'phone' => $phone,
            'sql' => trim($sql),
        ]);

        return '';
    } catch (Throwable $e) {
        log_event('pg_error', 'Query failed', [
            'customerId' => $customerId,
            'phone' => $phone,
            'sql' => trim($sql),
            'error' => $e->getMessage(),
        ]);
        return '';
    }
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
 * Upstream sender
 * ----------------------------
 */

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
        send_success_response('Upstream send disabled; payload prepared and logged.', [
            'debug' => [
                'endpoint' => $endpointPath,
                'payload' => $payload,
            ],
        ]);
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

    if (response_already_sent()) {
        log_event('response', 'Upstream response ignored (already responded)', [
            'http_code' => $post['http_code'],
        ]);
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
    $query = isset($data['query']) ? $data['query'] : null;

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
    if ($query !== null) {
        $queryJson = json_encode($query, JSON_UNESCAPED_SLASHES);
        if ($queryJson !== false && $queryJson !== '') {
            $parts[] = 'query=' . $queryJson;
        }
    }

    // Add extra event metadata (helps debugging)
    foreach ($data as $k => $v) {
        if (in_array($k, ['url', 'payload', 'ok'], true)) continue;
        $parts[] = $k . '=' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_SLASHES));
    }

    $line = implode(' | ', $parts);
    file_put_contents($logDir . DIRECTORY_SEPARATOR . 'index.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function response_already_sent(): bool
{
    return !empty($GLOBALS['ASYNC_RESPONSE_SENT']);
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
handle_index_request();
