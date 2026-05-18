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
        $currentUser = $this->authSessionContainer->getCurrentUser();
        if (!$currentUser) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        $isAdmin = ($currentUser['role'] ?? '') === 'admin';
        $userId = (int) ($currentUser['id'] ?? 0);
        $notifications = [];

        if ($isAdmin) {
            // 1. Pending Borrows
            $sqlBorrow = "SELECT r.*, b.title as book_title, u.full_name as student_name 
                          FROM borrow_records r 
                          JOIN books b ON r.book_id = b.book_id 
                          JOIN users u ON r.user_id = u.user_id 
                          WHERE r.status = 'pending' 
                          ORDER BY r.created_at DESC 
                          LIMIT 5";
            $statement = $this->dbAdapter->query($sqlBorrow);
            $results = $statement->execute();
            foreach ($results as $row) {
                $notifications[] = [
                    'id'          => 'borrow_' . $row['borrow_id'],
                    'title'       => 'Yêu cầu mượn sách mới',
                    'description' => "Sinh viên <strong>" . htmlspecialchars($row['student_name']) . "</strong> vừa đăng ký mượn cuốn <strong>" . htmlspecialchars($row['book_title']) . "</strong>.",
                    'url'         => $this->url()->fromRoute('library/transaction'),
                    'time'        => $this->formatTimeElapsed($row['created_at']),
                    'type'        => 'borrow'
                ];
            }

            // 2. Open Tickets
            $sqlTicket = "SELECT t.*, u.full_name as author_name 
                          FROM support_tickets t 
                          JOIN users u ON t.user_id = u.user_id 
                          WHERE t.status = 'open' 
                          ORDER BY t.created_at DESC 
                          LIMIT 5";
            $statement = $this->dbAdapter->query($sqlTicket);
            $results = $statement->execute();
            foreach ($results as $row) {
                $notifications[] = [
                    'id'          => 'ticket_' . $row['id'],
                    'title'       => 'Yêu cầu hỗ trợ mới',
                    'description' => "Độc giả <strong>" . htmlspecialchars($row['author_name']) . "</strong> gửi ticket mới: <em>" . htmlspecialchars($row['title']) . "</em>.",
                    'url'         => $this->url()->fromRoute('library/ticket', ['action' => 'view', 'id' => $row['id']]),
                    'time'        => $this->formatTimeElapsed($row['created_at']),
                    'type'        => 'ticket'
                ];
            }
        } else {
            // For Student:
            // 1. Approved Borrows (status = borrowed)
            $sqlBorrow = "SELECT r.*, b.title as book_title 
                          FROM borrow_records r 
                          JOIN books b ON r.book_id = b.book_id 
                          WHERE r.user_id = ? AND r.status = 'borrowed' 
                          ORDER BY r.created_at DESC 
                          LIMIT 5";
            $statement = $this->dbAdapter->query($sqlBorrow);
            $results = $statement->execute([$userId]);
            foreach ($results as $row) {
                $notifications[] = [
                    'id'          => 'borrow_app_' . $row['borrow_id'],
                    'title'       => 'Phiếu mượn đã được duyệt',
                    'description' => "Cuốn sách <strong>" . htmlspecialchars($row['book_title']) . "</strong> của bạn đã được thủ thư phê duyệt thành công!",
                    'url'         => $this->url()->fromRoute('library/transaction'),
                    'time'        => $this->formatTimeElapsed($row['created_at']),
                    'type'        => 'borrow_approved'
                ];
            }

            // 2. Answered Support Tickets (status = in_progress)
            $sqlTicket = "SELECT t.* 
                          FROM support_tickets t 
                          WHERE t.user_id = ? AND t.status = 'in_progress' 
                          ORDER BY t.updated_at DESC 
                          LIMIT 5";
            $statement = $this->dbAdapter->query($sqlTicket);
            $results = $statement->execute([$userId]);
            foreach ($results as $row) {
                $notifications[] = [
                    'id'          => 'ticket_ans_' . $row['id'],
                    'title'       => 'Có phản hồi hỗ trợ',
                    'description' => "Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>" . htmlspecialchars($row['title']) . "</em>.",
                    'url'         => $this->url()->fromRoute('library/ticket', ['action' => 'view', 'id' => $row['id']]),
                    'time'        => $this->formatTimeElapsed($row['updated_at']),
                    'type'        => 'ticket_answered'
                ];
            }
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
