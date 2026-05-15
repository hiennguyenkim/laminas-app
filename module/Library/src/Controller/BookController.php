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
        private FormElementManager $formElementManager
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

        if (! $isPublicCatalog && ($response = $this->requireLogin())) {
            return $response;
        }

        $currentUser = $this->currentUser();
        $isGuest = $currentUser === null;
        if ($isGuest) {
            $layout = $this->layout();
            if (method_exists($layout, 'setVariable')) {
                $layout->setVariable('guestCatalogMode', true);
            }
        }

        $statusFilter = $this->queryString('status');
        if ($isGuest && $statusFilter === '') {
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

        return new ViewModel([
            'books'      => $this->bookTable->fetchPage($filters, $page, self::PER_PAGE),
            'filters'    => $filters,
            'categories' => $this->bookTable->fetchCategories(),
            'summary'    => $this->bookTable->getSummary(),
            'canManage'  => $this->isAdmin(),
            'canBorrow'  => ($currentUser['role'] ?? '') === 'student',
            'isGuest'    => $isGuest,
            'indexRoute' => $isPublicCatalog ? 'catalog' : 'library/book',
            'pagination' => [
                'page'       => $page,
                'perPage'    => self::PER_PAGE,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    public function viewAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = $this->routeInt('id');

        try {
            $book = $this->bookTable->getBook($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());

            return $this->redirect()->toRoute('library/book');
        }

        return new ViewModel([
            'book'            => $book,
            'hasActiveBorrow' => $this->borrowTable->hasActiveBorrowForBook($id),
        ]);
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
}
