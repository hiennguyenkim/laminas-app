<?php

declare(strict_types=1);

namespace Library\Controller;

use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use Library\Model\Table\TicketTable;
use Library\Model\Table\TicketMessageTable;
use Library\Model\Table\NotificationTable;
use Library\Session\AuthSessionContainer;

class TicketController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private TicketTable $ticketTable,
        private TicketMessageTable $ticketMessageTable,
        private NotificationTable $notificationTable,
        private ?\Library\Service\MailService $mailService = null
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
        
        $page       = max(1, (int)($this->params()->fromQuery('page', 1)));
        $perPageRaw = $this->queryString('perPage', '20');
        if ($perPageRaw === 'all') {
            $perPage = 999999;
        } else {
            $perPage = (int)$perPageRaw;
            if (!in_array($perPage, [10, 20, 50, 100], true)) {
                $perPage = 20;
                $perPageRaw = '20';
            }
        }

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

        $totalCount = $this->ticketTable->countTickets($filters, (int)$currentUser['id'], $isAdmin);

        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $page       = min($page, $totalPages);

        $tickets = $this->ticketTable->fetchTickets($filters, (int)$currentUser['id'], $page, $perPage, $isAdmin);

        // Get count for the tabs
        // 1. Unanswered count
        $unansweredFilters = $filters;
        $unansweredFilters['status'] = 'unanswered';
        $unansweredCount = $this->ticketTable->countTickets($unansweredFilters, (int)$currentUser['id'], $isAdmin);

        // 2. Answered count
        $answeredFilters = $filters;
        $answeredFilters['status'] = 'answered';
        $answeredCount = $this->ticketTable->countTickets($answeredFilters, (int)$currentUser['id'], $isAdmin);

        // 3. Total with current search filter
        $allFilters = $filters;
        $allFilters['status'] = '';
        $allCount = $this->ticketTable->countTickets($allFilters, (int)$currentUser['id'], $isAdmin);

        $viewModel = new ViewModel([
            'tickets'         => $tickets,
            'isAdmin'         => $isAdmin,
            'filters'         => $filters,
            'unansweredCount' => $unansweredCount,
            'answeredCount'   => $answeredCount,
            'allCount'        => $allCount,
            'pagination'      => [
                'page'       => $page,
                'perPage'    => $perPageRaw,
                'totalItems' => $totalCount,
                'totalPages' => $totalPages,
            ]
        ]);

        if ($isAdmin) {
            $viewModel->setTemplate('library/ticket/index-admin');
        } else {
            $viewModel->setTemplate('library/ticket/index');
        }

        return $viewModel;
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
                $ticketId = $this->ticketTable->createTicket((int)$currentUser['id'], $title, $content);

                // schema: ticket_messages(ticket_id, sender_id, sender_role, message, sent_at)
                $senderRole = $currentUser['role'] === 'admin' ? 'admin' : 'user';
                $this->ticketMessageTable->insertMessage($ticketId, (int)$currentUser['id'], $senderRole, $content);

                // Gửi thông báo cho Admin
                try {
                    $this->notificationTable->insertNotification(
                        null,
                        (int)$currentUser['id'],
                        'Yêu cầu hỗ trợ mới',
                        "Độc giả <strong>" . htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) . "</strong> gửi ticket mới: <em>" . htmlspecialchars($title) . "</em>.",
                        'ticket',
                        $ticketId
                    );
                } catch (\Throwable $e) {}

                $this->flash()->addSuccessMessage('Đã gửi yêu cầu hỗ trợ thành công.');
                return $this->redirect()->toRoute($this->routeForRole('ticket'));
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

        $ticket = $this->ticketTable->getTicket($id);

        if (!$ticket) {
            $this->flash()->addErrorMessage('Không tìm thấy Ticket.');
            return $this->redirect()->toRoute($this->routeForRole('ticket'));
        }

        if (!$isAdmin && $ticket['user_id'] != $currentUser['id']) {
            $this->flash()->addErrorMessage('Bạn không có quyền xem Ticket này.');
            return $this->redirect()->toRoute($this->routeForRole('ticket'));
        }

        if ($this->httpRequest()->isPost()) {
            $data = $this->postData();
            $message = trim((string)($data['message'] ?? ''));
            $action = $data['action'] ?? 'reply';

            if ($action === 'close' && $isAdmin) {
                $this->ticketTable->closeTicket($id);

                // Gửi thông báo cho sinh viên
                try {
                    $this->notificationTable->insertNotification(
                        (int)$ticket['user_id'],
                        (int)$currentUser['id'],
                        'Yêu cầu hỗ trợ đã đóng',
                        "Thủ thư đã đóng ticket hỗ trợ: <em>" . htmlspecialchars($ticket['title']) . "</em>.",
                        'ticket_answered',
                        $id
                    );
                } catch (\Throwable $e) {}

                // Gửi email cho sinh viên
                if ($this->mailService !== null) {
                    try {
                        $subject = "[Thư viện HDPE] Yêu cầu hỗ trợ đã đóng";
                        $body = "Chào " . ($ticket['author_name'] ?? 'Độc giả') . ",\n\n"
                              . "Yêu cầu hỗ trợ của bạn về chủ đề '" . $ticket['title'] . "' đã được thủ thư đóng.\n\n"
                              . "Trân trọng,\n"
                              . "Thư viện HDPE";
                        $this->mailService->sendEmail((string)$ticket['author_email'], (string)($ticket['author_name'] ?? 'Độc giả'), $subject, $body);
                    } catch (\Throwable $e) {}
                }

                $this->flash()->addSuccessMessage('Đã đóng yêu cầu hỗ trợ.');
            }
            elseif ($message) {
                // schema: ticket_messages(ticket_id, sender_id, sender_role, message, sent_at)
                $senderRole = $isAdmin ? 'admin' : 'user';
                $this->ticketMessageTable->insertMessage($id, (int)$currentUser['id'], $senderRole, $message);
                
                $status = $isAdmin ? 'in_progress' : 'open';
                $this->ticketTable->updateStatus($id, $status);
                
                // Gửi thông báo
                try {
                    if ($isAdmin) {
                        $this->notificationTable->insertNotification(
                            (int)$ticket['user_id'],
                            (int)$currentUser['id'],
                            'Có phản hồi hỗ trợ',
                            "Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: <em>" . htmlspecialchars($ticket['title']) . "</em>.",
                            'ticket_answered',
                            $id
                        );
                    } else {
                        $isReopened = ($ticket['status'] === 'closed');
                        $title = $isReopened ? 'Ticket hỗ trợ đã mở lại' : 'Phản hồi hỗ trợ mới';
                        $msgText = $isReopened 
                            ? "Độc giả <strong>" . htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) . "</strong> đã mở lại ticket: <em>" . htmlspecialchars($ticket['title']) . "</em>."
                            : "Độc giả <strong>" . htmlspecialchars($currentUser['full_name'] ?? $currentUser['username']) . "</strong> đã phản hồi ticket: <em>" . htmlspecialchars($ticket['title']) . "</em>.";

                        $this->notificationTable->insertNotification(
                            null,
                            (int)$currentUser['id'],
                            $title,
                            $msgText,
                            'ticket',
                            $id
                        );
                    }
                } catch (\Throwable $e) {}

                // Gửi email cho sinh viên
                if ($isAdmin && $this->mailService !== null) {
                    try {
                        $subject = "[Thư viện HDPE] Có phản hồi hỗ trợ mới";
                        $body = "Chào " . ($ticket['author_name'] ?? 'Độc giả') . ",\n\n"
                              . "Thủ thư đã trả lời yêu cầu hỗ trợ của bạn: '" . $ticket['title'] . "'.\n"
                              . "Nội dung phản hồi:\n\n"
                              . $message . "\n\n"
                              . "Vui lòng đăng nhập vào tài khoản để xem chi tiết và phản hồi.\n\n"
                              . "Trân trọng,\n"
                              . "Thư viện HDPE";
                        $this->mailService->sendEmail((string)$ticket['author_email'], (string)($ticket['author_name'] ?? 'Độc giả'), $subject, $body);
                    } catch (\Throwable $e) {}
                }

                $this->flash()->addSuccessMessage('Đã gửi phản hồi.');
            }
            return $this->redirect()->toRoute($this->routeForRole('ticket'), ['action' => 'view', 'id' => $id]);
        }

        // schema: ticket_messages.sender_id → users.user_id, sort by sent_at
        $messages = $this->ticketMessageTable->fetchMessages($id);

        $viewModel = new ViewModel([
            'ticket' => $ticket,
            'messages' => $messages,
            'isAdmin' => $isAdmin,
            'currentUser' => $currentUser
        ]);

        if ($isAdmin) {
            $viewModel->setTemplate('library/ticket/view-admin');
        } else {
            $viewModel->setTemplate('library/ticket/view');
        }

        return $viewModel;
    }
}
