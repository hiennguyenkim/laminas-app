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
        
        $page    = max(1, (int)($this->params()->fromQuery('page', 1)));
        $perPage = 10;

        $search = trim($this->queryString('search'));
        $status = trim($this->queryString('status'));
        $sort   = trim((string)$this->queryString('sort'));
        $direction = strtoupper(trim((string)$this->queryString('direction')));
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        $filters = [
            'search'    => $search,
            'status'    => $status,
            'sort'      => $sort,
            'direction' => strtolower($direction),
        ];

        $where = [];
        $params = [];

        // Role filtering
        if (!$isAdmin) {
            $where[] = "t.user_id = ?";
            $params[] = $currentUser['id'];
        }

        // Status filtering (2 states: unanswered vs answered)
        if ($status === 'unanswered') {
            $where[] = "t.status = 'open'";
        } elseif ($status === 'answered') {
            $where[] = "t.status IN ('in_progress', 'closed')";
        }

        // Search filtering
        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            if ($isAdmin) {
                $where[] = "(t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            } else {
                $where[] = "(t.title LIKE ? OR t.description LIKE ?)";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        $countSql = "SELECT COUNT(*) as cnt FROM support_tickets t JOIN users u ON t.user_id = u.user_id $whereClause";
        $totalCount = (int)(($this->dbAdapter->query($countSql)->execute($params)->current()['cnt']) ?? 0);

        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $allowedSorts = [
            'id' => 't.id',
            'title' => 't.title',
            'author' => 'u.full_name',
            'updated_at' => 't.updated_at',
            'status' => 't.status',
        ];

        $orderBy = 't.status DESC, t.updated_at DESC';
        if (array_key_exists($sort, $allowedSorts)) {
            $orderBy = $allowedSorts[$sort] . ' ' . $direction;
        }

        $sql = "SELECT t.*, u.full_name as author_name FROM support_tickets t JOIN users u ON t.user_id = u.user_id $whereClause ORDER BY $orderBy LIMIT ? OFFSET ?";
        
        $fetchParams = $params;
        $fetchParams[] = $perPage;
        $fetchParams[] = $offset;

        $tickets = iterator_to_array($this->dbAdapter->query($sql)->execute($fetchParams));

        // Get count for the tabs
        // 1. Unanswered count
        $unansweredWhere = [];
        $unansweredParams = [];
        if (!$isAdmin) {
            $unansweredWhere[] = "t.user_id = ?";
            $unansweredParams[] = $currentUser['id'];
        }
        $unansweredWhere[] = "t.status = 'open'";
        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            if ($isAdmin) {
                $unansweredWhere[] = "(t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
                $unansweredParams[] = $searchTerm;
                $unansweredParams[] = $searchTerm;
                $unansweredParams[] = $searchTerm;
            } else {
                $unansweredWhere[] = "(t.title LIKE ? OR t.description LIKE ?)";
                $unansweredParams[] = $searchTerm;
                $unansweredParams[] = $searchTerm;
            }
        }
        $unansweredWhereClause = "WHERE " . implode(" AND ", $unansweredWhere);
        $unansweredCountSql = "SELECT COUNT(*) as cnt FROM support_tickets t JOIN users u ON t.user_id = u.user_id $unansweredWhereClause";
        $unansweredCount = (int)(($this->dbAdapter->query($unansweredCountSql)->execute($unansweredParams)->current()['cnt']) ?? 0);

        // 2. Answered count
        $answeredWhere = [];
        $answeredParams = [];
        if (!$isAdmin) {
            $answeredWhere[] = "t.user_id = ?";
            $answeredParams[] = $currentUser['id'];
        }
        $answeredWhere[] = "t.status IN ('in_progress', 'closed')";
        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            if ($isAdmin) {
                $answeredWhere[] = "(t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
                $answeredParams[] = $searchTerm;
                $answeredParams[] = $searchTerm;
                $answeredParams[] = $searchTerm;
            } else {
                $answeredWhere[] = "(t.title LIKE ? OR t.description LIKE ?)";
                $answeredParams[] = $searchTerm;
                $answeredParams[] = $searchTerm;
            }
        }
        $answeredWhereClause = "WHERE " . implode(" AND ", $answeredWhere);
        $answeredCountSql = "SELECT COUNT(*) as cnt FROM support_tickets t JOIN users u ON t.user_id = u.user_id $answeredWhereClause";
        $answeredCount = (int)(($this->dbAdapter->query($answeredCountSql)->execute($answeredParams)->current()['cnt']) ?? 0);

        // 3. Total with current search filter
        $allWhere = [];
        $allParams = [];
        if (!$isAdmin) {
            $allWhere[] = "t.user_id = ?";
            $allParams[] = $currentUser['id'];
        }
        if ($search !== '') {
            $searchTerm = '%' . $search . '%';
            if ($isAdmin) {
                $allWhere[] = "(t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
                $allParams[] = $searchTerm;
                $allParams[] = $searchTerm;
                $allParams[] = $searchTerm;
            } else {
                $allWhere[] = "(t.title LIKE ? OR t.description LIKE ?)";
                $allParams[] = $searchTerm;
                $allParams[] = $searchTerm;
            }
        }
        $allWhereClause = !empty($allWhere) ? "WHERE " . implode(" AND ", $allWhere) : "";
        $allCountSql = "SELECT COUNT(*) as cnt FROM support_tickets t JOIN users u ON t.user_id = u.user_id $allWhereClause";
        $allCount = (int)(($this->dbAdapter->query($allCountSql)->execute($allParams)->current()['cnt']) ?? 0);

        return new ViewModel([
            'tickets'         => $tickets,
            'isAdmin'         => $isAdmin,
            'page'            => $page,
            'totalPages'      => $totalPages,
            'totalCount'      => $totalCount,
            'perPage'         => $perPage,
            'filters'         => $filters,
            'unansweredCount' => $unansweredCount,
            'answeredCount'   => $answeredCount,
            'allCount'        => $allCount,
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
