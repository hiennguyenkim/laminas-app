<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Library\Session\AuthSessionContainer;
use Library\Model\Table\NotificationTable;

class NotificationApiController extends AbstractActionController
{
    public function __construct(
        private AuthSessionContainer $authSessionContainer,
        private NotificationTable $notificationTable
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

        // Auto cleanup old read notifications (older than 30 days) - Run with 10% chance to save performance
        if (mt_rand(1, 10) === 1) {
            try {
                $this->notificationTable->cleanupOldNotifications(30);
            } catch (\Throwable $e) {}
        }

        // Fetch first admin ID to set as sender_id for system/automatic notifications
        $adminId = null;
        try {
            $adminId = $this->notificationTable->findAdminId();
        } catch (\Throwable $e) {}

        // Auto scan overdue books
        try {
            $overdueRecords = $this->notificationTable->getOverdueRecords();
            foreach ($overdueRecords as $record) {
                $borrowId = (int)$record['borrow_id'];
                $rUserId = (int)$record['user_id'];
                
                // 1. Update status to overdue
                $this->notificationTable->updateOverdueRecordStatus($borrowId);
                
                // 2. Check if warning notification already exists
                if (!$this->notificationTable->warningNotificationExists($borrowId)) {
                    $this->notificationTable->insertNotification(
                        $rUserId,
                        $adminId,
                        'Sách quá hạn trả',
                        "Cuốn sách '" . $record['book_title'] . "' đã quá hạn trả vào ngày " . $record['return_date'] . ". Vui lòng trả sách sớm.",
                        'borrow_alert',
                        $borrowId
                    );
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
                        $this->notificationTable->deleteNotification($id, null);
                    } else {
                        $this->notificationTable->deleteNotification($id, $userId);
                    }
                }
            } else {
                if ($idStr && strpos($idStr, 'db_') === 0) {
                    $id = (int) substr($idStr, 3);
                    if ($isAdmin) {
                        $this->notificationTable->markAsRead($id, null);
                    } else {
                        $this->notificationTable->markAsRead($id, $userId);
                    }
                } else {
                    // Mark all as read
                    if ($isAdmin) {
                        $this->notificationTable->markAllAsRead(null);
                    } else {
                        $this->notificationTable->markAllAsRead($userId);
                    }
                }
            }
            return $this->jsonResponse(['success' => true]);
        }

        if ($isAdmin) {
            // Auto-insert pending borrow requests as notifications (if not already there)
            $results = $this->notificationTable->getRecentPendingBorrows(50);
            foreach ($results as $row) {
                if (!$this->notificationTable->notificationExists('borrow', (int)$row['borrow_id'], null)) {
                    $this->notificationTable->insertNotification(
                        null,
                        (int)$row['user_id'],
                        'Yêu cầu mượn sách mới',
                        "Sinh viên <strong>" . htmlspecialchars($row['student_name']) . "</strong> vừa đăng ký mượn cuốn <strong>" . htmlspecialchars($row['book_title']) . "</strong>.",
                        'borrow',
                        (int)$row['borrow_id']
                    );
                }
            }

            // Auto-insert open tickets as notifications (if not already there)
            $results = $this->notificationTable->getRecentOpenTickets(50);
            foreach ($results as $row) {
                if (!$this->notificationTable->notificationExists('ticket', (int)$row['id'], null)) {
                    $this->notificationTable->insertNotification(
                        null,
                        (int)$row['user_id'],
                        'Yêu cầu hỗ trợ mới',
                        "Độc giả <strong>" . htmlspecialchars($row['author_name']) . "</strong> gửi ticket mới: <em>" . htmlspecialchars($row['title']) . "</em>.",
                        'ticket',
                        (int)$row['id']
                    );
                }
            }
        } else {
            // Auto-insert approved borrow records as notifications (if not already there)
            $results = $this->notificationTable->getRecentBorrowedByStudent($userId, 20);
            foreach ($results as $row) {
                if (!$this->notificationTable->notificationExists('borrow_approved', (int)$row['borrow_id'], $userId)) {
                    $this->notificationTable->insertNotification(
                        $userId,
                        $adminId,
                        'Phiếu mượn đã được duyệt',
                        "Cuốn sách <strong>" . htmlspecialchars($row['book_title']) . "</strong> của bạn đã được thủ thư phê duyệt thành công!",
                        'borrow_approved',
                        (int)$row['borrow_id']
                    );
                }
            }

            // Auto-insert answered tickets as notifications (if not already there)
            $results = $this->notificationTable->getRecentInProgressTicketsByStudent($userId, 20);
            foreach ($results as $row) {
                if (!$this->notificationTable->notificationExists('ticket_answered', (int)$row['id'], $userId)) {
                    $this->notificationTable->insertNotification(
                        $userId,
                        $adminId,
                        'Có phản hồi hỗ trợ',
                        "Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>" . htmlspecialchars($row['title']) . "</em>.",
                        'ticket_answered',
                        (int)$row['id']
                    );
                }
            }
        }

        $notifications = [];

        // 1. Fetch persistent notifications from DB
        if ($isAdmin) {
            $dbResults = $this->notificationTable->fetchRecentNotifications(null, 15);
        } else {
            $dbResults = $this->notificationTable->fetchRecentNotifications($userId, 15);
        }

        foreach ($dbResults as $row) {
            $url = $this->url()->fromRoute($isAdmin ? 'library/transaction' : 'student/transaction');
            $type = $row['type'];

            if ($type === 'ticket' || $type === 'ticket_answered') {
                $url = $this->url()->fromRoute($isAdmin ? 'library/ticket' : 'student/ticket', ['action' => 'view', 'id' => $row['related_id']]);
            } elseif ($type === 'borrow_approved' && $row['title'] === 'Bảng tin mới') {
                // Announcement notification (using borrow_approved as type for now)
                $url = $this->url()->fromRoute('announcements/view', ['id' => $row['related_id']]);
            } elseif ($type === 'borrow_approved' && $row['title'] === 'Tài khoản đã được phê duyệt') {
                $url = $this->url()->fromRoute($isAdmin ? 'library/profile' : 'student/profile');
            } elseif ($type === 'borrow_alert' && strpos($row['title'], 'Tài khoản') !== false) {
                $url = $this->url()->fromRoute($isAdmin ? 'library/profile' : 'student/profile');
            }

            $notifications[] = [
                'id'          => 'db_' . $row['id'],
                'title'       => $row['title'],
                'description' => $row['message'],
                'url'         => $url,
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
