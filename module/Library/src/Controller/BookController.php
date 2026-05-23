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

        $searchQuery = trim((string)$this->queryString('search', ''));
        if ($searchQuery === '') {
            $searchQuery = trim((string)$this->queryString('q', ''));
        }

        $filters = [
            'search'   => $searchQuery,
            'category' => $this->queryString('category'),
            'status'   => $statusFilter,
        ];

        $perPageRaw = $this->queryString('perPage', '10');
        if ($perPageRaw === 'all') {
            $perPage = 999999;
        } else {
            $perPage = (int) $perPageRaw;
            if (! in_array($perPage, [10, 20, 50], true)) {
                $perPage = 10;
                $perPageRaw = '10';
            }
        }

        $page = (int) $this->queryString('page', '1');
        $page = max(1, $page);

        $totalItems = $this->bookTable->countFiltered($filters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        $announcements = [];
        if ($this->dbAdapter) {
            try {
                $sql = 'SELECT * FROM announcements WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY created_at DESC LIMIT 3';
                $statement = $this->dbAdapter->query($sql);
                $announcements = $statement->execute();
                $announcements = iterator_to_array($announcements);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return new ViewModel([
            'books'      => $this->bookTable->fetchPage($filters, $page, $perPage),
            'filters'    => $filters,
            'categories' => array_keys($this->getCategoryOptions()),
            'summary'    => $this->bookTable->getSummary(),
            'canManage'  => $this->isAdmin(),
            'canBorrow'  => ($currentUser['role'] ?? '') === 'student',
            'isGuest'    => $isGuest,
            'indexRoute' => $isPublicCatalog ? 'catalog' : ($this->isAdmin() ? 'library/book' : 'student/book'),
            'pagination' => [
                'page'       => $page,
                'perPage'    => $perPageRaw,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
            'announcements' => $announcements,
            'categoriesWithId' => $this->getCategoriesWithId(),
        ]);
    }

    public function viewAction(): Response|ViewModel
    {
        $currentUser = $this->currentUser();
        $isGuest = $currentUser === null;
        $canManage = ($currentUser['role'] ?? '') === 'admin';

        if ($isGuest) {
            $layout = $this->layout();
            if (method_exists($layout, 'setVariable')) {
                $layout->setVariable('guestCatalogMode', true);
            }
        }

        $id = $this->routeInt('id');

        try {
            $book = $this->bookTable->getBook($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());
            $route = $currentUser ? $this->routeForRole('book') : 'catalog';

            return $this->redirect()->toRoute($route);
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

    private function handleCoverUpload(?string $existingUrl = null): ?string
    {
        $file = $_FILES['cover_image'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $maxSize = 2 * 1024 * 1024; // 2 MB
        if ($file['size'] > $maxSize) {
            $this->flash()->addWarningMessage('File ảnh bìa quá lớn. Tối đa 2MB. Sử dụng ảnh mặc định.');
            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
        if (!in_array($ext, $allowed)) {
            $this->flash()->addWarningMessage('Định dạng ảnh bìa không hỗ trợ. Chỉ chấp nhận: ' . implode(', ', $allowed));
            return null;
        }

        $uploadDir = getcwd() . '/public/img/uploads/covers/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Delete existing local cover if it exists
        if ($existingUrl && str_starts_with($existingUrl, '/img/uploads/covers/')) {
            $oldPath = getcwd() . '/public' . $existingUrl;
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $newFilename = 'cover_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
            return '/img/uploads/covers/' . $newFilename;
        }

        return null;
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
        $form->get('category')->setValueOptions($this->getCategoryOptions());

        if ($this->httpRequest()->isPost()) {
            $form->setData($this->postData());
            if ($form->isValid()) {
                /** @var array<string, mixed> $data */
                $data = $form->getData();
                $book = new Book();
                $book->exchangeArray($data);

                // Handle file upload
                $uploadedUrl = $this->handleCoverUpload();
                if ($uploadedUrl) {
                    $book->coverImageUrl = $uploadedUrl;
                }

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
        $form->get('category')->setValueOptions($this->getCategoryOptions());
        $form->bind($book);

        if ($this->httpRequest()->isPost()) {
            $form->setData($this->postData());
            if ($form->isValid()) {
                $oldCoverUrl = $book->coverImageUrl;

                // Form binding automatically updates $book properties with form inputs
                // So $book->coverImageUrl has the value from $form->get('cover_image_url')
                // If a new cover image file is uploaded, handle it and override coverImageUrl
                $uploadedUrl = $this->handleCoverUpload($oldCoverUrl);
                if ($uploadedUrl) {
                    $book->coverImageUrl = $uploadedUrl;
                }

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

        try {
            $book = $this->bookTable->getBook($id);
            if ($book->coverImageUrl && str_starts_with($book->coverImageUrl, '/img/uploads/covers/')) {
                $oldPath = getcwd() . '/public' . $book->coverImageUrl;
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }
        } catch (\Exception $e) {}

        $this->bookTable->deleteBook($id);
        $this->flash()->addSuccessMessage('Đã xóa sách khỏi thư viện.');
        return $this->redirect()->toRoute('library/book');
    }

    public function reviewAction(): Response
    {
        $currentUser = $this->currentUser();
        if ($currentUser === null || $currentUser['role'] !== 'student') {
            $this->flash()->addErrorMessage('Chỉ sinh viên mới có thể đánh giá sách.');
            $route = $currentUser ? $this->routeForRole('book') : 'catalog';
            return $this->redirect()->toRoute($route);
        }

        $route = $this->routeForRole('book');

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute($route);
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
                    return $this->redirect()->toRoute($route, ['action' => 'view', 'id' => $bookId]);
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
                    return $this->redirect()->toRoute($route, ['action' => 'view', 'id' => $bookId]);
                }

                $sql = 'INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())';
                $this->dbAdapter->query($sql, [$bookId, $currentUser['id'], $rating, $comment]);
                $this->flash()->addSuccessMessage('Cảm ơn bạn đã đánh giá cuốn sách này!');
            } catch (\Exception $e) {
                $this->flash()->addErrorMessage('Có lỗi xảy ra khi gửi đánh giá: ' . $e->getMessage());
            }
        }

        return $this->redirect()->toRoute($route, ['action' => 'view', 'id' => $bookId]);
    }

    public function announcementsAction(): ViewModel|Response
    {
        $currentUser = $this->currentUser();
        $isAdmin = $currentUser !== null && ($currentUser['role'] ?? '') === 'admin';
        $isStudent = $currentUser !== null && ($currentUser['role'] ?? '') === 'student';

        $routeMatch = $this->getEvent()->getRouteMatch();
        $matchedRouteName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';

        if ($isAdmin && $matchedRouteName !== 'library/announcements') {
            return $this->redirect()->toRoute('library/announcements');
        }
        if ($isStudent && $matchedRouteName !== 'student/announcements') {
            return $this->redirect()->toRoute('student/announcements');
        }
        if ($currentUser === null && $matchedRouteName !== 'announcements') {
            return $this->redirect()->toRoute('announcements');
        }

        $baseRoute = $matchedRouteName;

        if ($currentUser === null) {
            $layout = $this->layout();
            if (method_exists($layout, 'setVariable')) {
                $layout->setVariable('guestCatalogMode', true);
            }
        }

        if ($isAdmin) {
            $page    = max(1, (int)($this->params()->fromQuery('page', 1)));
            $perPage = 5;

            $search = trim($this->queryString('search'));
            $type   = trim($this->queryString('type'));
            $status = trim($this->queryString('status'));

            $filters = [
                'search' => $search,
                'type'   => $type,
                'status' => $status,
            ];

            // Global stats
            $globalCounts = $this->dbAdapter->query(
                "SELECT 
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN start_date IS NOT NULL AND start_date > CURDATE() THEN 1 ELSE 0 END) AS upcoming_count,
                    SUM(CASE WHEN end_date IS NOT NULL AND end_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
                    SUM(CASE WHEN is_active = 0 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) AS hidden_count
                 FROM announcements"
            )->execute()->current();
            
            $activeCount = (int)($globalCounts['active_count'] ?? 0);
            $upcomingCount = (int)($globalCounts['upcoming_count'] ?? 0);
            $expiredCount = (int)($globalCounts['expired_count'] ?? 0);
            $hiddenCount = (int)($globalCounts['hidden_count'] ?? 0);

            $where = [];
            $params = [];

            // Type filter
            $allowedTypes = ['event', 'contest', 'holiday', 'general'];
            if ($type !== '' && in_array($type, $allowedTypes, true)) {
                $where[] = "a.type = ?";
                $params[] = $type;
            }

            // Status filter
            if ($status === 'active') {
                $where[] = "a.is_active = 1 AND (a.start_date IS NULL OR a.start_date <= CURDATE()) AND (a.end_date IS NULL OR a.end_date >= CURDATE())";
            } elseif ($status === 'upcoming') {
                $where[] = "a.start_date IS NOT NULL AND a.start_date > CURDATE()";
            } elseif ($status === 'expired') {
                $where[] = "a.end_date IS NOT NULL AND a.end_date < CURDATE()";
            } elseif ($status === 'hidden') {
                $where[] = "a.is_active = 0 AND (a.start_date IS NULL OR a.start_date <= CURDATE()) AND (a.end_date IS NULL OR a.end_date >= CURDATE())";
            }

            // Search filter
            if ($search !== '') {
                $where[] = "(a.title LIKE ? OR a.content LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

            // Filtered counts
            $countSql = "SELECT COUNT(*) as cnt FROM announcements a $whereClause";
            $totalCount = (int)(($this->dbAdapter->query($countSql)->execute($params)->current()['cnt']) ?? 0);

            $totalPages  = max(1, (int)ceil($totalCount / $perPage));
            $page        = min($page, $totalPages);
            $offset      = ($page - 1) * $perPage;

            $announcements = [];
            if ($totalCount > 0) {
                $announcements = iterator_to_array(
                    $this->dbAdapter->query(
                        "SELECT a.*, u.full_name AS creator_name
                         FROM announcements a
                         LEFT JOIN users u ON a.created_by = u.user_id
                         $whereClause
                         ORDER BY a.created_at DESC
                         LIMIT ? OFFSET ?"
                    )->execute(array_merge($params, [$perPage, $offset]))
                );
            }

            // Get counts for each type matching current search keyword and status filter
            $allCountWhere = [];
            $allCountParams = [];
            if ($search !== '') {
                $allCountWhere[] = "(title LIKE ? OR content LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $allCountParams[] = $searchTerm;
                $allCountParams[] = $searchTerm;
            }
            if ($status === 'active') {
                $allCountWhere[] = "is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())";
            } elseif ($status === 'upcoming') {
                $allCountWhere[] = "start_date IS NOT NULL AND start_date > CURDATE()";
            } elseif ($status === 'expired') {
                $allCountWhere[] = "end_date IS NOT NULL AND end_date < CURDATE()";
            } elseif ($status === 'hidden') {
                $allCountWhere[] = "is_active = 0 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())";
            }
            $allCountWhereClause = !empty($allCountWhere) ? "WHERE " . implode(" AND ", $allCountWhere) : "";

            $totalFilteredCount = (int)(($this->dbAdapter->query("SELECT COUNT(*) as cnt FROM announcements $allCountWhereClause")->execute($allCountParams)->current()['cnt']) ?? 0);

            $typeCountsRaw = iterator_to_array($this->dbAdapter->query("SELECT type, COUNT(*) as cnt FROM announcements $allCountWhereClause GROUP BY type")->execute($allCountParams));
            $typeCounts = [
                'general' => 0,
                'event'   => 0,
                'contest' => 0,
                'holiday' => 0,
            ];
            foreach ($typeCountsRaw as $row) {
                if (isset($typeCounts[$row['type']])) {
                    $typeCounts[$row['type']] = (int)$row['cnt'];
                }
            }

            $viewModel = new ViewModel([
                'announcements'      => $announcements,
                'activeCount'        => $activeCount,
                'upcomingCount'      => $upcomingCount,
                'expiredCount'       => $expiredCount,
                'hiddenCount'        => $hiddenCount,
                'page'               => $page,
                'totalPages'         => $totalPages,
                'totalCount'         => $totalCount,
                'perPage'            => $perPage,
                'filters'            => $filters,
                'typeCounts'         => $typeCounts,
                'totalFilteredCount' => $totalFilteredCount,
                'currentUser'        => $currentUser,
                'baseRoute'          => $baseRoute,
            ]);
            $viewModel->setTemplate('library/book/announcements-admin');
            return $viewModel;
        }

        $typeFilter = $this->queryString('type', 'all');
        $searchQuery = trim((string)$this->queryString('search', ''));
        if ($searchQuery === '') {
            $searchQuery = trim((string)$this->queryString('q', ''));
        }

        $announcements = [];
        if ($this->dbAdapter) {
            try {
                $sql = 'SELECT * FROM announcements WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())';
                $params = [];

                if ($typeFilter !== 'all') {
                    $sql .= ' AND type = ?';
                    $params[] = $typeFilter;
                }

                if ($searchQuery !== '') {
                    $sql .= ' AND (title LIKE ? OR content LIKE ?)';
                    $params[] = '%' . $searchQuery . '%';
                    $params[] = '%' . $searchQuery . '%';
                }

                $sql .= ' ORDER BY created_at DESC';

                $statement = $this->dbAdapter->query($sql);
                $result = $statement->execute($params);
                $announcements = iterator_to_array($result);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return new ViewModel([
            'announcements' => $announcements,
            'filters' => [
                'type' => $typeFilter,
                'search' => $searchQuery,
            ],
            'currentUser' => $currentUser,
            'baseRoute' => $baseRoute,
        ]);
    }

    public function addAnnouncementAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if ($this->getRequest()->isPost()) {
            $currentUser = $this->currentUser();
            $data = $this->postData();

            $title     = trim((string)($data['title'] ?? ''));
            $content   = trim((string)($data['content'] ?? ''));
            $type      = $data['type'] ?? 'general';
            $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
            $endDate   = !empty($data['end_date'])   ? $data['end_date']   : null;
            $isActive  = isset($data['is_active']) ? 1 : 0;

            if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày bắt đầu (Từ ngày).');
                return $this->redirect()->toRoute('library/announcements/add');
            }

            if ($endDate && strtotime($endDate) < strtotime(date('Y-m-d'))) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày hiện tại.');
                return $this->redirect()->toRoute('library/announcements/add');
            }

            $allowedTypes = ['event', 'contest', 'holiday', 'general'];
            if (!in_array($type, $allowedTypes)) {
                $type = 'general';
            }

            if ($title === '' || $content === '') {
                $this->flash()->addErrorMessage('Tiêu đề và nội dung bản tin không được để trống.');
                return $this->redirect()->toRoute('library/announcements/add');
            }

            try {
                $this->dbAdapter->query(
                    "INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                     [$title, $content, $type, $startDate, $endDate, $isActive, $currentUser['id']]
                );
                $this->flash()->addSuccessMessage('Đã đăng bản tin "' . htmlspecialchars($title) . '" thành công.');
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
            }

            return $this->redirect()->toRoute('library/announcements');
        }

        return new ViewModel([
            'currentUser' => $this->currentUser(),
        ]);
    }

    public function editAnnouncementAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id <= 0) {
            $this->flash()->addErrorMessage('Không tìm thấy ID bản tin.');
            return $this->redirect()->toRoute('library/announcements');
        }

        $stmt = $this->dbAdapter->query("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $res = iterator_to_array($stmt->execute([$id]));
        if (count($res) === 0) {
            $this->flash()->addErrorMessage('Bản tin không tồn tại.');
            return $this->redirect()->toRoute('library/announcements');
        }
        $ann = $res[0];

        if ($this->getRequest()->isPost()) {
            $data = $this->postData();

            $title     = trim((string)($data['title'] ?? ''));
            $content   = trim((string)($data['content'] ?? ''));
            $type      = $data['type'] ?? 'general';
            $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
            $endDate   = !empty($data['end_date'])   ? $data['end_date']   : null;
            $isActive  = isset($data['is_active']) ? 1 : 0;

            if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày bắt đầu (Từ ngày).');
                return $this->redirect()->toRoute('library/announcements/edit', ['id' => $id]);
            }

            if ($endDate && strtotime($endDate) < strtotime(date('Y-m-d'))) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày hiện tại.');
                return $this->redirect()->toRoute('library/announcements/edit', ['id' => $id]);
            }

            $allowedTypes = ['event', 'contest', 'holiday', 'general'];
            if (!in_array($type, $allowedTypes)) {
                $type = 'general';
            }

            if ($title === '' || $content === '') {
                $this->flash()->addErrorMessage('Tiêu đề và nội dung bản tin không được để trống.');
                return $this->redirect()->toRoute('library/announcements/edit', ['id' => $id]);
            }

            try {
                $this->dbAdapter->query(
                    "UPDATE announcements SET title = ?, content = ?, type = ?, start_date = ?, end_date = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                    [$title, $content, $type, $startDate, $endDate, $isActive, $id]
                );
                $this->flash()->addSuccessMessage('Đã cập nhật bản tin "' . htmlspecialchars($title) . '" thành công.');
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
            }

            return $this->redirect()->toRoute('library/announcements');
        }

        return new ViewModel([
            'announcement' => $ann,
            'currentUser' => $this->currentUser(),
        ]);
    }

    public function deleteAnnouncementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->dbAdapter->query("DELETE FROM announcements WHERE id = ?", [$id]);
            $this->flash()->addSuccessMessage('Đã xóa bản tin.');
        }

        return $this->redirect()->toRoute('library/announcements');
    }

    public function toggleAnnouncementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $stmt = $this->dbAdapter->query("SELECT * FROM announcements WHERE id = ? LIMIT 1");
            $res = iterator_to_array($stmt->execute([$id]));
            if (count($res) > 0) {
                $ann = $res[0];
                $isActive = (bool)$ann['is_active'];
                $today = date('Y-m-d');
                
                $isNotStarted = $ann['start_date'] && $ann['start_date'] > $today;
                $isExpired = $ann['end_date'] && $ann['end_date'] < $today;
                
                $isShowing = $isActive && !$isNotStarted && !$isExpired;
                
                if ($isShowing) {
                    $this->dbAdapter->query(
                        "UPDATE announcements SET is_active = 0, updated_at = NOW() WHERE id = ?",
                        [$id]
                    );
                    $this->flash()->addSuccessMessage('Đã ẩn bản tin thành công.');
                } else {
                    $updates = ["is_active = 1", "updated_at = NOW()"];
                    $params = [];
                    
                    if ($isNotStarted) {
                        $updates[] = "start_date = NULL";
                    }
                    if ($isExpired) {
                        $updates[] = "end_date = NULL";
                    }
                    
                    $params[] = $id;
                    
                    $this->dbAdapter->query(
                        "UPDATE announcements SET " . implode(", ", $updates) . " WHERE id = ?",
                        $params
                    );
                    $this->flash()->addSuccessMessage('Đã hiển thị bản tin thành công.');
                }
            } else {
                $this->flash()->addErrorMessage('Không tìm thấy bản tin.');
            }
        }

        return $this->redirect()->toRoute('library/announcements');
    }

    private function getCategoryOptions(): array
    {
        $defaultCategories = [
            'Công nghệ thông tin' => 'Công nghệ thông tin',
            'Văn học nước ngoài' => 'Văn học nước ngoài',
            'Kỹ năng học tập'    => 'Kỹ năng học tập',
            'Tâm lý / Sức khỏe'  => 'Tâm lý / Sức khỏe',
            'Kinh tế / Kinh doanh' => 'Kinh tế / Kinh doanh',
            'Khoa học'           => 'Khoa học',
            'Kỹ năng sống'       => 'Kỹ năng sống',
            'Văn học Việt Nam'   => 'Văn học Việt Nam',
            'Triết học'          => 'Triết học',
            'Tiểu thuyết'        => 'Tiểu thuyết',
            'Thiếu nhi'          => 'Thiếu nhi',
            'Sức khỏe'           => 'Sức khỏe',
            'Lịch sử'            => 'Lịch sử',
            'Tôn giáo / Tâm linh' => 'Tôn giáo / Tâm linh',
            'Ngoại ngữ'          => 'Ngoại ngữ',
            'Y học'              => 'Y học',
            'Xã hội học'         => 'Xã hội học',
            'Công nghệ'          => 'Công nghệ',
            'Ẩm thực'            => 'Ẩm thực',
            'Toán học'           => 'Toán học',
            'Địa lý'             => 'Địa lý',
            'Khác'               => 'Khác',
        ];

        if ($this->dbAdapter) {
            try {
                $result = $this->dbAdapter->query("SELECT name FROM book_categories ORDER BY name ASC")->execute();
                $options = [];
                foreach ($result as $row) {
                    $options[$row['name']] = $row['name'];
                }
                return !empty($options) ? $options : $defaultCategories;
            } catch (\Throwable $e) {
                return $defaultCategories;
            }
        }
        return $defaultCategories;
    }

    private function getCategoriesWithId(): array
    {
        if ($this->dbAdapter) {
            try {
                return iterator_to_array(
                    $this->dbAdapter->query("SELECT * FROM book_categories ORDER BY name ASC")->execute()
                );
            } catch (\Throwable $e) {
                return [];
            }
        }
        return [];
    }
}

