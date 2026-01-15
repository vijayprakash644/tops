<?php
// Request parsing and validation helpers.

declare(strict_types=1);

function read_json_payload(): array
{
    $raw = null;
    if (isset($_POST['jsonData'])) {
        $raw = $_POST['jsonData'];
    } else {
        $raw = file_get_contents('php://input');
    }

    if (!is_string($raw) || trim($raw) === '') {
        return [
            'ok' => false,
            'error' => 'Missing jsonData payload',
            'raw' => null,
            'data' => null,
        ];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'Invalid JSON in jsonData',
            'raw' => $raw,
            'data' => null,
        ];
    }

    return [
        'ok' => true,
        'error' => null,
        'raw' => $raw,
        'data' => $data,
    ];
}

function require_fields(array $data, array $fields): array
{
    $missing = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
            $missing[] = $field;
        }
    }

    return $missing;
}

function validate_not_answer(array $payload): array
{
    if (!isset($payload['predictiveCallCreateNotAnswer']) || !is_array($payload['predictiveCallCreateNotAnswer'])) {
        return ['ok' => false, 'error' => 'Missing predictiveCallCreateNotAnswer object'];
    }
    $obj = $payload['predictiveCallCreateNotAnswer'];
    $missing = require_fields($obj, ['callId', 'callTime', 'errorInfo1']);
    if ($missing !== []) {
        return ['ok' => false, 'error' => 'Missing required fields: ' . implode(', ', $missing)];
    }

    return ['ok' => true, 'error' => null];
}

function validate_call_start(array $payload): array
{
    if (!isset($payload['predictiveCallCreateCallStart']) || !is_array($payload['predictiveCallCreateCallStart'])) {
        return ['ok' => false, 'error' => 'Missing predictiveCallCreateCallStart object'];
    }
    $obj = $payload['predictiveCallCreateCallStart'];
    $missing = require_fields($obj, ['callId', 'predictiveStaffId', 'targetTel']);
    if ($missing !== []) {
        return ['ok' => false, 'error' => 'Missing required fields: ' . implode(', ', $missing)];
    }

    return ['ok' => true, 'error' => null];
}

function validate_call_end(array $payload): array
{
    if (!isset($payload['predictiveCallCreateCallEnd']) || !is_array($payload['predictiveCallCreateCallEnd'])) {
        return ['ok' => false, 'error' => 'Missing predictiveCallCreateCallEnd object'];
    }
    $obj = $payload['predictiveCallCreateCallEnd'];
    $missing = require_fields($obj, [
        'callId',
        'callStartTime',
        'callEndTime',
        'subCtiHistoryId',
        'targetTel',
        'predictiveStaffId',
    ]);
    if ($missing !== []) {
        return ['ok' => false, 'error' => 'Missing required fields: ' . implode(', ', $missing)];
    }

    return ['ok' => true, 'error' => null];
}