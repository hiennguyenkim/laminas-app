<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Library\Model\Table\UserTable;
use Library\Service\MailService;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use DomainException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class FineController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private UserTable $userTable,
        private AdapterInterface $db,
        private ?MailService $mailService = null
    ) {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response|ViewModel
    {
        $loginRedirect = $this->requireLogin();
        if ($loginRedirect instanceof Response) {
            return $loginRedirect;
        }

        $currentUser = $this->currentUser();
        if ($currentUser === null || ($currentUser['role'] ?? '') !== 'student') {
            $this->flash()->addErrorMessage('Chỉ sinh viên mới được truy cập trang này.');
            return $this->redirect()->toRoute('announcements');
        }

        $userId = $currentUser['id'];

        // Fetch all fines for the student
        $sql = "SELECT * FROM fines WHERE user_id = ? ORDER BY status ASC, created_at DESC";
        $stmt = $this->db->createStatement($sql);
        $resultSet = $stmt->execute([$userId]);
        $fines = iterator_to_array($resultSet);

        return new ViewModel([
            'fines' => $fines,
        ]);
    }

    public function checkoutAction(): Response|ViewModel
    {
        $loginRedirect = $this->requireLogin();
        if ($loginRedirect instanceof Response) {
            return $loginRedirect;
        }

        $currentUser = $this->currentUser();
        if ($currentUser === null || ($currentUser['role'] ?? '') !== 'student') {
            $this->flash()->addErrorMessage('Chỉ sinh viên mới được truy cập trang này.');
            return $this->redirect()->toRoute('announcements');
        }

        $userId = $currentUser['id'];
        $fineId = $this->routeInt('id');

        // Fetch specific fine
        $sql = "SELECT * FROM fines WHERE fine_id = ? AND user_id = ? LIMIT 1";
        $stmt = $this->db->createStatement($sql);
        $fine = $stmt->execute([$fineId, $userId])->current();

        if (!$fine) {
            $this->flash()->addErrorMessage('Không tìm thấy khoản phạt yêu cầu.');
            return $this->redirect()->toRoute('student/fine');
        }

        if ($fine['status'] === 'paid') {
            $this->flash()->addInfoMessage('Khoản phạt này đã được thanh toán trước đó.');
            return $this->redirect()->toRoute('student/fine');
        }

        $request = $this->httpRequest();
        if ($request->isPost()) {
            $connection = $this->db->getDriver()->getConnection();
            $connection->beginTransaction();

            try {
                // 1. Update fine status to paid
                $updateSql = "UPDATE fines SET status = 'paid', paid_at = NOW() WHERE fine_id = ?";
                $this->db->query($updateSql)->execute([$fineId]);

                // 2. Check if student still has any other unpaid fines
                $checkSql = "SELECT COUNT(*) AS cnt FROM fines WHERE user_id = ? AND status = 'unpaid'";
                $checkStmt = $this->db->createStatement($checkSql);
                $checkRes = $checkStmt->execute([$userId])->current();
                $unpaidCount = (int)($checkRes['cnt'] ?? 0);

                $unlocked = false;
                if ($unpaidCount === 0) {
                    // 3. Unlock student account in database
                    $this->userTable->unlockUser($userId);

                    // 4. Restore standard borrow limit to 5
                    $userObj = $this->userTable->getUser($userId);
                    $userObj->borrowLimit = 5;
                    $this->userTable->saveUser($userObj);

                    // 5. Update logged-in session user status to reflect active immediately
                    $session = $this->authSession();
                    if (isset($session->user) && is_array($session->user)) {
                        $session->user['account_status'] = 'active';
                        $session->user['locked_until'] = '';
                    }

                    // 6. Insert notification for account unlocking
                    $notifySql = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at) 
                                  VALUES (?, 0, 'Tài khoản đã mở khóa', ?, 'system', ?, NOW())";
                    $notifyMessage = "Tài khoản của bạn đã được mở khóa và khôi phục hạn mức mượn 5 cuốn sau khi hoàn tất nộp phạt.";
                    $this->db->query($notifySql)->execute([$userId, $notifyMessage, $fineId]);

                    $unlocked = true;
                }

                $connection->commit();

                if ($unlocked) {
                    $this->flash()->addSuccessMessage('Thanh toán thành công! Tài khoản của bạn đã được mở khóa hoạt động trở lại.');
                } else {
                    $this->flash()->addSuccessMessage('Thanh toán thành công. Vui lòng hoàn tất nộp phạt các khoản còn lại để mở khóa tài khoản.');
                }

                return $this->redirect()->toRoute('student/fine');
            } catch (\Throwable $e) {
                try {
                    $connection->rollback();
                } catch (\Throwable $rollbackError) {}

                $this->flash()->addErrorMessage('Đã xảy ra lỗi trong quá trình xử lý thanh toán: ' . $e->getMessage());
                return $this->redirect()->toRoute('student/fine');
            }
        }

        // Fetch VietQR settings
        $settingsSql = "SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('vietqr_bank_id', 'vietqr_account_no', 'vietqr_account_name')";
        $settingsStmt = $this->db->createStatement($settingsSql);
        $settingsRes = $settingsStmt->execute();
        $bankSettings = [];
        foreach ($settingsRes as $row) {
            $bankSettings[$row['setting_key']] = $row['setting_value'];
        }

        return new ViewModel([
            'fine' => $fine,
            'bankSettings' => $bankSettings,
        ]);
    }

    public function adminIndexAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $filters = [
            'search' => trim((string)$this->queryString('search', '')),
            'status' => (string)$this->queryString('status', ''),
            'sort'   => $this->queryString('sort', 'created_at'),
            'direction' => $this->queryString('direction', 'DESC'),
        ];

        $page = (int) $this->queryString('page', '1');
        $page = max(1, $page);

        $perPageRaw = $this->queryString('perPage', '20');
        if ($perPageRaw === 'all') {
            $perPage = 999999;
        } else {
            $perPage = (int) $perPageRaw;
            if (!in_array($perPage, [10, 20, 50, 100], true)) {
                $perPage = 20;
                $perPageRaw = '20';
            }
        }

        $where = [];
        $params = [];

        if ($filters['status'] !== '') {
            $where[] = "f.status = ?";
            $params[] = $filters['status'];
        }

        if ($filters['search'] !== '') {
            $where[] = "(f.fine_id = ? OR u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR f.reason LIKE ?)";
            $searchWild = '%' . $filters['search'] . '%';
            $searchVal = is_numeric($filters['search']) ? (int)$filters['search'] : 0;
            $params[] = $searchVal;
            $params[] = $searchWild;
            $params[] = $searchWild;
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $whereSql = '';
        if (count($where) > 0) {
            $whereSql = "WHERE " . implode(" AND ", $where);
        }

        $countSql = "SELECT COUNT(*) as cnt FROM fines f JOIN users u ON f.user_id = u.user_id $whereSql";
        $countStmt = $this->db->createStatement($countSql);
        $countRes = $countStmt->execute($params)->current();
        $totalItems = (int)($countRes['cnt'] ?? 0);

        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $allowedSort = [
            'id'         => 'f.fine_id',
            'amount'     => 'f.amount',
            'created_at' => 'f.created_at',
            'paid_at'    => 'f.paid_at',
            'status'     => 'f.status',
            'username'   => 'u.username',
        ];
        $sortCol = $allowedSort[$filters['sort']] ?? 'f.created_at';
        $dir = strtoupper($filters['direction']) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT f.*, u.username, u.email, u.full_name 
                FROM fines f 
                JOIN users u ON f.user_id = u.user_id 
                $whereSql 
                ORDER BY $sortCol $dir 
                LIMIT $perPage OFFSET $offset";
        $stmt = $this->db->createStatement($sql);
        $fines = iterator_to_array($stmt->execute($params));

        // Calculate summary stats
        $summarySql = "SELECT 
                         COALESCE(SUM(CASE WHEN status = 'unpaid' THEN amount ELSE 0 END), 0) as total_unpaid,
                         COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as total_paid,
                         COUNT(*) as total_count
                       FROM fines";
        $summary = $this->db->query($summarySql)->execute()->current();

        return new ViewModel([
            'fines'      => $fines,
            'filters'    => $filters,
            'summary'    => $summary,
            'pagination' => [
                'page'       => $page,
                'perPage'    => $perPageRaw,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    public function adminPayAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/fine');
        }

        $fineId = $this->routeInt('id');
        $stmt = $this->db->createStatement("SELECT * FROM fines WHERE fine_id = ?");
        $fine = $stmt->execute([$fineId])->current();

        if (!$fine) {
            $this->flash()->addErrorMessage('Không tìm thấy khoản phạt yêu cầu.');
            return $this->redirect()->toRoute('library/fine');
        }

        if ($fine['status'] === 'paid') {
            $this->flash()->addInfoMessage('Khoản phạt này đã được thanh toán.');
            return $this->redirect()->toRoute('library/fine');
        }

        $connection = $this->db->getDriver()->getConnection();
        $connection->beginTransaction();

        try {
            $userId = (int)$fine['user_id'];
            $fineAmount = (float)$fine['amount'];

            // 1. Update fine status to paid
            $updateSql = "UPDATE fines SET status = 'paid', paid_at = NOW() WHERE fine_id = ?";
            $this->db->query($updateSql)->execute([$fineId]);

            // 2. Check if student still has any other unpaid fines
            $checkSql = "SELECT COUNT(*) AS cnt FROM fines WHERE user_id = ? AND status = 'unpaid'";
            $checkStmt = $this->db->createStatement($checkSql);
            $checkRes = $checkStmt->execute([$userId])->current();
            $unpaidCount = (int)($checkRes['cnt'] ?? 0);

            // 3. Bell notification: payment confirmed (always)
            try {
                $payNotiMsg = "Khoản phạt #" . $fineId . " (" . number_format($fineAmount, 0, ',', '.') . " VNĐ) đã được Thủ thư xác nhận thanh toán tiền mặt thành công.";
                $this->db->query("INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at) VALUES (?, 0, 'Thanh toán khoản phạt thành công', ?, 'system', ?, NOW())")
                         ->execute([$userId, $payNotiMsg, $fineId]);
            } catch (\Throwable) {}

            $unlocked = false;
            if ($unpaidCount === 0) {
                // 4. Unlock student account in database
                $this->userTable->unlockUser($userId);

                // 5. Restore standard borrow limit to 5
                $userObj = $this->userTable->getUser($userId);
                $userObj->borrowLimit = 5;
                $this->userTable->saveUser($userObj);

                // 6. Bell notification: account unlocked
                try {
                    $this->db->query("INSERT INTO notifications (user_id, sender_id, title, message, type, related_id, created_at) VALUES (?, 0, 'Tài khoản đã mở khóa', ?, 'system', ?, NOW())")
                             ->execute([$userId, "Tài khoản của bạn đã được mở khóa và khôi phục hạn mức mượn 5 cuốn sau khi hoàn tất nộp phạt.", $fineId]);
                } catch (\Throwable) {}

                // 7. Email: unlocked
                if ($this->mailService !== null && $userObj) {
                    try {
                        $subject = "[Thư viện HDPE] Thanh toán phạt & Mở khóa tài khoản thành công";
                        $body = "Chào " . $userObj->fullName . ",\n\n"
                              . "Thủ thư đã xác nhận thu tiền mặt khoản phạt #" . $fineId . ".\n"
                              . "Số tiền: " . number_format($fineAmount, 0, ',', '.') . " VNĐ.\n\n"
                              . "Tài khoản thư viện của bạn đã được mở khóa và hạn mức mượn được khôi phục về 5 cuốn.\n"
                              . "Bạn có thể tiếp tục đăng nhập và sử dụng các dịch vụ của thư viện bình thường.\n\n"
                              . "Trân trọng,\nThư viện HDPE";
                        $this->mailService->sendEmail($userObj->email, $userObj->fullName, $subject, $body);
                    } catch (\Throwable) {}
                }

                $unlocked = true;
            } else {
                // 8. Email: payment confirmed but still has fines
                if ($this->mailService !== null) {
                    $userForMail = $this->userTable->getUser($userId);
                    if ($userForMail) {
                        try {
                            $subject = "[Thư viện HDPE] Xác nhận thanh toán khoản phạt #" . $fineId;
                            $body = "Chào " . $userForMail->fullName . ",\n\n"
                                  . "Thủ thư đã xác nhận thu tiền mặt khoản phạt #" . $fineId . ".\n"
                                  . "Số tiền: " . number_format($fineAmount, 0, ',', '.') . " VNĐ.\n\n"
                                  . "Tuy nhiên, tài khoản của bạn vẫn đang bị tạm khóa vì còn " . $unpaidCount . " khoản phạt chưa được thanh toán.\n"
                                  . "Vui lòng đăng nhập để xem và thanh toán các khoản phạt còn lại.\n\n"
                                  . "Trân trọng,\nThư viện HDPE";
                            $this->mailService->sendEmail($userForMail->email, $userForMail->fullName, $subject, $body);
                        } catch (\Throwable) {}
                    }
                }
            }

            $connection->commit();

            if ($unlocked) {
                $this->flash()->addSuccessMessage('Đã thu tiền mặt thành công! Tài khoản của sinh viên đã được mở khóa hoạt động trở lại.');
            } else {
                $this->flash()->addSuccessMessage('Đã thu tiền mặt thành công. Sinh viên vẫn còn khoản phạt khác chưa nộp.');
            }
        } catch (\Throwable $e) {
            try {
                $connection->rollback();
            } catch (\Throwable $rollbackError) {}

            $this->flash()->addErrorMessage('Lỗi xử lý thanh toán tiền mặt: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('library/fine');
    }

    public function adminExportAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $filters = [
            'search' => trim((string)$this->queryString('search', '')),
            'status' => (string)$this->queryString('status', ''),
        ];

        $where = [];
        $params = [];

        if ($filters['status'] !== '') {
            $where[] = "f.status = ?";
            $params[] = $filters['status'];
        }

        if ($filters['search'] !== '') {
            $where[] = "(f.fine_id = ? OR u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR f.reason LIKE ?)";
            $searchWild = '%' . $filters['search'] . '%';
            $searchVal = is_numeric($filters['search']) ? (int)$filters['search'] : 0;
            $params[] = $searchVal;
            $params[] = $searchWild;
            $params[] = $searchWild;
            $params[] = $searchWild;
            $params[] = $searchWild;
        }

        $whereSql = '';
        if (count($where) > 0) {
            $whereSql = "WHERE " . implode(" AND ", $where);
        }

        $sort = $this->queryString('sort', 'created_at');
        $direction = $this->queryString('direction', 'DESC');
        $allowedSort = [
            'id'         => 'f.fine_id',
            'amount'     => 'f.amount',
            'created_at' => 'f.created_at',
            'paid_at'    => 'f.paid_at',
            'status'     => 'f.status',
            'username'   => 'u.username',
        ];
        $sortCol = $allowedSort[$sort] ?? 'f.created_at';
        $dir = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT f.*, u.username, u.email, u.full_name 
                FROM fines f 
                JOIN users u ON f.user_id = u.user_id 
                $whereSql 
                ORDER BY $sortCol $dir";
        $stmt = $this->db->createStatement($sql);
        $fines = iterator_to_array($stmt->execute($params));

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Danh sach khoan phat')
            ->setCreator('Thu vien');

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Khoan phat');

        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Tên đăng nhập');
        $sheet->setCellValue('C1', 'Họ tên');
        $sheet->setCellValue('D1', 'Email');
        $sheet->setCellValue('E1', 'Số tiền (VND)');
        $sheet->setCellValue('F1', 'Lý do');
        $sheet->setCellValue('G1', 'Trạng thái');
        $sheet->setCellValue('H1', 'Ngày tạo');
        $sheet->setCellValue('I1', 'Ngày nộp');

        $sheet->getStyle('A1:I1')->applyFromArray($hStyle);

        $rowNum = 2;
        $statusLabels = [
            'unpaid' => 'Chưa nộp',
            'paid'   => 'Đã nộp',
        ];

        foreach ($fines as $fine) {
            $sheet->setCellValue('A' . $rowNum, $fine['fine_id']);
            $sheet->setCellValue('B' . $rowNum, $fine['username']);
            $sheet->setCellValue('C' . $rowNum, $fine['full_name']);
            $sheet->setCellValue('D' . $rowNum, $fine['email']);
            $sheet->setCellValue('E' . $rowNum, (float)$fine['amount']);
            $sheet->getStyle('E' . $rowNum)->getNumberFormat()->setFormatCode('#,##0');
            $sheet->setCellValue('F' . $rowNum, $fine['reason']);
            $sheet->setCellValue('G' . $rowNum, $statusLabels[$fine['status']] ?? $fine['status']);
            $sheet->setCellValue('H' . $rowNum, $fine['created_at']);
            $sheet->setCellValue('I' . $rowNum, $fine['paid_at'] ?: '—');
            $rowNum++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $fileName = 'danh-sach-khoan-phat-' . date('Y-m-d-His') . '.xlsx';
        
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tempFile === false) {
             throw new \RuntimeException('Cannot create temporary file.');
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
        @unlink($tempFile);

        return $response;
    }
}
