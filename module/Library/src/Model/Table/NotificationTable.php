<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class NotificationTable
{
    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    private function getAdapter(): AdapterInterface
    {
        return $this->tableGateway->getAdapter();
    }

    public function findAdminId(): ?int
    {
        $sql = "SELECT user_id FROM users WHERE role = 'admin' LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute()->current();
        return $row ? (int) $row['user_id'] : null;
    }

    public function getOverdueRecords(): array
    {
        $sqlScan = "SELECT r.*, b.title as book_title 
                    FROM borrow_records r 
                    JOIN books b ON r.book_id = b.book_id 
                    WHERE r.status IN ('borrowed', 'overdue') AND r.return_date < CURDATE()";
        $results = $this->getAdapter()->query($sqlScan)->execute();
        return iterator_to_array($results);
    }

    public function updateOverdueRecordStatus(int $borrowId): void
    {
        $this->getAdapter()->query("UPDATE borrow_records SET status = 'overdue' WHERE borrow_id = ?")->execute([$borrowId]);
    }

    public function warningNotificationExists(int $borrowId): bool
    {
        $check = $this->getAdapter()->query("SELECT id FROM notifications WHERE type = 'borrow_alert' AND related_id = ?")->execute([$borrowId]);
        return $check->count() > 0;
    }

    public function deleteNotification(int $id, ?int $userId = null): void
    {
        if ($userId === null) {
            $this->getAdapter()->query("UPDATE notifications SET is_deleted = 1 WHERE id = ? AND user_id IS NULL")->execute([$id]);
        } else {
            $this->getAdapter()->query("UPDATE notifications SET is_deleted = 1 WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        }
    }

    public function markAsRead(int $id, ?int $userId = null): void
    {
        if ($userId === null) {
            $this->getAdapter()->query("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id IS NULL")->execute([$id]);
        } else {
            $this->getAdapter()->query("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
        }
    }

    public function markAllAsRead(?int $userId = null): void
    {
        if ($userId === null) {
            $this->getAdapter()->query("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0")->execute();
        } else {
            $this->getAdapter()->query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
        }
    }

    public function notificationExists(string $type, int $relatedId, ?int $userId = null): bool
    {
        if ($userId === null) {
            $check = $this->getAdapter()->query("SELECT id FROM notifications WHERE type = ? AND related_id = ? AND user_id IS NULL")->execute([$type, $relatedId]);
        } else {
            $check = $this->getAdapter()->query("SELECT id FROM notifications WHERE type = ? AND related_id = ? AND user_id = ?")->execute([$type, $relatedId, $userId]);
        }
        return $check->count() > 0;
    }

    public function insertNotification(?int $userId, ?int $senderId, string $title, string $message, string $type, int $relatedId): void
    {
        $sql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) VALUES (?, ?, ?, ?, ?, ?)";
        $this->getAdapter()->query($sql)->execute([$userId, $senderId, $title, $message, $type, $relatedId]);
    }

    public function fetchRecentNotifications(?int $userId, int $limit = 15): array
    {
        if ($userId === null) {
            $sql = "SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT ?";
            $results = $this->getAdapter()->query($sql)->execute([$limit]);
        } else {
            $sql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
            $results = $this->getAdapter()->query($sql)->execute([$userId, $limit]);
        }
        return iterator_to_array($results);
    }

    public function getRecentPendingBorrows(int $limit = 5): array
    {
        $sqlBorrow = "SELECT r.*, b.title as book_title, u.full_name as student_name 
                      FROM borrow_records r 
                      JOIN books b ON r.book_id = b.book_id 
                      JOIN users u ON r.user_id = u.user_id 
                      WHERE r.status = 'pending' 
                      ORDER BY r.created_at DESC LIMIT ?";
        $results = $this->getAdapter()->query($sqlBorrow)->execute([$limit]);
        return iterator_to_array($results);
    }

    public function getRecentOpenTickets(int $limit = 5): array
    {
        $sqlTicket = "SELECT t.*, u.full_name as author_name 
                      FROM support_tickets t 
                      JOIN users u ON t.user_id = u.user_id 
                      WHERE t.status = 'open' 
                      ORDER BY t.created_at DESC LIMIT ?";
        $results = $this->getAdapter()->query($sqlTicket)->execute([$limit]);
        return iterator_to_array($results);
    }

    public function getRecentBorrowedByStudent(int $userId, int $limit = 5): array
    {
        $sqlBorrow = "SELECT r.*, b.title as book_title 
                      FROM borrow_records r 
                      JOIN books b ON r.book_id = b.book_id 
                      WHERE r.user_id = ? AND r.status = 'borrowed'
                      ORDER BY r.created_at DESC LIMIT ?";
        $results = $this->getAdapter()->query($sqlBorrow)->execute([$userId, $limit]);
        return iterator_to_array($results);
    }

    public function getRecentInProgressTicketsByStudent(int $userId, int $limit = 5): array
    {
        $sqlTicket = "SELECT t.* FROM support_tickets t 
                      WHERE t.user_id = ? AND t.status = 'in_progress'
                      ORDER BY t.updated_at DESC LIMIT ?";
        $results = $this->getAdapter()->query($sqlTicket)->execute([$userId, $limit]);
        return iterator_to_array($results);
    }
}

