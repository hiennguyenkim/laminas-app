<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Form\UserForm;
use Library\Model\Entity\User;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Form\FormElementManager;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use RuntimeException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class UserController extends BaseController
{
    private const CREATE_FORM_SERVICE = 'Library\Form\UserCreateForm';
    private const EDIT_FORM_SERVICE   = 'Library\Form\UserEditForm';

    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private BorrowTable $borrowTable,
        private UserTable $userTable,
        private FormElementManager $formElementManager
    ) {
        parent::__construct($authSessionContainer);
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $filters = [
            'search' => trim((string)$this->queryString('search', '')),
            'role'   => (string)$this->queryString('role', ''),
            'status' => (string)$this->queryString('status', ''),
        ];

        $currentUser = $this->currentUser() ?? [
            'id' => 0,
            'username' => '',
            'email' => '',
            'full_name' => '',
            'role' => '',
        ];

        $page = (int) $this->queryString('page', '1');
        $page = max(1, $page);

        $perPageRaw = $this->queryString('perPage', '10');
        if ($perPageRaw === 'all') {
            $perPage = 999999;
        } else {
            $perPage = (int) $perPageRaw;
            if (! in_array($perPage, [10, 20, 50, 100], true)) {
                $perPage = 10;
                $perPageRaw = '10';
            }
        }

        $sort = $this->queryString('sort', 'id');
        $direction = $this->queryString('direction', 'ASC');

        $tableFilters = [
            'search' => $filters['search'],
            'role'   => $filters['role'],
        ];
        if ($filters['status'] !== '') {
            $tableFilters['status'] = $filters['status'];
        }

        $totalItems = $this->userTable->countFiltered($tableFilters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min($page, $totalPages);

        return new ViewModel([
            'users'     => $this->userTable->fetchPage($tableFilters, $page, $perPage, $sort, $direction),
            'filters'   => array_merge($filters, ['sort' => $sort, 'direction' => $direction]),
            'isAdmin'   => true,
            'summary'   => [
                'total'    => $this->userTable->countAll(),
                'admins'   => $this->userTable->countByRole('admin'),
                'students' => $this->userTable->countByRole('student'),
            ],
            'currentId' => $currentUser['id'],
            'pagination' => [
                'page'       => $page,
                'perPage'    => $perPageRaw,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
        ]);
    }

    public function exportAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $filters = [
            'search' => trim((string)$this->queryString('search', '')),
            'role'   => $this->queryString('role'),
            'status' => $this->queryString('status'),
        ];
        
        $sort = $this->queryString('sort', 'id');
        $direction = $this->queryString('direction', 'ASC');

        // Fetch all matching users
        $users = $this->userTable->fetchPage($filters, 1, 999999, $sort, $direction);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Danh sach nguoi dung')
            ->setCreator('Thu vien');

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nguoi dung');

        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Tên đăng nhập');
        $sheet->setCellValue('C1', 'Họ tên');
        $sheet->setCellValue('D1', 'Email');
        $sheet->setCellValue('E1', 'Vai trò');
        $sheet->setCellValue('F1', 'Trạng thái');
        $sheet->setCellValue('G1', 'Ngày tham gia');

        $sheet->getStyle('A1:G1')->applyFromArray($hStyle);

        $rowNum = 2;
        $roleLabels = [
            'admin'   => 'Quản trị viên',
            'student' => 'Sinh viên',
        ];
        $statusLabels = [
            'active' => 'Hoạt động',
            'locked' => 'Bị khóa',
        ];

        foreach ($users as $user) {
            $sheet->setCellValue('A' . $rowNum, $user->id);
            $sheet->setCellValue('B' . $rowNum, $user->username);
            $sheet->setCellValue('C' . $rowNum, $user->fullName);
            $sheet->setCellValue('D' . $rowNum, $user->email);
            $sheet->setCellValue('E' . $rowNum, $roleLabels[$user->role] ?? $user->role);
            $sheet->setCellValue('F' . $rowNum, $statusLabels[$user->accountStatus] ?? $user->accountStatus);
            $sheet->setCellValue('G' . $rowNum, $user->createdAt);
            $rowNum++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $fileName = 'danh-sach-nguoi-dung-' . date('Y-m-d-His') . '.xlsx';
        
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
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = $this->routeInt('id');

        try {
            $user = $this->userTable->getUser($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());

            return $this->redirect()->toRoute('library/user');
        }

        $currentId = $this->currentUser()['id'] ?? 0;
        $hasActiveTransactions = $this->borrowTable->hasActiveTransactionsForUser($id);
        $hasBorrowHistory = $this->borrowTable->hasBorrowHistoryForUser($id);
        $canDelete = $user->role === 'student'
            && $id !== $currentId
            && ! $hasActiveTransactions;

        $deleteBlockedReason = null;
        if (! $canDelete) {
            if ($user->role !== 'student') {
                $deleteBlockedReason = 'Chỉ có thể xóa tài khoản sinh viên.';
            } elseif ($id === $currentId) {
                $deleteBlockedReason = 'Không thể xóa tài khoản đang đăng nhập.';
            } elseif ($hasActiveTransactions) {
                $deleteBlockedReason = 'Không thể xóa sinh viên đang mượn sách hoặc có yêu cầu chờ duyệt.';
            }
        }

        $activeLoans = $this->borrowTable->countActiveLoansForUser($id);
        $remainingLimit = max(0, $user->borrowLimit - $activeLoans);
        $onTimeRate = $this->borrowTable->getOnTimeRateForUser($id);
        $overdueCount = $this->borrowTable->countOverdueOccurrencesForUser($id);

        // Fetch Penalty Logs (Hạng mục 4)
        $penaltyLogs = [];
        try {
            $sql = "SELECT pl.*, u.full_name as admin_name 
                    FROM penalty_logs pl 
                    LEFT JOIN users u ON pl.admin_id = u.user_id 
                    WHERE pl.user_id = ? 
                    ORDER BY pl.created_at DESC";
            $penaltyLogs = iterator_to_array($this->userTable->getAdapter()->query($sql)->execute([$id]));
        } catch (\Throwable $e) {}

        return new ViewModel([
            'user'                => $user,
            'currentId'           => $currentId,
            'activeLoans'         => $activeLoans,
            'remainingLimit'      => $remainingLimit,
            'hasOverdueLoans'     => $this->borrowTable->hasOverdueLoans($id),
            'overdueCount'        => $overdueCount,
            'onTimeRate'          => $onTimeRate,
            'hasBorrowHistory'    => $hasBorrowHistory,
            'borrowRecords'       => $this->borrowTable->fetchAllWithDetails([], $id),
            'canDelete'           => $canDelete,
            'deleteBlockedReason' => $deleteBlockedReason,
            'penaltyLogs'         => $penaltyLogs,
        ]);
    }

    public function addAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $form = $this->formElementManager->get(self::CREATE_FORM_SERVICE);
        if (! $form instanceof UserForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu tài khoản mới.');
        }

        if ($this->httpRequest()->isPost()) {
            $form->setData($this->postData());

            if ($form->isValid()) {
                /** @var array{username:string, email:string, full_name:string, role:string, password:string, nickname?:string} $data */
                $data = $form->getData();

                if ($this->userTable->usernameExists($data['username'])) {
                    $form->get('username')->setMessages(['Tên đăng nhập đã tồn tại.']);
                } elseif ($this->userTable->emailExists($data['email'])) {
                    $form->get('email')->setMessages(['Email đã được sử dụng.']);
                } else {
                    $user = new User();
                    $user->exchangeArray($data);
                    $user->isApproved = true; // Manual add = auto approved
                    $user->borrowLimit = (int)($data['borrow_limit'] ?? 5);

                    $this->userTable->saveUser(
                        $user,
                        password_hash($data['password'], PASSWORD_DEFAULT)
                    );

                    $this->flash()->addSuccessMessage('Đã tạo tài khoản mới thành công.');

                    return $this->redirect()->toRoute('library/user');
                }
            }
        }

        $view = new ViewModel([
            'form' => $form,
            'mode' => 'add',
        ]);
        $view->setTemplate('library/user/form');

        return $view;
    }

    public function editAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = $this->routeInt('id');

        try {
            $user = $this->userTable->getUser($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());

            return $this->redirect()->toRoute('library/user');
        }

        $form = $this->formElementManager->get(self::EDIT_FORM_SERVICE);
        if (! $form instanceof UserForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu cập nhật tài khoản.');
        }

        if ($this->httpRequest()->isPost()) {
            $payload = $this->postData();
            $payload['id'] = $id;
            $form->setData($payload);

            if ($form->isValid()) {
                /** @var array{username:string, email:string, full_name:string, role:string, password:string} $data */
                $data = $form->getData();

                if ($this->userTable->usernameExists($data['username'], $id)) {
                    $form->get('username')->setMessages(['Tên đăng nhập đã tồn tại.']);
                } elseif ($this->userTable->emailExists($data['email'], $id)) {
                    $form->get('email')->setMessages(['Email đã được sử dụng.']);
                } elseif (
                    $user->role === 'admin'
                    && $data['role'] !== 'admin'
                    && $this->userTable->countByRole('admin') <= 1
                ) {
                    $form->get('role')->setMessages(['Phải luôn duy trì ít nhất một quản trị viên trong hệ thống.']);
                } else {
                    $updated = new User();
                    $updated->exchangeArray($user->getArrayCopy()); // Start with original data
                    $updated->id = $id;
                    $updated->username = $data['username'];
                    $updated->email = $data['email'];
                    $updated->fullName = $data['full_name'];
                    $updated->role = $data['role'];
                    $updated->nickname = $data['nickname'] ?? '';
                    $updated->borrowLimit = (int)($data['borrow_limit'] ?? 5);

                    $passwordHash = $data['password'] !== ''
                        ? password_hash($data['password'], PASSWORD_DEFAULT)
                        : null;

                    $this->userTable->saveUser($updated, $passwordHash);

                    if (($this->currentUser()['id'] ?? 0) === $id) {
                        $session = $this->authSession();
                        $session->user = [
                            'id'        => $updated->id,
                            'username'  => $updated->username,
                            'email'     => $updated->email,
                            'full_name' => $updated->fullName,
                            'role'      => $updated->role,
                        ];
                    }

                    $this->flash()->addSuccessMessage('Đã cập nhật thông tin tài khoản.');

                    return $this->redirect()->toRoute('library/user');
                }
            }
        } else {
            $form->setData(array_merge(
                $user->getArrayCopy(),
                ['password' => '', 'password_confirm' => '']
            ));
        }

        $view = new ViewModel([
            'form' => $form,
            'mode' => 'edit',
            'user' => $user,
        ]);
        $view->setTemplate('library/user/form');

        return $view;
    }

    public function deleteAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (! $this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user');
        }

        $id = $this->routeInt('id');

        try {
            $user = $this->userTable->getUser($id);
        } catch (\RuntimeException $exception) {
            $this->flash()->addErrorMessage($exception->getMessage());

            return $this->redirect()->toRoute('library/user');
        }

        if ($user->role !== 'student') {
            $this->flash()->addErrorMessage('Chỉ có thể xóa tài khoản sinh viên.');

            return $this->redirect()->toRoute('library/user');
        }

        if (($this->currentUser()['id'] ?? 0) === $id) {
            $this->flash()->addErrorMessage('Không thể xóa tài khoản đang đăng nhập.');

            return $this->redirect()->toRoute('library/user');
        }

        if ($this->borrowTable->hasActiveTransactionsForUser($id)) {
            $this->flash()->addErrorMessage('Không thể xóa sinh viên đang mượn sách hoặc có yêu cầu chờ duyệt.');

            return $this->redirect()->toRoute('library/user');
        }

        $this->userTable->deleteUser($id);
        $this->flash()->addSuccessMessage('Đã xóa tài khoản sinh viên thành công.');

        return $this->redirect()->toRoute('library/user');
    }

    public function pendingAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $filters = ['is_approved' => 0];
        $users = $this->userTable->fetchAll($filters);

        return new ViewModel([
            'users' => $users,
        ]);
    }

    public function approveAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user', ['action' => 'pending']);
        }

        $id = $this->routeInt('id');
        try {
            $user = $this->userTable->getUser($id);
            $user->isApproved = true;
            $this->userTable->saveUser($user);

            // Gửi thông báo chào mừng cho sinh viên
            try {
                $stmt = $this->userTable->getAdapter()->createStatement(
                    "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                     VALUES (?, NULL, 'Tài khoản đã được phê duyệt', ?, 'borrow_approved', ?)"
                );
                $stmt->execute([
                    $id,
                    "Chúc mừng! Tài khoản của bạn đã được quản trị viên phê duyệt. Bạn có thể bắt đầu mượn sách ngay bây giờ.",
                    $id
                ]);
            } catch (\Throwable $e) {}

            $this->flash()->addSuccessMessage("Đã phê duyệt tài khoản: " . $user->fullName);
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user', ['action' => 'pending']);
    }

    public function rejectAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user', ['action' => 'pending']);
        }

        $id = $this->routeInt('id');
        try {
            $user = $this->userTable->getUser($id);
            $this->userTable->deleteUser($id);
            $this->flash()->addSuccessMessage("Đã từ chối và xóa tài khoản: " . $user->fullName);
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user', ['action' => 'pending']);
    }

    public function lockAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user');
        }

        $id = $this->routeInt('id');
        $reason = trim((string)($this->postData()['reason'] ?? ''));

        try {
            $user = $this->userTable->getUser($id);
            if ($user->role !== 'student') {
                $this->flash()->addErrorMessage('Chỉ có thể khóa tài khoản sinh viên.');
                return $this->redirect()->toRoute('library/user');
            }
            $this->userTable->lockUser($id, $reason);

            // Gửi thông báo cho sinh viên
            try {
                $currentUser = $this->currentUser();
                $sqlNoti = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                            VALUES (?, ?, 'Tài khoản đã bị khóa', ?, 'borrow_alert', ?)";
                $this->userTable->getAdapter()->query($sqlNoti)->execute([
                    $id,
                    $currentUser['id'],
                    "Tài khoản của bạn đã bị quản trị viên khóa. Lý do: <em>" . htmlspecialchars($reason) . "</em>.",
                    $id
                ]);
            } catch (\Throwable $e) {}

            $this->flash()->addSuccessMessage('Đã khóa tài khoản ' . $user->username . '.');
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user');
    }

    public function unlockAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user');
        }

        $id = $this->routeInt('id');

        try {
            $user = $this->userTable->getUser($id);
            $this->userTable->unlockUser($id);

            // Gửi thông báo cho sinh viên
            try {
                $currentUser = $this->currentUser();
                $sqlNoti = "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                            VALUES (?, ?, 'Tài khoản đã được mở khóa', ?, 'borrow_approved', ?)";
                $this->userTable->getAdapter()->query($sqlNoti)->execute([
                    $id,
                    $currentUser['id'],
                    "Tài khoản của bạn đã được quản trị viên mở khóa. Bạn có thể tiếp tục sử dụng các dịch vụ của thư viện.",
                    $id
                ]);
            } catch (\Throwable $e) {}

            $this->flash()->addSuccessMessage('Đã mở khóa tài khoản ' . $user->username . '.');
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user');
    }

    public function clearNicknameAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user');
        }

        $id = $this->routeInt('id');

        try {
            $user = $this->userTable->getUser($id);
            if ($user->role !== 'student') {
                $this->flash()->addErrorMessage('Chỉ có thể xóa biệt danh của sinh viên.');
                return $this->redirect()->toRoute('library/user', ['action' => 'view', 'id' => $id]);
            }

            $this->userTable->clearNickname($id);
            $this->flash()->addSuccessMessage('Đã xóa biệt danh của thành viên ' . $user->username . '.');
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user', ['action' => 'view', 'id' => $id]);
    }

    public function clearAvatarAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->httpRequest()->isPost()) {
            return $this->redirect()->toRoute('library/user');
        }

        $id = $this->routeInt('id');

        try {
            $user = $this->userTable->getUser($id);
            if ($user->role !== 'student') {
                $this->flash()->addErrorMessage('Chỉ có thể xóa ảnh đại diện của sinh viên.');
                return $this->redirect()->toRoute('library/user', ['action' => 'view', 'id' => $id]);
            }

            // Delete physical file if it exists locally
            if ($user->avatarUrl && str_starts_with($user->avatarUrl, '/img/avatars/')) {
                $path = getcwd() . '/public' . $user->avatarUrl;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }

            $this->userTable->clearAvatar($id);
            $this->flash()->addSuccessMessage('Đã xóa ảnh đại diện của thành viên ' . $user->username . '.');
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user', ['action' => 'view', 'id' => $id]);
    }
}
