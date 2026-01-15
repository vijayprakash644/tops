<?php
// Shared bootstrap for env/config and helpers.

declare(strict_types=1);

const APP_BASE_DIR = __DIR__ . DIRECTORY_SEPARATOR . '..';

require_once __DIR__ . DIRECTORY_SEPARATOR . 'env.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'http_client.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'validators.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'response.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'handlers.php';

load_env_file(APP_BASE_DIR . DIRECTORY_SEPARATOR . '.env');
