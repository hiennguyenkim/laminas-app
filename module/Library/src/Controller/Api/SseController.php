<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractActionController;
use Library\Session\AuthSessionContainer;
use Library\Model\Table\NotificationTable;
use Library\Model\Table\PublicChatTable;

class SseController extends AbstractActionController
{
    public function __construct(
        private AuthSessionContainer $authSessionContainer,
        private NotificationTable $notificationTable,
        private PublicChatTable $publicChatTable
    ) {
    }

    public function chatAction(): Response
    {
        $response = $this->getResponse();
        assert($response instanceof Response);

        // Set Headers for Server-Sent Events
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $currentUser = $this->authSessionContainer->user ?? null;
        session_write_close();
        if (!$currentUser) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
            flush();
            return $response;
        }

        $lastChatHash = '';

        // Force connection handling
        while (connection_status() == CONNECTION_NORMAL) {
            $results = [];
            try {
                $results = $this->publicChatTable->fetchRecentMessages(50);
            } catch (\Throwable $e) {}

            $formattedResults = array_map(function($row) {
                $timestamp = strtotime($row['created_at']);
                $dateLabel = '';
                if (date('Y-m-d', $timestamp) !== date('Y-m-d')) {
                    $dateLabel = ' ' . date('d/m', $timestamp);
                }
                return [
                    'id'         => $row['id'],
                    'user_id'    => $row['user_id'],
                    'nickname'   => $row['nickname'],
                    'message'    => $row['message'],
                    'role'       => $row['role'] ?? 'student',
                    'avatar_url' => $row['avatar_url'] ?? '',
                    'is_pinned'  => (int)($row['is_pinned'] ?? 0),
                    'reactions'  => $row['reactions'] ?? '',
                    'created_at' => date('H:i', $timestamp),
                    'date_label' => $dateLabel,
                ];
            }, $results);

            // Fetch pinned message
            $formattedPinned = null;
            try {
                $pinnedResult = $this->publicChatTable->getPinnedMessage();
                if ($pinnedResult) {
                    $timestamp = strtotime($pinnedResult['created_at']);
                    $dateLabel = '';
                    if (date('Y-m-d', $timestamp) !== date('Y-m-d')) {
                        $dateLabel = ' ' . date('d/m', $timestamp);
                    }
                    $formattedPinned = [
                        'id'         => $pinnedResult['id'],
                        'user_id'    => $pinnedResult['user_id'],
                        'nickname'   => $pinnedResult['nickname'],
                        'message'    => $pinnedResult['message'],
                        'role'       => $pinnedResult['role'] ?? 'student',
                        'avatar_url' => $pinnedResult['avatar_url'] ?? '',
                        'is_pinned'  => (int)($pinnedResult['is_pinned'] ?? 0),
                        'reactions'  => $pinnedResult['reactions'] ?? '',
                        'created_at' => date('H:i', $timestamp),
                        'date_label' => $dateLabel,
                    ];
                }
            } catch (\Throwable $e) {}

            $currentHash = md5(json_encode([$formattedResults, $formattedPinned]));
            if ($currentHash !== $lastChatHash) {
                echo "data: " . json_encode(['messages' => $formattedResults, 'pinned' => $formattedPinned], JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastChatHash = $currentHash;
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            sleep(1);
        }

        return $response;
    }

    public function notificationAction(): Response
    {
        $response = $this->getResponse();
        assert($response instanceof Response);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $currentUser = $this->authSessionContainer->user ?? null;
        session_write_close();
        if (!$currentUser) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
            flush();
            return $response;
        }

        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        $userId = (int) ($currentUser['id'] ?? 0);

        $lastNotificationHash = '';

        while (connection_status() == CONNECTION_NORMAL) {
            $this->triggerNotificationGeneration($userId, $isAdmin);

            $dbResults = [];
            try {
                if ($isAdmin) {
                    $dbResults = $this->notificationTable->fetchRecentNotifications(null, 15);
                } else {
                    $dbResults = $this->notificationTable->fetchRecentNotifications($userId, 15);
                }
            } catch (\Throwable $e) {}

            $notifications = [];
            foreach ($dbResults as $row) {
                $url = $this->url()->fromRoute($isAdmin ? 'library/transaction' : 'student/transaction');
                $type = $row['type'];

                if ($type === 'ticket' || $type === 'ticket_answered') {
                    $url = $this->url()->fromRoute($isAdmin ? 'library/ticket' : 'student/ticket', ['action' => 'view', 'id' => $row['related_id']]);
                } elseif ($type === 'borrow_approved' && $row['title'] === 'Bảng tin mới') {
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

            $currentHash = md5(json_encode($notifications));
            if ($currentHash !== $lastNotificationHash) {
                echo "data: " . json_encode($notifications, JSON_UNESCAPED_UNICODE) . "\n\n";
                $lastNotificationHash = $currentHash;
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            sleep(2);
        }

        return $response;
    }

    private function triggerNotificationGeneration(int $userId, bool $isAdmin): void
    {
        // Suppress output
        try {
            $adminId = $this->notificationTable->findAdminId();
            
            // Overdue scan
            $overdueRecords = $this->notificationTable->getOverdueRecords();
            foreach ($overdueRecords as $record) {
                $borrowId = (int)$record['borrow_id'];
                $rUserId = (int)$record['user_id'];
                $this->notificationTable->updateOverdueRecordStatus($borrowId);
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

            if ($isAdmin) {
                // Pending borrow alerts
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

                // Open tickets
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
                // Approved borrow alerts
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

                // Answered tickets
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
        } catch (\Throwable $e) {}
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
}
