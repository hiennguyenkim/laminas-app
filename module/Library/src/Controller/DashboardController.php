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

    public function __construct(
        AuthSessionContainer $authSessionContainer,
        BookTable $bookTable,
        BorrowTable $borrowTable,
        UserTable $userTable
    ) {
        parent::__construct($authSessionContainer);
        $this->bookTable   = $bookTable;
        $this->borrowTable = $borrowTable;
        $this->userTable   = $userTable;
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
}
