<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class DashboardController extends BaseController
{
    private BookTable $bookTable;
    private BorrowTable $borrowTable;
    private UserTable $userTable;
    private \Laminas\Db\Adapter\AdapterInterface $dbAdapter;

    public function __construct(
        AuthSessionContainer $authSessionContainer,
        BookTable $bookTable,
        BorrowTable $borrowTable,
        UserTable $userTable,
        \Laminas\Db\Adapter\AdapterInterface $dbAdapter
    ) {
        parent::__construct($authSessionContainer);
        $this->bookTable   = $bookTable;
        $this->borrowTable = $borrowTable;
        $this->userTable   = $userTable;
        $this->dbAdapter   = $dbAdapter;
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $isAdmin     = $this->isAdmin();
        $bookSummary = $this->bookTable->getSummary();
        $loanSummary = $this->borrowTable->getSummary($isAdmin ? null : $userId);

        $isLocked = false;
        $lockReason = '';
        $lockedAt = '';
        if (! $isAdmin && $userId > 0) {
            try {
                $userObj = $this->userTable->getUser($userId);
                $isLocked = $userObj->isLocked();
                $lockReason = $userObj->lockReason;
                $lockedAt = $userObj->lockedAt;
            } catch (\Throwable $e) {}
        }

        return new ViewModel([
            'isAdmin'        => $isAdmin,
            'currentUser'    => $currentUser,
            'bookSummary'    => $bookSummary,
            'loanSummary'    => $loanSummary,
            'totalBooks'     => $bookSummary['total_titles'],
            'totalBorrowed'  => $loanSummary['borrowed'],
            'totalOverdue'   => $loanSummary['overdue'],
            'totalReturned'  => $loanSummary['returned'],
            'dueSoon'        => $loanSummary['due_soon'],
            'totalMembers'   => $isAdmin ? $this->userTable->countByRole('student') : 0,
            'recentBorrows'  => $this->borrowTable->fetchAllWithDetails([], $isAdmin ? null : $userId, 10),
            'monthlyStats'   => $this->borrowTable->getMonthlyStats((int) date('Y')),
            'categoryStats'  => $this->bookTable->getCategoryStats(),
            'isLocked'       => $isLocked,
            'lockReason'     => $lockReason,
            'lockedAt'       => $lockedAt,
        ]);
    }

    public function chatAction(): Response
    {
        $currentUser = $this->currentUser();
        if (!$currentUser) {
            return $this->jsonResponse(['error' => 'Unauthorized'], 401);
        }

        if ($this->getRequest()->isPost()) {
            $data = $this->postData();
            $message = trim((string)($data['message'] ?? ''));
            if ($message === '') {
                return $this->jsonResponse(['error' => 'Message cannot be empty'], 400);
            }
            if (mb_strlen($message) > 255) {
                return $this->jsonResponse(['error' => 'Message is too long'], 400);
            }

            $userId = (int)$currentUser['id'];
            $sql = "INSERT INTO public_chats (user_id, message, created_at) VALUES (?, ?, NOW())";
            $this->dbAdapter->query($sql, [$userId, $message]);

            return $this->jsonResponse(['success' => true]);
        }

        // Fetch last 50 messages joining with users to get nickname/fullname/username
        $sql = "SELECT c.*, COALESCE(NULLIF(u.nickname, ''), NULLIF(u.full_name, ''), u.username) AS nickname, u.role 
                FROM public_chats c 
                JOIN users u ON c.user_id = u.user_id 
                ORDER BY c.created_at ASC 
                LIMIT 50";
        $results = iterator_to_array($this->dbAdapter->query($sql)->execute());

        // Format timestamps for display
        $formattedResults = array_map(function($row) {
            return [
                'id'         => $row['id'],
                'nickname'   => $row['nickname'] ?? 'Độc giả',
                'message'    => $row['message'],
                'role'       => $row['role'] ?? 'student',
                'created_at' => date('H:i', strtotime($row['created_at']))
            ];
        }, $results);

        return $this->jsonResponse($formattedResults);
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
