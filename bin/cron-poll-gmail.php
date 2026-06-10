<?php

declare(strict_types=1);

// Run with: php bin/cron-poll-gmail.php

chdir(dirname(__DIR__));

require 'vendor/autoload.php';

use Library\Service\GmailService;

try {
    $container = require 'config/container.php';
    /** @var GmailService $gmailService */
    $gmailService = $container->get(GmailService::class);

    echo "[" . date('Y-m-d H:i:s') . "] Starting Gmail Polling..." . PHP_EOL;

    $processed = $gmailService->processEmails();

    echo "Processed " . count($processed) . " payment(s)." . PHP_EOL;
    if (count($processed) > 0) {
        echo "Order codes: " . implode(', ', $processed) . PHP_EOL;
    }

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
