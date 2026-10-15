<?php

declare(strict_types=1);

/**
 * Gmail Payment Polling Cron Script
 * Run via Windows Task Scheduler every 1 minute:
 *   C:\xampp\php\php.exe bin/cron-poll-gmail.php
 *   Start In: C:\xampp\htdocs\laminas-app
 */

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

// ── Timezone ────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Ho_Chi_Minh');

// ── Log file (tự động giữ tối đa 1000 dòng mới nhất) ──────────────────────
$logFile = __DIR__ . '/../data/logs/cron-gmail.log';
if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}

function rotateLog(string $file, int $maxLines = 1000): void
{
    if (!file_exists($file)) {
        return;
    }
    $lines = file($file);
    if ($lines !== false && count($lines) > $maxLines) {
        $trimmed = array_slice($lines, -$maxLines);
        file_put_contents($file, implode('', $trimmed), LOCK_EX);
    }
}
rotateLog($logFile, 1000);

function cronLog(string $msg): void
{
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    echo $line;
}

// ── Prevent duplicate runs (lock file) ──────────────────────────────────────
$lockFile = sys_get_temp_dir() . '/cron-poll-gmail.lock';
$lockFp   = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    cronLog('SKIP: Another instance is already running (lock file busy).');
    exit(0);
}

// ── PHP execution timeout (max 55s — safely under 1-minute Task interval) ───
set_time_limit(55);

// ── Main with Retry Logic ───────────────────────────────────────────────────
cronLog('=== Gmail Polling START ===');

$maxRetries = 3;
$retryDelay = 2; // seconds
$processed = null;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    try {
        $container = require 'config/container.php';

        /** @var \Library\Service\GmailService $gmailService */
        $gmailService = $container->get(\Library\Service\GmailService::class);

        $processed = $gmailService->processEmails();

        $count = count($processed);
        if ($count > 0) {
            cronLog("SUCCESS: Processed {$count} payment(s): " . implode(', ', $processed));
        } else {
            cronLog("OK: No new payments found.");
        }
        break; // Success!
    } catch (\Throwable $e) {
        $isDbError = str_contains($e->getMessage(), 'SQLSTATE') || str_contains($e->getMessage(), 'connection');
        cronLog("ERROR (Attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
        if ($attempt < $maxRetries && $isDbError) {
            cronLog("Database connection error, waiting {$retryDelay}s before retry...");
            sleep($retryDelay);
        } else {
            cronLog('Trace: ' . $e->getFile() . ':' . $e->getLine());
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            exit(1);
        }
    }
}

cronLog('=== Gmail Polling END ===');

// ── Release lock ─────────────────────────────────────────────────────────────
flock($lockFp, LOCK_UN);
fclose($lockFp);
exit(0);
