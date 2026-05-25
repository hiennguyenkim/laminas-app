<?php
/**
 * Data Sanitization - Fix Ticket Statuses
 * Goal: Ensure ticket status matches the LAST sender's role.
 * If last message is from user -> open
 * If last message is from admin -> in_progress
 * Ignore closed tickets.
 */

$dsn = 'mysql:host=localhost;dbname=library_db;charset=utf8mb4';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    echo "Auditing Ticket Statuses vs Message History...\n";

    $tickets = $pdo->query("SELECT id, status FROM support_tickets")->fetchAll();
    $stmtLastMsg = $pdo->prepare("SELECT sender_role FROM ticket_messages WHERE ticket_id = ? ORDER BY sent_at DESC LIMIT 1");
    $stmtUpdate = $pdo->prepare("UPDATE support_tickets SET status = ? WHERE id = ?");

    $fixedCount = 0;
    foreach ($tickets as $t) {
        if ($t['status'] === 'closed') {
            continue; // Keep closed tickets as closed
        }

        $stmtLastMsg->execute([$t['id']]);
        $lastRole = $stmtLastMsg->fetchColumn();

        if ($lastRole === 'user' && $t['status'] === 'in_progress') {
            // Student replied back, but status is still 'in_progress' (answered)
            $stmtUpdate->execute(['open', $t['id']]);
            $fixedCount++;
        } elseif ($lastRole === 'admin' && $t['status'] === 'open') {
            // Admin replied, but status is still 'open' (unanswered)
            $stmtUpdate->execute(['in_progress', $t['id']]);
            $fixedCount++;
        }
    }

    echo "Found and fixed $fixedCount logical flaws (Updated ticket statuses based on last sender).\n";
    echo "Logic Audit COMPLETED successfully.\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
