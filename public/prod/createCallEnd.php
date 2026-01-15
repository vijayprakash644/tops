<?php
require_once __DIR__ . '/../../src/bootstrap.php';

handle_predictive_request(
    'PROD',
    '/fasthelp5-server/service/callmanage/predictiveCallApiService/createCallEnd.json',
    'validate_call_end'
);