<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Form\BookForm;
use Library\Model\Entity\Book;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Session\AuthSessionContainer;
use Laminas\Form\FormElementManager;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use RuntimeException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class BookController extends BaseController
{
    private const PER_PAGE = 10;

    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private BookTable $bookTable,
        private BorrowTable $borrowTable,
        private FormElementManager $formElementManager,
        private ?\Laminas\Db\Adapter\AdapterInterface $dbAdapter = null
    ) {
        parent::__construct($authSessionContainer);
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    public function indexAction(): Response|ViewModel
    {
        $routeMatch = $this->getEvent()->getRouteMatch();
        $matchedRoute = $routeMatch?->getMatchedRouteName() ?? 'library/book';
        $isPublicCatalog = $matchedRoute === 'catalog';

        // Update: permit view/review in indexAction? No indexAction handles both catalog and admin index.
        if (! $isPublicCatalog && ($response = $this->requireLogin())) {
            // Actually, we don't need to change indexAction, viewAction and reviewAction are separate.
            return $response;
        }

        $currentUser = $this->currentUser();
        $isGuest = $currentUser === null;
        $isStudent = $currentUser !== null && ($currentUser['role'] ?? '') === 'student';
        if ($isGuest) {
            $layout = $this->layout();
            if (method_exists($layout, 'setVariable')) {
                $layout->setVariable('guestCatalogMode', true);
            }
        }

        $statusFilter = $this->queryString('status');
        if ($isGuest || $isStudent) {
            $statusFilter = 'available';
        }

        $filters = [
            'search'   => trim($this->queryString('search')),
            'category' => $this->queryString('category'),
            'status'   => $statusFilter,
        ];

        $page = (int) $this->queryString('page', '1');
        $page = max(1, $page);

        $totalItems = $this->bookTable->countFiltered($filters);
        $totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
        $page = min($page, $totalPages);

        $announcements = [];
        if ($this->dbAdapter) {
            try {
                $sql = 'SELECT * FROM announcements WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY created_at DESC LIMIT 5';
                $statement = $this->dbAdapter->query($sql);
                $announcements = $statement->execute();
                $announcements = iterator_to_array($announcements);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return new ViewModel([
            'books'      => $this->bookTable->fetchPage($filters, $page, self::PER_PAGE),
            'filters'    => $filters,
            'categories' => $this->bookTable->fetchCategories(),
            'summary'    => $this->bookTable->getSummary(),
            'canManage'  => $this->isAdmin(),
            'canBorrow'  => ($currentUser['role'] ?? '') === 'student',
            'isGuest'    => $isGuest,
            'indexRoute' => $isPublicCatalog ? 'catalog' : ($this->isAdmin() ? 'library/book' : 'student/book'),
            'pagination' => [
                'page'       => $page,
                'perPage'    => self::PER_PAGE,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
            'announcements' => $announcements,
        ]);
    }

    public function viewAction(): Response|ViewModel
    {
        $currentUser = $this->currentUser();
        $isGuest = $currentUser === null;
        $canManage = ($currentUser['role'] ?? '') === 'admin';

        $id = $this->routeInt('id');

        try {
            $book = $this->bookTable->getBook($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());

            return $this->redirect()->toRoute('library/book');
        }

        // Fetch reviews
        $reviews = [];
        if ($this->dbAdapter) {
            try {
                $sql = 'SELECT r.*, u.full_name, u.role FROM book_reviews r JOIN users u ON r.user_id = u.user_id WHERE r.book_id = ? ORDER BY r.created_at DESC';
                $statement = $this->dbAdapter->query($sql);
                $reviews = iterator_to_array($statement->execute([$id]));
            } catch (\Exception $e) {}
        }

        return new ViewModel([
            'book'            => $book,
            'hasActiveBorrow' => $this->borrowTable->hasActiveBorrowForBook($id),
            'currentUser'     => $currentUser,
            'canManage'       => $canManage,
            'reviews'         => $reviews,
        ]);
    }

    public function importAction(): Response
    {
        $this->flash()->addInfoMessage('Nhập sách được tích hợp trực tiếp trong form Thêm sách.');
        return $this->redirect()->toRoute('library/book', ['action' => 'add']);
    }

    public function addAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $form = $this->formElementManager->get(BookForm::class);
        if (! $form instanceof BookForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu sách.');
        }

        if ($this->httpRequest()->isPost()) {
            $form->setData($this->postData());
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                $book = new Book();
                $book->exchangeArray($data);
                $this->bookTable->saveBook($book);
                $this->flash()->addSuccessMessage('Đã thêm sách "' . $book->title . '" vào thư viện.');
                return $this->redirect()->toRoute('library/book');
            }
        }

        $view = new ViewModel(['form' => $form, 'mode' => 'add']);
        $view->setTemplate('library/book/form');

        return $view;
    }

    public function editAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id   = $this->routeInt('id');
        try {
            $book = $this->bookTable->getBook($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());

            return $this->redirect()->toRoute('library/book');
        }

        $form = $this->formElementManager->get(BookForm::class);
        if (! $form instanceof BookForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu sách.');
        }
        $form->bind($book);

        if ($this->httpRequest()->isPost()) {
            $form->setData($this->postData());
            if ($form->isValid()) {
                $this->bookTable->saveBook($book);
                $this->flash()->addSuccessMessage('Đã cập nhật thông tin sách.');
                return $this->redirect()->toRoute('library/book');
            }
        }

        $view = new ViewModel(['form' => $form, 'mode' => 'edit', 'book' => $book]);
        $view->setTemplate('library/book/form');

        return $view;
    }

    public function deleteAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/book');
        }

        $id = $this->routeInt('id');

        if ($this->borrowTable->hasActiveBorrowForBook($id)) {
            $this->flash()->addErrorMessage('Không thể xóa sách vì vẫn còn lượt mượn chưa trả.');

            return $this->redirect()->toRoute('library/book');
        }

        $this->bookTable->deleteBook($id);
        $this->flash()->addSuccessMessage('Đã xóa sách khỏi thư viện.');
        return $this->redirect()->toRoute('library/book');
    }

    public function reviewAction(): Response
    {
        $currentUser = $this->currentUser();
        if ($currentUser === null || $currentUser['role'] !== 'student') {
            $this->flash()->addErrorMessage('Chỉ sinh viên mới có thể đánh giá sách.');
            return $this->redirect()->toRoute('library/book');
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/book');
        }

        $bookId = $this->routeInt('id');
        $rating = (int)($this->postData()['rating'] ?? 5);
        $comment = trim((string)($this->postData()['comment'] ?? ''));
        $userId = $currentUser['id'];

        if ($this->dbAdapter) {
            try {
                // Kiểm tra 1: Đã từng mượn sách này chưa?
                $sqlHistory = "SELECT COUNT(*) as cnt
                               FROM borrow_records
                               WHERE user_id = ? AND book_id = ?
                               AND status = 'returned'";
                $hasBorrowed = $this->dbAdapter
                    ->query($sqlHistory, [$userId, $bookId])
                    ->current()['cnt'] > 0;

                if (!$hasBorrowed) {
                    $this->flash()->addErrorMessage('Bạn chỉ có thể đánh giá sách sau khi đã mượn và trả.');
                    return $this->redirect()->toRoute('library/book', ['action' => 'view', 'id' => $bookId]);
                }

                // Kiểm tra 2: Đã review cuốn này chưa?
                $sqlDup = "SELECT COUNT(*) as cnt
                           FROM book_reviews
                           WHERE user_id = ? AND book_id = ?";
                $hasReviewed = $this->dbAdapter
                    ->query($sqlDup, [$userId, $bookId])
                    ->current()['cnt'] > 0;

                if ($hasReviewed) {
                    $this->flash()->addErrorMessage('Bạn đã đánh giá cuốn sách này rồi.');
                    return $this->redirect()->toRoute('library/book', ['action' => 'view', 'id' => $bookId]);
                }

                $sql = 'INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())';
                $this->dbAdapter->query($sql, [$bookId, $currentUser['id'], $rating, $comment]);
                $this->flash()->addSuccessMessage('Cảm ơn bạn đã đánh giá cuốn sách này!');
            } catch (\Exception $e) {
                $this->flash()->addErrorMessage('Có lỗi xảy ra khi gửi đánh giá: ' . $e->getMessage());
            }
        }

        return $this->redirect()->toRoute('library/book', ['action' => 'view', 'id' => $bookId]);
    }
}
