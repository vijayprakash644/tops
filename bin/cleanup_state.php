<?php
/**
 * bin/cleanup_state.php
 *
 * Deletes expired state files from logs/state/.
 * Safe to run from cron — exits 0 on success.
 *
 * Usage:
 *   php bin/cleanup_state.php
 *
 * Recommended cron (every 30 minutes):
 *   * /30 * * * * php /path/to/tops/bin/cleanup_state.php >> /path/to/tops/logs/cleanup.log 2>&1
 */

declare(strict_types=1);

define('APP_BASE_DIR', dirname(__DIR__));

require_once APP_BASE_DIR . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'env.php';

load_env_file(APP_BASE_DIR . DIRECTORY_SEPARATOR . '.env');

$stateDir = APP_BASE_DIR . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'state';

if (!is_dir($stateDir)) {
    echo date('Y-m-d H:i:s') . " | state dir does not exist, nothing to clean\n";
    exit(0);
}

// TTLs (match the app defaults)
$phone1Ttl  = (int) (getenv('PHONE1_STATE_TTL_SECONDS')        ?: 600);
$reqProcTtl = (int) (getenv('REQUEST_PROCESSING_TTL_SECONDS')  ?: 30);
$reqDedupe  = (int) (getenv('REQUEST_DEDUPE_TTL_SECONDS')       ?: 300);
$callStartT = $reqDedupe; // call_start uses the same dedupe TTL

// Grace multiplier: keep files a bit longer than the TTL to be safe
$graceFactor = 2;

$deleted = 0;
$skipped = 0;
$errors  = 0;

$files = glob($stateDir . DIRECTORY_SEPARATOR . '*.json');
if ($files === false) {
    echo date('Y-m-d H:i:s') . " | glob failed\n";
    exit(1);
}

foreach ($files as $file) {
    $basename = basename($file);
    $raw = @file_get_contents($file);
    if ($raw === false) {
        $errors++;
        continue;
    }

    $data = json_decode($raw, true);
    $updatedAt = is_array($data) && isset($data['updatedAt'])
        ? strtotime((string) $data['updatedAt'])
        : 0;

    if ($updatedAt <= 0) {
        // Cannot determine age — use file mtime as fallback
        $updatedAt = (int) filemtime($file);
    }

    $age = time() - $updatedAt;

    // Determine TTL based on file name prefix
    if (str_starts_with($basename, 'phone1_')) {
        $ttl = $phone1Ttl * $graceFactor;
    } elseif (str_starts_with($basename, 'call_start_')) {
        $ttl = $callStartT * $graceFactor;
    } elseif (str_starts_with($basename, 'req_')) {
        // For waiting_phone2, give extra time
        $status = is_array($data) ? ($data['status'] ?? '') : '';
        $ttl = ($status === 'waiting_phone2')
            ? $phone1Ttl * $graceFactor
            : $reqDedupe * $graceFactor;
    } else {
        // Unknown prefix — use the longest TTL as safe default
        $ttl = max($phone1Ttl, $reqDedupe) * $graceFactor;
    }

    if ($age > $ttl) {
        if (@unlink($file)) {
            $deleted++;
            echo date('Y-m-d H:i:s') . " | deleted | {$basename} | age={$age}s ttl={$ttl}s\n";
        } else {
            $errors++;
            echo date('Y-m-d H:i:s') . " | error   | could not delete {$basename}\n";
        }
    } else {
        $skipped++;
    }
}

echo date('Y-m-d H:i:s') . " | done | deleted={$deleted} skipped={$skipped} errors={$errors}\n";
exit($errors > 0 ? 1 : 0);
