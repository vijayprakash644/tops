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
    send_dummy_response_and_continue();

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
    ]);

    $endpointPath = '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallStart.json';
    send_or_log_request($endpointPath, $payload);
}
