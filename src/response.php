<?php
// Response helper.

declare(strict_types=1);

function send_json(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function send_error(string $message, int $httpCode = 200): void
{
    http_response_code($httpCode);
    send_json([
        'result' => 'fail',
        'message' => $message,
    ]);
}