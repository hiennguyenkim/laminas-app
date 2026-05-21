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
            'recentBorrows'  => $this->borrowTable->fetchAllWithDetails([], $isAdmin ? null : $userId, 6),
            'monthlyStats'   => $this->borrowTable->getMonthlyStats((int) date('Y'), $isAdmin ? null : $userId),
            'categoryStats'  => $this->bookTable->getCategoryStats($isAdmin ? null : $userId),
            'isLocked'       => $isLocked,
            'lockReason'     => $lockReason,
            'lockedAt'       => $lockedAt,
        ]);
    }

    public function chatAction(): Response
    {
        $currentUser = $this->currentUser();

        if ($this->getRequest()->isPost()) {
            if (!$currentUser) {
                return $this->jsonResponse(['error' => 'Unauthorized'], 401);
            }
            $data = $this->postData();
            $action = trim((string)($data['action'] ?? ''));

            if ($action === 'delete') {
                $messageId = (int)($data['id'] ?? 0);
                if ($messageId <= 0) {
                    return $this->jsonResponse(['error' => 'Invalid message ID'], 400);
                }
                $isAdmin = ($currentUser['role'] ?? '') === 'admin';
                $userId = (int)$currentUser['id'];

                if ($isAdmin) {
                    $sql = "DELETE FROM public_chats WHERE id = ?";
                    $this->dbAdapter->query($sql, [$messageId]);
                } else {
                    $sql = "DELETE FROM public_chats WHERE id = ? AND user_id = ?";
                    $this->dbAdapter->query($sql, [$messageId, $userId]);
                }
                return $this->jsonResponse(['success' => true]);
            }

            if ($action === 'pin') {
                $messageId = (int)($data['id'] ?? 0);
                if ($messageId <= 0) {
                    return $this->jsonResponse(['error' => 'Invalid message ID'], 400);
                }
                $isAdmin = ($currentUser['role'] ?? '') === 'admin';
                if (!$isAdmin) {
                    return $this->jsonResponse(['error' => 'Forbidden'], 403);
                }

                // Unpin everything first
                $this->dbAdapter->query("UPDATE public_chats SET is_pinned = 0", []);
                // Pin the target message
                $this->dbAdapter->query("UPDATE public_chats SET is_pinned = 1 WHERE id = ?", [$messageId]);

                return $this->jsonResponse(['success' => true]);
            }

            if ($action === 'unpin') {
                $isAdmin = ($currentUser['role'] ?? '') === 'admin';
                if (!$isAdmin) {
                    return $this->jsonResponse(['error' => 'Forbidden'], 403);
                }

                // Unpin everything
                $this->dbAdapter->query("UPDATE public_chats SET is_pinned = 0", []);

                return $this->jsonResponse(['success' => true]);
            }

            if ($action === 'react') {
                $messageId = (int)($data['id'] ?? 0);
                $emoji = trim((string)($data['emoji'] ?? ''));
                if ($messageId <= 0 || $emoji === '') {
                    return $this->jsonResponse(['error' => 'Invalid parameters'], 400);
                }

                // Fetch message
                $sql = "SELECT reactions FROM public_chats WHERE id = ?";
                $stmt = $this->dbAdapter->query($sql);
                $row = $stmt->execute([$messageId])->current();
                if (!$row) {
                    return $this->jsonResponse(['error' => 'Message not found'], 404);
                }

                $reactionsStr = $row['reactions'] ?? '';
                $reactions = [];
                if ($reactionsStr !== '') {
                    $reactions = json_decode($reactionsStr, true) ?? [];
                }

                $userId = (int)$currentUser['id'];

                // Toggle logic
                if (!isset($reactions[$emoji])) {
                    $reactions[$emoji] = [];
                }

                $userIndex = array_search($userId, $reactions[$emoji]);
                if ($userIndex !== false) {
                    // User already reacted with this emoji, remove it
                    unset($reactions[$emoji][$userIndex]);
                    $reactions[$emoji] = array_values($reactions[$emoji]); // reindex
                    if (empty($reactions[$emoji])) {
                        unset($reactions[$emoji]);
                    }
                } else {
                    // Add user reaction
                    $reactions[$emoji][] = $userId;
                }

                $newReactionsStr = json_encode($reactions, JSON_UNESCAPED_UNICODE);
                $sql = "UPDATE public_chats SET reactions = ? WHERE id = ?";
                $this->dbAdapter->query($sql, [$newReactionsStr, $messageId]);

                return $this->jsonResponse(['success' => true]);
            }

            // Normal chat insert action
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

        // Fetch last 50 messages joining with users to get nickname securely
        $sql = "SELECT c.*, COALESCE(NULLIF(u.nickname, ''), u.full_name, CONCAT('Độc giả #', u.user_id)) AS nickname, u.role, u.avatar_url 
                FROM public_chats c 
                JOIN users u ON c.user_id = u.user_id 
                ORDER BY c.created_at ASC 
                LIMIT 50";
        $results = iterator_to_array($this->dbAdapter->query($sql)->execute());

        // Format for display
        $formattedResults = array_map(function($row) {
            return [
                'id'         => $row['id'],
                'user_id'    => $row['user_id'],
                'nickname'   => $row['nickname'],
                'message'    => $row['message'],
                'role'       => $row['role'] ?? 'student',
                'avatar_url' => $row['avatar_url'] ?? '',
                'is_pinned'  => (int)($row['is_pinned'] ?? 0),
                'reactions'  => $row['reactions'] ?? '',
                'created_at' => date('H:i', strtotime($row['created_at']))
            ];
        }, $results);

        return $this->jsonResponse($formattedResults);
    }

    public function borrowedAction(): Response
    {
        return $this->redirect()->toRoute('library/transaction', [], ['query' => ['status' => 'borrowed']]);
    }

    public function overdueAction(): Response
    {
        return $this->redirect()->toRoute('library/transaction', [], ['query' => ['status' => 'overdue']]);
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
