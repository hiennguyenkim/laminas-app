<?php

declare(strict_types=1);

namespace Library\Controller;

use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;
use Library\Session\AuthSessionContainer;

class TicketController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private AdapterInterface $dbAdapter
    ) {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $currentUser = $this->currentUser();
        $isAdmin = $currentUser['role'] === 'admin';
        
        $tickets = [];
        if ($isAdmin) {
            $sql = "SELECT t.*, u.full_name as author_name FROM support_tickets t JOIN users u ON t.user_id = u.user_id ORDER BY t.status DESC, t.updated_at DESC";
            $statement = $this->dbAdapter->query($sql);
            $tickets = iterator_to_array($statement->execute());
        } else {
            $sql = "SELECT t.*, u.full_name as author_name FROM support_tickets t JOIN users u ON t.user_id = u.user_id WHERE t.user_id = ? ORDER BY t.status DESC, t.updated_at DESC";
            $statement = $this->dbAdapter->query($sql);
            $tickets = iterator_to_array($statement->execute([$currentUser['id']]));
        }

        return new ViewModel([
            'tickets' => $tickets,
            'isAdmin' => $isAdmin
        ]);
    }

    public function createAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        if ($this->httpRequest()->isPost()) {
            $data = $this->postData();
            $title   = trim((string)($data['subject'] ?? ''));   // form field 'subject' → DB col 'title'
            $content = trim((string)($data['content'] ?? ''));
            $currentUser = $this->currentUser();

            if ($title && $content) {
                // schema: support_tickets(user_id, category, title, description, status, ...)
                $sql = "INSERT INTO support_tickets (user_id, title, description, status, created_at, updated_at) VALUES (?, ?, ?, 'open', NOW(), NOW())";
                $this->dbAdapter->query($sql, [$currentUser['id'], $title, $content]);
                $ticketId = $this->dbAdapter->getDriver()->getLastGeneratedValue();

                // schema: ticket_messages(ticket_id, sender_id, sender_role, message, sent_at)
                $senderRole = $currentUser['role'] === 'admin' ? 'admin' : 'user';
                $msgSql = "INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, ?, ?, NOW())";
                $this->dbAdapter->query($msgSql, [$ticketId, $currentUser['id'], $senderRole, $content]);

                // Gửi thông báo cho Admin
                try {
                    $notiSql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                                VALUES (NULL, ?, 'Yêu cầu hỗ trợ mới', ?, 'ticket', ?)";
                    $this->dbAdapter->query($notiSql, [
                        $currentUser['id'],
                        "Độc giả <strong>" . htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) . "</strong> gửi ticket mới: <em>" . htmlspecialchars($title) . "</em>.",
                        $ticketId
                    ]);
                } catch (\Throwable $e) {}

                $this->flash()->addSuccessMessage('Đã gửi yêu cầu hỗ trợ thành công.');
                return $this->redirect()->toRoute('library/ticket');
            }
            $this->flash()->addErrorMessage('Vui lòng nhập đầy đủ Tiêu đề và Nội dung.');
        }

        return new ViewModel();
    }

    public function viewAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $id = $this->routeInt('id');
        $currentUser = $this->currentUser();
        $isAdmin = $currentUser['role'] === 'admin';

        $sql = "SELECT t.*, u.full_name as author_name FROM support_tickets t JOIN users u ON t.user_id = u.user_id WHERE t.id = ?";
        $statement = $this->dbAdapter->query($sql);
        $result = $statement->execute([$id]);
        $ticket = $result->current();

        if (!$ticket) {
            $this->flash()->addErrorMessage('Không tìm thấy Ticket.');
            return $this->redirect()->toRoute('library/ticket');
        }

        if (!$isAdmin && $ticket['user_id'] != $currentUser['id']) {
            $this->flash()->addErrorMessage('Bạn không có quyền xem Ticket này.');
            return $this->redirect()->toRoute('library/ticket');
        }

        if ($this->httpRequest()->isPost()) {
            $data = $this->postData();
            $message = trim((string)($data['message'] ?? ''));
            $action = $data['action'] ?? 'reply';

            if ($action === 'close' && $isAdmin) {
                $this->dbAdapter->query("UPDATE support_tickets SET status = 'closed', updated_at = NOW() WHERE id = ?", [$id]);
                $this->flash()->addSuccessMessage('Đã đóng yêu cầu hỗ trợ.');
            } elseif ($message) {
                // schema: ticket_messages(ticket_id, sender_id, sender_role, message, sent_at)
                $senderRole = $isAdmin ? 'admin' : 'user';
                $this->dbAdapter->query("INSERT INTO ticket_messages (ticket_id, sender_id, sender_role, message, sent_at) VALUES (?, ?, ?, ?, NOW())", [$id, $currentUser['id'], $senderRole, $message]);
                
                $status = $isAdmin ? 'in_progress' : 'open';
                $this->dbAdapter->query("UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?", [$status, $id]);
                
                // Gửi thông báo
                try {
                    if ($isAdmin) {
                        $notiSql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                                    VALUES (?, ?, 'Có phản hồi hỗ trợ', ?, 'ticket_answered', ?)";
                        $this->dbAdapter->query($notiSql, [
                            $ticket['user_id'],
                            $currentUser['id'],
                            "Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>" . htmlspecialchars($ticket['title']) . "</em>.",
                            $id
                        ]);
                    } else {
                        $notiSql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                                    VALUES (NULL, ?, 'Phản hồi hỗ trợ mới', ?, 'ticket', ?)";
                        $this->dbAdapter->query($notiSql, [
                            $currentUser['id'],
                            "Độc giả <strong>" . htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) . "</strong> đã phản hồi ticket: <em>" . htmlspecialchars($ticket['title']) . "</em>.",
                            $id
                        ]);
                    }
                } catch (\Throwable $e) {}

                $this->flash()->addSuccessMessage('Đã gửi phản hồi.');
            }
            return $this->redirect()->toRoute('library/ticket', ['action' => 'view', 'id' => $id]);
        }

        // schema: ticket_messages.sender_id → users.user_id, sort by sent_at
        $msgSql = "SELECT m.*, u.full_name, u.role FROM ticket_messages m JOIN users u ON m.sender_id = u.user_id WHERE m.ticket_id = ? ORDER BY m.sent_at ASC";
        $messages = iterator_to_array($this->dbAdapter->query($msgSql)->execute([$id]));

        return new ViewModel([
            'ticket' => $ticket,
            'messages' => $messages,
            'isAdmin' => $isAdmin,
            'currentUser' => $currentUser
        ]);
    }
}
