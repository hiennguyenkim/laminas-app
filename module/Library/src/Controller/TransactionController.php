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
        $year = $this->queryString('year');
        if ($year === null || $year === '') {
            $year = 'all';
        }
        $period = $this->queryString('period');
        if ($period === null || $period === '') {
            $period = 'year';
        }
        $week = $this->queryString('week');
        if ($week === null || $week === '') {
            $week = 'all';
        }

        $sort = $this->queryString('sort');
        $direction = $this->queryString('direction');

        $filters     = [
            'search'    => trim($this->queryString('search')),
            'status'    => $this->queryString('status'),
            'year'      => $year,
            'period'    => $period,
            'week'      => $week,
            'sort'      => $sort,
            'direction' => $direction,
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

        $viewModel = new ViewModel([
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

        if ($isAdmin) {
            $viewModel->setTemplate('library/transaction/index-admin');
        } else {
            $viewModel->setTemplate('library/transaction/index');
        }

        return $viewModel;
    }

    public function exportAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $isAdmin     = $this->isAdmin();
        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $year = $this->queryString('year');
        if ($year === null || $year === '') {
            $year = 'all';
        }
        $period = $this->queryString('period');
        if ($period === null || $period === '') {
            $period = 'year';
        }
        $week = $this->queryString('week');
        if ($week === null || $week === '') {
            $week = 'all';
        }

        $sort = $this->queryString('sort');
        $direction = $this->queryString('direction');

        $filters     = [
            'search'    => trim($this->queryString('search')),
            'status'    => $this->queryString('status'),
            'year'      => $year,
            'period'    => $period,
            'week'      => $week,
            'sort'      => $sort,
            'direction' => $direction,
        ];

        if ($isAdmin) {
            $filters['user_id'] = $this->queryString('user_id');
        }

        // Fetch all matching records without pagination limits (limit=0, offset=0)
        $records = $this->borrowTable->fetchAllWithDetails($filters, $isAdmin ? null : $userId, 0, 0);

        // Generate XLSX output using PhpSpreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Bao cao muon tra sach')
            ->setCreator('Thu vien');

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];
        $sumStyle  = ['font' => ['bold' => true], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']]];
        $evenStyle = ['fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0F4FA']]];
        $boldStyle = ['font' => ['bold' => true]];
        $titleStyle = ['font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF1A237E']]];

        $periodText = '';
        $periodLabel = 'ca-nam';
        if ($year === 'all') {
            $periodText .= 'Tất cả các năm';
            $periodLabel = 'tat-ca-nam';
        } else {
            $periodText .= 'Năm ' . $year;
            $periodLabel = 'nam-' . $year;
        }

        if ($period !== 'year') {
            if (preg_match('/^q([1-4])$/', $period, $m)) {
                $periodText .= ' - Quý ' . $m[1];
                $periodLabel .= '-quy-' . $m[1];
            } elseif (preg_match('/^m(\d{1,2})$/', $period, $m)) {
                $periodText .= ' - Tháng ' . $m[1];
                $periodLabel .= '-thang-' . $m[1];
            }
        }

        if ($week !== 'all' && $week !== '') {
            $periodText .= ' - Tuần ' . $week;
            $periodLabel .= '-tuan-' . $week;
        }

        $exporterName = $currentUser['full_name'] ?? ($currentUser['username'] ?? 'Thu vien');

        // ── Sheet 1: Chi tiet muon tra ──────────────────
        $s1 = $spreadsheet->getActiveSheet();
        $s1->setTitle('Chi tiet muon tra');

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

        $totalCountFiltered = count($records);
        $pendingCount = 0;
        $borrowedCount = 0;
        $returnedCount = 0;
        $overdueCount = 0;

        $dataRows = [];
        foreach ($records as $record) {
            $statusVal = $record->status;
            if ($statusVal === 'pending') {
                $pendingCount++;
            } elseif ($statusVal === 'borrowed') {
                $borrowedCount++;
            } elseif ($statusVal === 'returned') {
                $returnedCount++;
            } elseif ($statusVal === 'overdue') {
                $overdueCount++;
            }

            $dataRows[] = [
                (int)$record->id,
                $record->userFullName,
                $record->username,
                $record->bookTitle,
                $record->bookIsbn !== '' ? $record->bookIsbn : '—',
                $formatDate($record->borrowDate),
                $formatDate($record->returnDate),
                $formatDate($record->returnedAt),
                $statusMap[$statusVal] ?? $statusVal
            ];
        }

        // Title Block
        $s1->setCellValue('A1', 'CHI TIẾT PHIẾU MƯỢN TRẢ SÁCH THƯ VIỆN');
        $s1->mergeCells('A1:E1');
        $s1->getStyle('A1')->applyFromArray($titleStyle);

        $s1->setCellValue('A2', 'Thời gian:');       $s1->setCellValue('B2', $periodText);
        $s1->setCellValue('A3', 'Ngày xuất:');       $s1->setCellValue('B3', date('d/m/Y H:i:s'));
        $s1->setCellValue('A4', 'Người xuất:');     $s1->setCellValue('B4', $exporterName);

        $s1->setCellValue('D2', 'Tổng số phiếu:');   $s1->setCellValue('E2', $totalCountFiltered);
        $s1->setCellValue('D3', 'Đang mượn / Quá hạn:'); $s1->setCellValue('E3', ($borrowedCount + $overdueCount));
        $s1->setCellValue('D4', 'Đã trả / Chờ duyệt:'); $s1->setCellValue('E4', ($returnedCount + $pendingCount));

        $s1->getStyle('A2:A4')->applyFromArray($boldStyle);
        $s1->getStyle('D2:D4')->applyFromArray($boldStyle);
        $s1->getStyle('E2:E4')->applyFromArray($boldStyle);

        // Table Headers at Row 6
        $h2 = [
            'Mã giao dịch',
            'Thành viên',
            'Tài khoản',
            'Tên sách',
            'ISBN',
            'Ngày mượn',
            'Hạn trả',
            'Ngày trả thực tế',
            'Trạng thái'
        ];
        $s1->fromArray([$h2], null, 'A6');
        $s1->getStyle('A6:I6')->applyFromArray($hStyle);
        $s1->setAutoFilter('A6:I6');
        $s1->freezePane('A7');

        $r2 = 7;
        foreach ($dataRows as $row) {
            $s1->fromArray([$row], null, 'A' . $r2);
            $s1->getStyle('A' . $r2)->getNumberFormat()->setFormatCode('0');
            if ($r2 % 2 === 1) {
                $s1->getStyle('A' . $r2 . ':I' . $r2)->applyFromArray($evenStyle);
            }
            $r2++;
        }

        if ($r2 > 7) {
            $last = $r2 - 1;
            $s1->setCellValue('A' . $r2, 'Tổng cộng');
            $s1->setCellValue('B' . $r2, '=COUNTA(B7:B' . $last . ')');
            $s1->getStyle('A' . $r2 . ':I' . $r2)->applyFromArray($sumStyle);
        }

        foreach (range('A', 'I') as $c) {
            $s1->getColumnDimension($c)->setAutoSize(true);
        }

        // ── Sheet 2: Thong ke ──────────────────────
        $s2 = $spreadsheet->createSheet();
        $s2->setTitle('Thong ke');

        $s2->setCellValue('A1', 'THỐNG KÊ GIAO DỊCH MƯỢN TRẢ');
        $s2->mergeCells('A1:C1');
        $s2->getStyle('A1')->applyFromArray($titleStyle);

        $s2->setCellValue('A2', 'Thời gian:');  $s2->setCellValue('B2', $periodText);
        $s2->setCellValue('A3', 'Ngày xuất:');  $s2->setCellValue('B3', date('d/m/Y H:i:s'));
        $s2->getStyle('A2:A3')->applyFromArray($boldStyle);

        // Status Statistics Table
        $s2->setCellValue('A5', 'Thống kê theo trạng thái');
        $s2->getStyle('A5')->applyFromArray($boldStyle);

        $s2->fromArray([['Trạng thái', 'Số lượng']], null, 'A6');
        $s2->getStyle('A6:B6')->applyFromArray($hStyle);

        $s2->setCellValue('A7', 'Chờ duyệt'); $s2->setCellValue('B7', $pendingCount);
        $s2->setCellValue('A8', 'Đang mượn'); $s2->setCellValue('B8', $borrowedCount);
        $s2->setCellValue('A9', 'Đã trả');   $s2->setCellValue('B9', $returnedCount);
        $s2->setCellValue('A10', 'Quá hạn');  $s2->setCellValue('B10', $overdueCount);

        $s2->setCellValue('A11', 'Tổng cộng');
        $s2->setCellValue('B11', '=SUM(B7:B10)');
        $s2->getStyle('A11:B11')->applyFromArray($sumStyle);

        $s2->getStyle('A7:B7')->applyFromArray($evenStyle);
        $s2->getStyle('A9:B9')->applyFromArray($evenStyle);

        if ($year !== 'all') {
            $s2->setCellValue('D5', 'Thống kê mượn trả theo tháng (Năm ' . $year . ')');
            $s2->getStyle('D5')->applyFromArray($boldStyle);

            $s2->fromArray([['Tháng', 'Số lượt mượn', 'Số lượt trả']], null, 'D6');
            $s2->getStyle('D6:F6')->applyFromArray($hStyle);

            $monthlyStats = $this->borrowTable->getMonthlyStats((int)$year, $isAdmin ? null : $userId);
            $borrows = $monthlyStats['borrow'] ?? array_fill(0, 12, 0);
            $returns = $monthlyStats['return'] ?? array_fill(0, 12, 0);

            $r3 = 7;
            for ($m = 1; $m <= 12; $m++) {
                $s2->setCellValue('D' . $r3, 'Tháng ' . $m);
                $s2->setCellValue('E' . $r3, $borrows[$m - 1]);
                $s2->setCellValue('F' . $r3, $returns[$m - 1]);

                if ($r3 % 2 === 1) {
                    $s2->getStyle('D' . $r3 . ':F' . $r3)->applyFromArray($evenStyle);
                }
                $r3++;
            }

            $s2->setCellValue('D' . $r3, 'Tổng cộng');
            $s2->setCellValue('E' . $r3, '=SUM(E7:E' . ($r3 - 1) . ')');
            $s2->setCellValue('F' . $r3, '=SUM(F7:F' . ($r3 - 1) . ')');
            $s2->getStyle('D' . $r3 . ':F' . $r3)->applyFromArray($sumStyle);
        }

        foreach (range('A', 'F') as $c) {
            $s2->getColumnDimension($c)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $filename = 'bao-cao-muon-tra-' . $periodLabel . '-' . date('Ymd-His') . '.xlsx';

        $response = $this->getResponse();
        $response->getHeaders()->addHeaders([
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
        $response->setContent($content !== false ? $content : '');

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

                    $viewModel = new ViewModel([
                        'form'         => $form,
                        'isAdmin'      => $isAdmin,
                        'currentUser'  => $currentUser,
                        'prefillBookId' => $prefillBookId,
                    ]);

                    if ($isAdmin) {
                        $viewModel->setTemplate('library/transaction/borrow-admin');
                    } else {
                        $viewModel->setTemplate('library/transaction/borrow');
                    }

                    return $viewModel;
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

        $viewModel = new ViewModel([
            'form'          => $form,
            'isAdmin'       => $isAdmin,
            'currentUser'   => $currentUser,
            'prefillBookId' => $prefillBookId,
        ]);

        if ($isAdmin) {
            $viewModel->setTemplate('library/transaction/borrow-admin');
        } else {
            $viewModel->setTemplate('library/transaction/borrow');
        }

        return $viewModel;
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
