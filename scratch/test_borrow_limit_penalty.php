<?php
require 'vendor/autoload.php';
$app = Laminas\Mvc\Application::init(require 'config/application.config.php');
$sm = $app->getServiceManager();

$db = $sm->get(Laminas\Db\Adapter\AdapterInterface::class);
$circulationService = $sm->get(\Library\Service\CirculationService::class);
$userTable = $sm->get(\Library\Model\Table\UserTable::class);
$borrowTable = $sm->get(\Library\Model\Table\BorrowTable::class);

echo "--- START COMPREHENSIVE PENALTY TEST ---\n";

function runTestForLateCount($expectedLateCount, $expectedLimit, $db, $circulationService, $userTable, $borrowTable) {
    echo "\n=== Testing for expected late count: $expectedLateCount (Expected Limit: $expectedLimit) ===\n";
    
    // 1. Create temporary student
    $username = 'test_penalty_std_' . $expectedLateCount . '_' . time();
    $email = $username . '@example.com';
    $db->query("INSERT INTO users (username, email, password, full_name, role, is_approved, borrow_limit, account_status)
                VALUES (?, ?, 'dummy_pass', 'Test Student Penalty', 'student', 1, 5, 'active')")
       ->execute([$username, $email]);
    $userId = (int)$db->getDriver()->getConnection()->getLastGeneratedValue();

    // 2. Create temporary book
    $db->query("INSERT INTO books (title, author, category, quantity, status) VALUES ('Test Book', 'Author', 'Khác', 10, 'available')")
       ->execute();
    $bookId = (int)$db->getDriver()->getConnection()->getLastGeneratedValue();

    // 3. Create historical late returns (expectedLateCount - 1 records)
    $historicalCount = $expectedLateCount - 1;
    for ($i = 0; $i < $historicalCount; $i++) {
        $db->query("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status, returned_at) 
                    VALUES (?, ?, '2026-05-01', '2026-05-10', 'returned', '2026-05-12 10:00:00')")
           ->execute([$bookId, $userId]);
    }

    // 4. Create 1 active overdue loan
    $db->query("INSERT INTO borrow_records (book_id, user_id, borrow_date, return_date, status) 
                VALUES (?, ?, '2026-05-10', '2026-05-20', 'borrowed')")
       ->execute([$bookId, $userId]);
    $borrowId = (int)$db->getDriver()->getConnection()->getLastGeneratedValue();

    // 5. Call returnBook
    try {
        $circulationService->returnBook($borrowId);
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }

    // 6. Verify User state
    $user = $userTable->getUser($userId);
    $lateCount = $borrowTable->countReturnedLateForUser($userId);

    echo "Results:\n";
    echo "- Late count: $lateCount\n";
    echo "- Account status: " . $user->accountStatus . "\n";
    echo "- Locked until: " . $user->lockedUntil . "\n";
    echo "- Borrow Limit: " . $user->borrowLimit . "\n";

    if ($user->borrowLimit === $expectedLimit) {
        echo "SUCCESS: Borrow limit was correctly updated to $expectedLimit!\n";
    } else {
        echo "FAILURE: Borrow limit is " . $user->borrowLimit . " (expected $expectedLimit)\n";
    }

    // Clean up
    $db->query("DELETE FROM borrow_records WHERE user_id = ?", [$userId]);
    $db->query("DELETE FROM users WHERE user_id = ?", [$userId]);
    $db->query("DELETE FROM books WHERE book_id = ?", [$bookId]);
    $db->query("DELETE FROM penalty_logs WHERE user_id = ?", [$userId]);
}

// Test Mốc 1 (3-4 lần trễ) -> Limit = 4
runTestForLateCount(3, 4, $db, $circulationService, $userTable, $borrowTable);

// Test Mốc 2 (5 lần trễ) -> Limit = 2
runTestForLateCount(5, 2, $db, $circulationService, $userTable, $borrowTable);

// Test Mốc 3 (6 lần trễ) -> Limit = 1
runTestForLateCount(6, 1, $db, $circulationService, $userTable, $borrowTable);

echo "\n--- END COMPREHENSIVE TEST ---\n";
