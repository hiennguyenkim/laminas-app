<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Model\Entity\Book;
use Library\Model\Entity\User;
use Library\Form\BorrowForm;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Library\Service\CirculationService;
use Laminas\Form\FormElementManager;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use RuntimeException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class TransactionController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private BorrowTable $borrowTable,
        private BookTable $bookTable,
        private UserTable $userTable,
        private CirculationService $circulationService,
        private FormElementManager $formElementManager
    ) {
        parent::__construct($authSessionContainer);
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $isAdmin     = $this->isAdmin();
        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $filters     = [
            'search' => trim($this->queryString('search')),
            'status' => $this->queryString('status'),
        ];

        if ($isAdmin) {
            $filters['user_id'] = $this->queryString('user_id');
        }

        return new ViewModel([
            'records'     => $this->borrowTable->fetchAllWithDetails($filters, $isAdmin ? null : $userId),
            'summary'     => $this->borrowTable->getSummary($isAdmin ? null : $userId),
            'filters'     => $filters,
            'isAdmin'     => $isAdmin,
            'currentUser' => $currentUser,
            'users'       => $isAdmin ? $this->userTable->fetchStudentOptions() : [],
        ]);
    }

    public function borrowAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $isAdmin = $this->isAdmin();
        $currentUser = $this->currentUser() ?? [];
        $currentUserId = $currentUser['id'] ?? 0;

        // Build dropdown options
        $bookOptions = [];
        foreach ($this->bookTable->fetchAll() as $book) {
            if (! $book instanceof Book) {
                continue;
            }

            if ($book->quantity > 0 && $book->status !== 'unavailable') {
                $bookOptions[$book->id] = $book->title . ' (còn ' . $book->quantity . ')';
            }
        }

        $userOptions = [];
        if ($isAdmin) {
            foreach ($this->userTable->fetchStudentOptions() as $user) {
                if (! $user instanceof User) {
                    continue;
                }

                $userOptions[$user->id] = $user->fullName . ' (@' . $user->username . ')';
            }
        } elseif ($currentUserId > 0) {
            $currentFullName = $currentUser['full_name'] ?? 'Sinh viên';
            $currentUsername = $currentUser['username'] ?? 'student';
            $userOptions[$currentUserId] = $currentFullName . ' (@' . $currentUsername . ')';
        }

        $form = $this->formElementManager->get(BorrowForm::class);
        if (! $form instanceof BorrowForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu phiếu mượn.');
        }
        $form->setSelectionOptions($bookOptions, $userOptions);

        $prefillBookId = (int) $this->queryString('book_id');

        if ($this->httpRequest()->isPost()) {
            $postData = $this->postData();
            if (! $isAdmin) {
                $postData['user_id'] = (string) $currentUserId;
            }

            $form->setData($postData);
            if ($form->isValid()) {
                /** @var array{book_id:string|int, user_id:string|int, borrow_date:string, return_date:string} $data */
                $data   = $form->getData();
                $bookId = (int) $data['book_id'];
                $userId = $isAdmin ? (int) $data['user_id'] : $currentUserId;

                if ($data['return_date'] < $data['borrow_date']) {
                    $form->get('return_date')->setMessages([
                        'Hạn trả phải sau hoặc bằng ngày mượn.',
                    ]);

                    return new ViewModel([
                        'form' => $form,
                        'isAdmin' => $isAdmin,
                        'currentUser' => $currentUser,
                    ]);
                }

                try {
                    $this->circulationService->borrowBook(
                        $bookId,
                        $userId,
                        $data['borrow_date'],
                        $data['return_date']
                    );
                    $this->flash()->addSuccessMessage('Lập phiếu mượn thành công.');
                    return $this->redirect()->toRoute('library/transaction');
                } catch (\Throwable $e) {
                    $this->flash()->addErrorMessage($e->getMessage());
                }
            }
        } else {
            if ($prefillBookId > 0 && array_key_exists($prefillBookId, $bookOptions)) {
                $form->get('book_id')->setValue((string) $prefillBookId);
            }

            if (! $isAdmin && $currentUserId > 0) {
                $form->get('user_id')->setValue((string) $currentUserId);
            }
        }

        return new ViewModel([
            'form' => $form,
            'isAdmin' => $isAdmin,
            'currentUser' => $currentUser,
        ]);
    }

    public function returnAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/transaction');
        }

        try {
            $this->circulationService->returnBook($this->routeInt('id'));
            $this->flash()->addSuccessMessage('Đã ghi nhận trả sách thành công.');
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/transaction');
    }
}
