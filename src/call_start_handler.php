<?php
declare(strict_types=1);

function handle_call_start_request(): void
{
    ensure_tokyo_timezone();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        send_error_response('Only GET is allowed');
        return;
    }

    set_log_channel('call_start');
    log_event(
        'incoming_get',
        'Received call start callback',
        [
            'client' => client_context(),
            'query' => $_GET,
        ]
    );
    send_empty_response_and_continue();

    $callId = get_param($_GET, 'callId');
    $callIdSource = 'callId';
    if ($callId === '') {
        $callId = get_param($_GET, 'cs_unique_id');
        $callIdSource = 'cs_unique_id';
    }
    if ($callId === '') {
        $callId = get_param($_GET, 'crm_push_generated_time');
        $callIdSource = 'crm_push_generated_time';
    }
    if ($callId === '') {
        $callId = get_param($_GET, 'sessionId');
        $callIdSource = 'sessionId';
    }

    if ($callId === '') {
        send_error_response('Missing required fields: callId or unique_id or crm_push_generated_time');
        return;
    }

    $predictiveStaffId = get_param($_GET, 'userId');
    if ($predictiveStaffId === '') {
        send_error_response('Missing required fields: userId');
        return;
    }

    $customerCrtId = get_param($_GET, 'crtObjectId');

    $targetTel = get_param($_GET, 'phone');
    if ($targetTel === '') {
        $targetTel = get_param($_GET, 'displayPhone');
    }
    if ($targetTel === '') {
        $targetTel = get_param($_GET, 'dialledPhone');
    }
    if ($targetTel === '') {
        $targetTel = get_param($_GET, 'dstPhone');
    }

    if ($targetTel === '') {
        send_error_response('Missing required fields: phone');
        return;
    }

    $gate = call_start_gate_check($callId, $predictiveStaffId, $targetTel, $customerCrtId);
    if (!$gate['ok']) {
        log_event('dedupe', 'Skipped duplicate call start', [
            'reason' => $gate['reason'],
            'callId' => $callId,
            'predictiveStaffId' => $predictiveStaffId,
            'targetTel' => $targetTel,
            'customerCRTId' => $customerCrtId,
        ]);
        return;
    }

    $payload = [
        'predictiveCallCreateCallStart' => [
            'callId' => $callId,
            'predictiveStaffId' => $predictiveStaffId,
            'targetTel' => $targetTel,
        ],
    ];

    $validation = validate_call_start($payload);
    if (!$validation['ok']) {
        send_error_response($validation['error']);
        return;
    }

    log_event('decision', 'createCallStart', [
        'callId' => $callId,
        'callIdSource' => $callIdSource,
        'predictiveStaffId' => $predictiveStaffId,
        'targetTel' => $targetTel,
        'customerCRTId' => $customerCrtId,
    ]);

    $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallStart.json';
    $sendResult = send_or_log_request($endpointPath, $payload);
    call_start_gate_complete($gate['key'], $sendResult);
}

function call_start_gate_check(string $callId, string $predictiveStaffId, string $targetTel, string $customerCrtId): array
{
    $key = sha1($callId . '|' . $predictiveStaffId . '|' . $targetTel . '|' . $customerCrtId);
    $stateDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'state';
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0775, true);
    }
    $path = $stateDir . DIRECTORY_SEPARATOR . 'call_start_' . $key . '.json';
    $state = [];
    if (is_file($path)) {
        $raw = file_get_contents($path);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $state = $decoded;
        }
    }

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

    $state = [
        'status' => 'processing',
        'callId' => $callId,
        'predictiveStaffId' => $predictiveStaffId,
        'targetTel' => $targetTel,
        'customerCRTId' => $customerCrtId,
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
    file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);

    return ['ok' => true, 'reason' => 'new', 'key' => $key];
}

function call_start_gate_complete(string $key, array $sendResult): void
{
    $stateDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'state';
    if (!is_dir($stateDir)) {
        mkdir($stateDir, 0775, true);
    }
    $path = $stateDir . DIRECTORY_SEPARATOR . 'call_start_' . $key . '.json';
    $state = [
        'status' => $sendResult['ok'] ? 'processed' : 'failed',
        'result' => $sendResult,
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
    file_put_contents($path, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}
