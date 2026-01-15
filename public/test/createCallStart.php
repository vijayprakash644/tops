<?php
require_once __DIR__ . '/../../src/bootstrap.php';

handle_predictive_request(
    'TEST',
    '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallStart.json',
    'validate_call_start'
);