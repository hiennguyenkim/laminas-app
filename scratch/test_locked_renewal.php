<?php
require 'vendor/autoload.php';
$container = require 'config/container.php';
$service = $container->get(Library\Service\CirculationService::class);
$borrowTable = $container->get(Library\Model\Table\BorrowTable::class);

try {
    // Find an active borrow for user 2
    $db = $container->get(Laminas\Db\Adapter\AdapterInterface::class);
    $row = $db->query("SELECT borrow_id FROM borrow_records WHERE user_id = 9 AND status = 'borrowed' LIMIT 1")->execute()->current();
    
    if ($row) {
        echo "Attempting to renew record #{$row['borrow_id']} for locked user 9...\n";
        $service->renewBook((int)$row['borrow_id'], 9);
        echo "SUCCESS (Error: Should have been blocked)\n";
    } else {
        echo "No active borrow found for user 2 to test.\n";
    }
} catch (\Exception $e) {
    echo "CAUGHT EXPECTED ERROR: " . $e->getMessage() . "\n";
}
