<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Form\BookForm;
use Library\Model\Entity\Book;
use Library\Model\Table\AnnouncementTable;
use Library\Model\Table\BookCategoryTable;
use Library\Model\Table\BookReviewTable;
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
        private AnnouncementTable $announcementTable,
        private BookReviewTable $bookReviewTable,
        private BookCategoryTable $bookCategoryTable
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

        $perPageRaw = $this->queryString('perPage', '20');
        if ($perPageRaw === 'all') {
            $perPage = 999999;
        } else {
            $perPage = (int) $perPageRaw;
            if (! in_array($perPage, [20, 50, 100], true)) {
                $perPage = 20;
                $perPageRaw = '20';
            }
        }

        $sort = $this->queryString('sort', 'id');
        $direction = $this->queryString('direction', 'ASC');

        $page = (int) $this->queryString('page', '1');
        $page = max(1, $page);

        $totalItems = $this->bookTable->countFiltered($filters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        $announcements = [];
        try {
            $announcements = $this->announcementTable->fetchActiveForSidebar(3);
        } catch (\Exception $e) {
            // ignore
        }

        $viewModel = new ViewModel([
            'books'      => $this->bookTable->fetchPage($filters, $page, $perPage, $sort, $direction),
            'filters'    => array_merge($filters, ['sort' => $sort, 'direction' => $direction]),
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

        if ($this->isAdmin()) {
            $viewModel->setTemplate('library/book/index-admin');
        } else {
            $viewModel->setTemplate('library/book/index');
        }

        return $viewModel;
    }

    public function exportAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $filters = [
            'search'   => trim((string)$this->queryString('search', '')),
            'category' => $this->queryString('category'),
            'status'   => $this->queryString('status'),
        ];
        
        $sort = $this->queryString('sort', 'id');
        $direction = $this->queryString('direction', 'ASC');

        // Fetch all matching books
        $books = $this->bookTable->fetchPage($filters, 1, 999999, $sort, $direction);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Danh sach dau sach')
            ->setCreator('Thu vien');

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dau sach');

        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Tên đầu sách');
        $sheet->setCellValue('C1', 'Tác giả');
        $sheet->setCellValue('D1', 'ISBN');
        $sheet->setCellValue('E1', 'Thể loại');
        $sheet->setCellValue('F1', 'Số lượng');
        $sheet->setCellValue('G1', 'Trạng thái');
        $sheet->setCellValue('H1', 'Ngày tạo');

        $sheet->getStyle('A1:H1')->applyFromArray($hStyle);

        $rowNum = 2;
        $statusLabels = [
            'available'   => 'Sẵn sàng',
            'borrowed'    => 'Hết sách',
            'unavailable' => 'Tạm khóa',
        ];

        foreach ($books as $book) {
            $sheet->setCellValue('A' . $rowNum, $book->id);
            $sheet->setCellValue('B' . $rowNum, $book->title);
            $sheet->setCellValue('C' . $rowNum, $book->author);
            $sheet->setCellValue('D' . $rowNum, $book->isbn);
            $sheet->setCellValue('E' . $rowNum, $book->category);
            $sheet->setCellValue('F' . $rowNum, $book->quantity);
            $sheet->setCellValue('G' . $rowNum, $statusLabels[$book->status] ?? $book->status);
            $sheet->setCellValue('H' . $rowNum, $book->createdAt);
            $rowNum++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $fileName = 'danh-sach-dau-sach-' . date('Y-m-d-His') . '.xlsx';
        
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tempFile === false) {
             throw new RuntimeException('Cannot create temporary file.');
        }
        $writer->save($tempFile);

        $response = new Response();
        $response->getHeaders()->addHeaders([
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment;filename="' . $fileName . '"',
            'Cache-Control'       => 'max-age=0',
        ]);
        $content = file_get_contents($tempFile);
        $response->setContent($content !== false ? $content : '');
        unlink($tempFile);

        return $response;
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
        try {
            $reviews = $this->bookReviewTable->fetchReviewsForBook($id);
        } catch (\Exception $e) {}

        // Fetch user borrowing history for this book to allow review
        $hasBorrowed = false;
        $hasReviewed = false;
        if ($currentUser && $currentUser['role'] === 'student') {
            $hasBorrowed = $this->bookReviewTable->hasBorrowedAny((int)$currentUser['id'], $id);
            $hasReviewed = $this->bookReviewTable->hasReviewed((int)$currentUser['id'], $id);
        }

        $viewModel = new ViewModel([
            'book'            => $book,
            'hasActiveBorrow' => $this->borrowTable->hasActiveBorrowForBook($id),
            'currentUser'     => $currentUser,
            'canManage'       => $canManage,
            'reviews'         => $reviews,
            'hasBorrowed'     => $hasBorrowed,
            'hasReviewed'     => $hasReviewed,
        ]);

        if ($canManage) {
            $viewModel->setTemplate('library/book/view-admin');
        } else {
            $viewModel->setTemplate('library/book/view');
        }

        return $viewModel;
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

    private function handlePreviewUpload(?string $existingUrl = null): ?string
    {
        $file = $_FILES['preview_file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $maxSize = 10 * 1024 * 1024; // 10 MB
        if ($file['size'] > $maxSize) {
            $this->flash()->addWarningMessage('File tài liệu xem thử quá lớn. Tối đa 10MB.');
            return null;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf'];
        if (!in_array($ext, $allowed)) {
            $this->flash()->addWarningMessage('Định dạng tài liệu xem thử không hỗ trợ. Chỉ chấp nhận: ' . implode(', ', $allowed));
            return null;
        }

        $uploadDir = getcwd() . '/public/uploads/previews/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Delete existing local preview if it exists
        if ($existingUrl && str_starts_with($existingUrl, '/uploads/previews/')) {
            $oldPath = getcwd() . '/public' . $existingUrl;
            if (file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        $newFilename = 'preview_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
            return '/uploads/previews/' . $newFilename;
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

                $uploadedPreviewUrl = $this->handlePreviewUpload();
                if ($uploadedPreviewUrl) {
                    $book->previewUrl = $uploadedPreviewUrl;
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
                $oldPreviewUrl = $book->previewUrl;

                // Form binding automatically updates $book properties with form inputs
                // So $book->coverImageUrl has the value from $form->get('cover_image_url')
                // If a new cover image file is uploaded, handle it and override coverImageUrl
                $uploadedUrl = $this->handleCoverUpload($oldCoverUrl);
                if ($uploadedUrl) {
                    $book->coverImageUrl = $uploadedUrl;
                }

                $uploadedPreviewUrl = $this->handlePreviewUpload($oldPreviewUrl);
                if ($uploadedPreviewUrl) {
                    $book->previewUrl = $uploadedPreviewUrl;
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
            if ($book->previewUrl && str_starts_with($book->previewUrl, '/uploads/previews/')) {
                $oldPreviewPath = getcwd() . '/public' . $book->previewUrl;
                if (file_exists($oldPreviewPath)) {
                    @unlink($oldPreviewPath);
                }
            }
        } catch (\Exception $e) {}

        $this->bookTable->deleteBook($id);
        $this->flash()->addSuccessMessage('Đã xóa sách khỏi thư viện.');
        return $this->redirect()->toRoute('library/book');
    }

    public function toggleStatusAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/book');
        }

        $id = $this->routeInt('id');
        try {
            $book = $this->bookTable->getBook($id);
            if ($book->status === 'unavailable') {
                $book->status = 'available';
                $this->bookTable->saveBook($book);
                $this->flash()->addSuccessMessage('Đã mở khóa đầu sách "' . $book->title . '".');
            } else {
                $book->status = 'unavailable';
                $this->bookTable->saveBook($book);
                $this->flash()->addSuccessMessage('Đã khóa đầu sách "' . $book->title . '".');
            }
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage('Có lỗi xảy ra: ' . $e->getMessage());
        }

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
        $redirectUrl = trim((string)($this->params()->fromQuery('redirect') ?? $this->postData()['redirect'] ?? ''));

        if (!$this->httpRequest()->isPost()) {
            if ($redirectUrl !== '') {
                return $this->redirect()->toUrl($redirectUrl);
            }
            return $this->redirect()->toRoute($route);
        }

        $bookId = $this->routeInt('id');
        $rating = (int)($this->postData()['rating'] ?? 5);
        $comment = trim((string)($this->postData()['comment'] ?? ''));
        $userId = $currentUser['id'];

        try {
            // Kiểm tra 1: Đã hoặc đang mượn sách này chưa?
            $hasBorrowed = $this->bookReviewTable->hasBorrowedAny($userId, $bookId);

            if (!$hasBorrowed) {
                $this->flash()->addErrorMessage('Bạn chỉ có thể đánh giá sách sau khi đã hoặc đang mượn.');
                if ($redirectUrl !== '') {
                    return $this->redirect()->toUrl($redirectUrl);
                }
                return $this->redirect()->toRoute($route, ['action' => 'view', 'id' => $bookId]);
            }

            // Kiểm tra 2: Đã review cuốn này chưa?
            $hasReviewed = $this->bookReviewTable->hasReviewed($userId, $bookId);

            if ($hasReviewed) {
                $this->flash()->addErrorMessage('Bạn đã đánh giá cuốn sách này rồi.');
                if ($redirectUrl !== '') {
                    return $this->redirect()->toUrl($redirectUrl);
                }
                return $this->redirect()->toRoute($route, ['action' => 'view', 'id' => $bookId]);
            }

            $this->bookReviewTable->addReview($bookId, (int)$currentUser['id'], $rating, $comment);
            $this->flash()->addSuccessMessage('Cảm ơn bạn đã đánh giá cuốn sách này!');
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage('Có lỗi xảy ra khi gửi đánh giá: ' . $e->getMessage());
        }

        if ($redirectUrl !== '') {
            return $this->redirect()->toUrl($redirectUrl);
        }
        return $this->redirect()->toRoute($route, ['action' => 'view', 'id' => $bookId]);
    }

    public function deleteReviewAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/book');
        }

        $reviewId = (int) $this->params()->fromRoute('id', 0);
        $bookId   = (int) $this->params()->fromQuery('book_id', 0);

        if ($reviewId > 0) {
            $this->bookReviewTable->deleteReview($reviewId);
            $this->flash()->addSuccessMessage('Đã xóa đánh giá.');
        }

        if ($bookId > 0) {
            return $this->redirect()->toRoute('library/book', ['action' => 'view', 'id' => $bookId]);
        }

        return $this->redirect()->toRoute('library/book');
    }


    public function categoriesAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        return new ViewModel([
            'categoriesWithId' => $this->getCategoriesWithId(),
            'currentUser'      => $this->currentUser(),
        ]);
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

        try {
            $result = $this->bookCategoryTable->fetchNames();
            $options = [];
            foreach ($result as $name) {
                $options[$name] = $name;
            }
            return !empty($options) ? $options : $defaultCategories;
        } catch (\Throwable $e) {
            return $defaultCategories;
        }
    }

    private function getCategoriesWithId(): array
    {
        try {
            return $this->bookCategoryTable->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }
}

