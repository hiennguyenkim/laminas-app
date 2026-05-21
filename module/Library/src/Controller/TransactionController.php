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

        $page        = max(1, (int)($this->params()->fromQuery('page', 1)));
        $perPage     = 10;
        $totalItems  = $this->borrowTable->countFiltered($filters, $isAdmin ? null : $userId);
        $totalPages  = max(1, (int)ceil($totalItems / $perPage));
        $page        = min($page, $totalPages);
        $offset      = ($page - 1) * $perPage;

        $records = $this->borrowTable->fetchAllWithDetails($filters, $isAdmin ? null : $userId, $perPage, $offset);

        return new ViewModel([
            'records'     => $records,
            'summary'     => $this->borrowTable->getSummary($isAdmin ? null : $userId),
            'filters'     => $filters,
            'isAdmin'     => $isAdmin,
            'currentUser' => $currentUser,
            'users'       => $isAdmin ? $this->userTable->fetchStudentOptions() : [],
            'pagination'  => [
                'page'       => $page,
                'perPage'    => $perPage,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ]
        ]);
    }

    public function exportAction(): Response
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

        // Fetch all matching records without pagination limits (limit=0, offset=0)
        $records = $this->borrowTable->fetchAllWithDetails($filters, $isAdmin ? null : $userId, 0, 0);

        // Generate CSV output in memory
        $output = fopen('php://temp', 'r+');
        if ($output === false) {
            throw new RuntimeException('Không thể tạo file tạm để xuất dữ liệu.');
        }

        // Add UTF-8 BOM for Microsoft Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // Set up headers
        fputcsv($output, [
            'Mã giao dịch',
            'Thành viên',
            'Tài khoản',
            'Tên sách',
            'ISBN',
            'Ngày mượn',
            'Hạn trả',
            'Ngày trả thực tế',
            'Trạng thái'
        ]);

        $statusMap = [
            'pending'  => 'Chờ duyệt',
            'borrowed' => 'Đang mượn',
            'returned' => 'Đã trả',
            'overdue'  => 'Quá hạn',
        ];

        $formatDate = function(?string $dateStr) {
            if (!$dateStr || $dateStr === '0000-00-00' || $dateStr === '0000-00-00 00:00:00') {
                return '—';
            }
            $time = strtotime($dateStr);
            if ($time === false) {
                return $dateStr;
            }
            if (strpos($dateStr, ' ') !== false) {
                return date('d/m/Y H:i:s', $time);
            }
            return date('d/m/Y', $time);
        };

        foreach ($records as $record) {
            fputcsv($output, [
                $record->id,
                $record->userFullName,
                $record->username,
                $record->bookTitle,
                $record->bookIsbn !== '' ? $record->bookIsbn : '—',
                $formatDate($record->borrowDate),
                $formatDate($record->returnDate),
                $formatDate($record->returnedAt),
                $statusMap[$record->status] ?? $record->status
            ]);
        }

        rewind($output);
        $csvData = stream_get_contents($output);
        fclose($output);

        $response = $this->getResponse();
        $response->getHeaders()->addHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="danh-sach-muon-tra.csv"',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
        $response->setContent($csvData !== false ? $csvData : '');

        return $response;
    }

    public function borrowAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $isAdmin = $this->isAdmin();
        $currentUser = $this->currentUser() ?? [];
        $currentUserId = $currentUser['id'] ?? 0;

        // Build danh sách sách khả dụng cho Laminas Form validation
        // (điều kiện: quantity >= 1 VÀ status = 'available')
        // NOTE: UI mới dùng AJAX autocomplete, nhưng vẫn cần bookOptions
        //       để BorrowForm validate book_id hợp lệ phía server.
        $bookOptions = [];
        foreach ($this->bookTable->fetchAll() as $book) {
            if (! $book instanceof Book) {
                continue;
            }

            // FIX: Dùng >= 1 thay vì > 0 để tường minh hơn.
            // Cả hai đều bằng nhau với số nguyên, nhưng >= 1 phản ánh
            // đúng nghĩa "còn ít nhất 1 cuốn có thể mượn".
            if ($book->quantity >= 1 && $book->status === 'available') {
                $bookOptions[$book->id] = $book->title . ' (còn ' . $book->quantity . ')';
            }
        }

        $userOptions = [];
        if ($isAdmin) {
            foreach ($this->userTable->fetchStudentOptions() as $user) {
                if (! $user instanceof User) {
                    continue;
                }

                $label = $user->fullName . ' (@' . $user->username . ')';
                if ($user->isLocked()) {
                    $label .= ' [ĐÃ KHÓA THẺ]';
                }
                $userOptions[$user->id] = $label;
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

        // Book ID để prefill UI (từ ?book_id=... trong URL)
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
                        'form'         => $form,
                        'isAdmin'      => $isAdmin,
                        'currentUser'  => $currentUser,
                        'prefillBookId' => $prefillBookId,
                    ]);
                }

                try {
                    $this->circulationService->borrowBook(
                        $bookId,
                        $userId,
                        $data['borrow_date'],
                        $data['return_date'],
                        $isAdmin
                    );
                    if ($isAdmin) {
                        $this->flash()->addSuccessMessage('Lập phiếu mượn thành công.');
                    } else {
                        $this->flash()->addSuccessMessage('Gửi yêu cầu mượn sách thành công! Vui lòng chờ thủ thư phê duyệt.');
                    }
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
            'form'          => $form,
            'isAdmin'       => $isAdmin,
            'currentUser'   => $currentUser,
            'prefillBookId' => $prefillBookId,
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

    public function approveAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/transaction');
        }

        $id = $this->routeInt('id');
        $post = $this->postData();
        $borrowDate = isset($post['borrow_date']) ? trim((string)$post['borrow_date']) : null;
        $returnDate = isset($post['return_date']) ? trim((string)$post['return_date']) : null;

        if ($borrowDate === '') {
            $borrowDate = null;
        }
        if ($returnDate === '') {
            $returnDate = null;
        }

        try {
            $this->circulationService->approveBorrow($id, $borrowDate, $returnDate);
            $this->flash()->addSuccessMessage('Phê duyệt phiếu mượn thành công.');
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/transaction');
    }

    public function rejectAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/transaction');
        }

        try {
            $this->circulationService->rejectBorrow($this->routeInt('id'));
            $this->flash()->addSuccessMessage('Từ chối duyệt phiếu mượn thành công.');
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/transaction');
    }
}
