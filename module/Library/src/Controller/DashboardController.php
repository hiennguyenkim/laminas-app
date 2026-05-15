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
            'categoryStats'  => $isAdmin ? $this->bookTable->getCategoryStats() : [],
        ]);
    }
}
