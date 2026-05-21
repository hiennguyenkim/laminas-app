<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Db\Adapter\AdapterInterface;
use Library\Session\AuthSessionContainer;

class NotificationApiController extends AbstractActionController
{
    public function __construct(
        private AuthSessionContainer $authSessionContainer,
        private AdapterInterface $dbAdapter
    ) {
    }

    public function indexAction(): Response
    {
        $currentUser = $this->authSessionContainer->user ?? null;
        if (!$currentUser) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        $userId = (int) ($currentUser['id'] ?? 0);

        // Fetch first admin ID to set as sender_id for system/automatic notifications
        $adminId = null;
        try {
            $adminRow = $this->dbAdapter->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1")->execute()->current();
            if ($adminRow) {
                $adminId = (int)$adminRow['user_id'];
            }
        } catch (\Throwable $e) {}

        // Auto scan overdue books
        try {
            $sqlScan = "SELECT r.*, b.title as book_title 
                        FROM borrow_records r 
                        JOIN books b ON r.book_id = b.book_id 
                        WHERE r.status IN ('borrowed', 'overdue') AND r.return_date < CURDATE()";
            $overdueRecords = $this->dbAdapter->query($sqlScan)->execute();
            foreach ($overdueRecords as $record) {
                $borrowId = (int)$record['borrow_id'];
                $rUserId = (int)$record['user_id'];
                
                // 1. Update status to overdue
                $this->dbAdapter->query("UPDATE borrow_records SET status = 'overdue' WHERE borrow_id = ?")->execute([$borrowId]);
                
                // 2. Check if warning notification already exists
                $check = $this->dbAdapter->query("SELECT id FROM notifications WHERE type = 'borrow_alert' AND related_id = ?")->execute([$borrowId]);
                if ($check->count() === 0) {
                    $this->dbAdapter->query(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                         VALUES (?, ?, 'Sách quá hạn trả', ?, 'borrow_alert', ?)",
                        [
                            $rUserId,
                            $adminId,
                            "Cuốn sách '" . $record['book_title'] . "' đã quá hạn trả vào ngày " . $record['return_date'] . ". Vui lòng trả sách sớm.",
                            $borrowId
                        ]
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        if ($this->getRequest()->isPost()) {
            $rawBody = $this->getRequest()->getContent();
            $params = json_decode($rawBody, true) ?: [];
            $idStr = $params['id'] ?? null;
            $action = $params['action'] ?? null;

            if ($action === 'delete') {
                if ($idStr && strpos($idStr, 'db_') === 0) {
                    $id = (int) substr($idStr, 3);
                    if ($isAdmin) {
                        $this->dbAdapter->query("DELETE FROM notifications WHERE id = ? AND user_id IS NULL")->execute([$id]);
                    } else {
                        $this->dbAdapter->query("DELETE FROM notifications WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
                    }
                }
            } else {
                if ($idStr && strpos($idStr, 'db_') === 0) {
                    $id = (int) substr($idStr, 3);
                    if ($isAdmin) {
                        $this->dbAdapter->query("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id IS NULL")->execute([$id]);
                    } else {
                        $this->dbAdapter->query("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $userId]);
                    }
                } else {
                    // Mark all as read
                    if ($isAdmin) {
                        $this->dbAdapter->query("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0")->execute();
                    } else {
                        $this->dbAdapter->query("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$userId]);
                    }
                }
            }
            return $this->jsonResponse(['success' => true]);
        }

        if ($isAdmin) {
            // Auto-insert pending borrow requests as notifications (if not already there)
            $sqlBorrow = "SELECT r.*, b.title as book_title, u.full_name as student_name 
                          FROM borrow_records r 
                          JOIN books b ON r.book_id = b.book_id 
                          JOIN users u ON r.user_id = u.user_id 
                          WHERE r.status = 'pending' 
                          ORDER BY r.created_at DESC LIMIT 5";
            $results = $this->dbAdapter->query($sqlBorrow)->execute();
            foreach ($results as $row) {
                $check = $this->dbAdapter->query(
                    "SELECT id FROM notifications WHERE type = 'borrow' AND related_id = ? AND user_id IS NULL"
                )->execute([$row['borrow_id']]);
                if ($check->count() === 0) {
                    $this->dbAdapter->query(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id)
                         VALUES (NULL, ?, 'Yêu cầu mượn sách mới', ?, 'borrow', ?)"
                    )->execute([
                        $row['user_id'],
                        "Sinh viên <strong>" . htmlspecialchars($row['student_name']) . "</strong> vừa đăng ký mượn cuốn <strong>" . htmlspecialchars($row['book_title']) . "</strong>.",
                        $row['borrow_id']
                    ]);
                }
            }

            // Auto-insert open tickets as notifications (if not already there)
            $sqlTicket = "SELECT t.*, u.full_name as author_name 
                          FROM support_tickets t 
                          JOIN users u ON t.user_id = u.user_id 
                          WHERE t.status = 'open' 
                          ORDER BY t.created_at DESC LIMIT 5";
            $results = $this->dbAdapter->query($sqlTicket)->execute();
            foreach ($results as $row) {
                $check = $this->dbAdapter->query(
                    "SELECT id FROM notifications WHERE type = 'ticket' AND related_id = ? AND user_id IS NULL"
                )->execute([$row['id']]);
                if ($check->count() === 0) {
                    $this->dbAdapter->query(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id)
                         VALUES (NULL, ?, 'Yêu cầu hỗ trợ mới', ?, 'ticket', ?)"
                    )->execute([
                        $row['user_id'],
                        "Độc giả <strong>" . htmlspecialchars($row['author_name']) . "</strong> gửi ticket mới: <em>" . htmlspecialchars($row['title']) . "</em>.",
                        $row['id']
                    ]);
                }
            }
        } else {
            // Auto-insert approved borrow records as notifications (if not already there)
            $sqlBorrow = "SELECT r.*, b.title as book_title 
                          FROM borrow_records r 
                          JOIN books b ON r.book_id = b.book_id 
                          WHERE r.user_id = ? AND r.status = 'borrowed'
                          ORDER BY r.created_at DESC LIMIT 5";
            $results = $this->dbAdapter->query($sqlBorrow)->execute([$userId]);
            foreach ($results as $row) {
                $check = $this->dbAdapter->query(
                    "SELECT id FROM notifications WHERE type = 'borrow_approved' AND related_id = ? AND user_id = ?"
                )->execute([$row['borrow_id'], $userId]);
                if ($check->count() === 0) {
                    $this->dbAdapter->query(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id)
                         VALUES (?, ?, 'Phiếu mượn đã được duyệt', ?, 'borrow_approved', ?)"
                    )->execute([
                        $userId,
                        $adminId,
                        "Cuốn sách <strong>" . htmlspecialchars($row['book_title']) . "</strong> của bạn đã được thủ thư phê duyệt thành công!",
                        $row['borrow_id']
                    ]);
                }
            }

            // Auto-insert answered tickets as notifications (if not already there)
            $sqlTicket = "SELECT t.* FROM support_tickets t 
                          WHERE t.user_id = ? AND t.status = 'in_progress'
                          ORDER BY t.updated_at DESC LIMIT 5";
            $results = $this->dbAdapter->query($sqlTicket)->execute([$userId]);
            foreach ($results as $row) {
                $check = $this->dbAdapter->query(
                    "SELECT id FROM notifications WHERE type = 'ticket_answered' AND related_id = ? AND user_id = ?"
                )->execute([$row['id'], $userId]);
                if ($check->count() === 0) {
                    $this->dbAdapter->query(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id)
                         VALUES (?, ?, 'Có phản hồi hỗ trợ', ?, 'ticket_answered', ?)"
                    )->execute([
                        $userId,
                        $adminId,
                        "Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>" . htmlspecialchars($row['title']) . "</em>.",
                        $row['id']
                    ]);
                }
            }
        }

        $notifications = [];

        // 1. Fetch persistent notifications from DB
        if ($isAdmin) {
            $dbSql = "SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 15";
            $statement = $this->dbAdapter->query($dbSql);
            $dbResults = $statement->execute();
        } else {
            $dbSql = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 15";
            $statement = $this->dbAdapter->query($dbSql);
            $dbResults = $statement->execute([$userId]);
        }

        foreach ($dbResults as $row) {
            $notifications[] = [
                'id'          => 'db_' . $row['id'],
                'title'       => $row['title'],
                'description' => $row['message'],
                'url'         => $row['type'] === 'ticket' || $row['type'] === 'ticket_answered' 
                                 ? $this->url()->fromRoute($isAdmin ? 'library/ticket' : 'student/ticket', ['action' => 'view', 'id' => $row['related_id']])
                                 : $this->url()->fromRoute($isAdmin ? 'library/transaction' : 'student/transaction'),
                'time'        => $this->formatTimeElapsed($row['created_at']),
                'type'        => $row['type'],
                'is_read'     => (int) $row['is_read']
            ];
        }

        return $this->jsonResponse($notifications);
    }

    private function formatTimeElapsed(string $datetime): string
    {
        $time = strtotime($datetime);
        if (!$time) {
            return 'Vừa xong';
        }
        $diff = time() - $time;
        if ($diff < 60) {
            return 'Vừa xong';
        }
        $diff = (int) round($diff / 60);
        if ($diff < 60) {
            return $diff . ' phút trước';
        }
        $diff = (int) round($diff / 60);
        if ($diff < 24) {
            return $diff . ' giờ trước';
        }
        $diff = (int) round($diff / 24);
        return $diff . ' ngày trước';
    }

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->getResponse();
        if (! $response instanceof Response) {
            throw new \RuntimeException('Unexpected response instance.');
        }

        $response->setStatusCode($statusCode);
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        return $response;
    }
}
