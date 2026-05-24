<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Model\Table\BookReviewTable;
use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;

class ProfileController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private UserTable $userTable,
        private BorrowTable $borrowTable,
        private BookTable $bookTable,
        private BookReviewTable $bookReviewTable
    ) {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $userId = $this->currentUser()['id'] ?? 0;
        $user = $this->userTable->getUser($userId);
        $isAdmin = $this->isAdmin();

        $stats = [
            'total_borrowed' => $this->borrowTable->countTotalBorrowedHistory([], $userId),
            'active_loans'   => $this->borrowTable->countBorrowed([], $userId),
            'overdue_count'  => $this->borrowTable->countOverdue([], $userId),
            'review_count'   => 0,
        ];

        try {
            $stats['review_count'] = $this->bookReviewTable->countReviewsByUser($userId);
        } catch (\Throwable $e) {
            // Fallback for tests/environments without DB
        }

        $adminStats = [];
        if ($isAdmin) {
            $bookSummary = $this->bookTable->getSummary();
            $adminStats = [
                'total_categories' => $this->bookTable->countCategories(),
                'total_titles'     => $bookSummary['total_titles'],
                'total_books'      => $bookSummary['total_copies'],
                'total_members'    => $this->userTable->countByRole('student'),
                'active_loans'     => $this->borrowTable->countBorrowed([], null),
            ];
        }

        $page = max(1, (int)$this->params()->fromQuery('page', 1));
        $search = trim((string)$this->params()->fromQuery('search', ''));
        $status = trim((string)$this->params()->fromQuery('status', ''));
        $sort = trim((string)$this->params()->fromQuery('sort', 'borrow_date'));
        if ($sort === '') {
            $sort = 'borrow_date';
        }
        $direction = strtoupper(trim((string)$this->params()->fromQuery('direction', 'DESC')));
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }
        $perPage = 5;

        $filters = [
            'search'    => $search,
            'status'    => $status,
            'sort'      => $sort,
            'direction' => $direction,
        ];

        $totalCount = $isAdmin ? 0 : $this->borrowTable->countFiltered($filters, $userId);
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $history = $isAdmin ? [] : $this->borrowTable->fetchAllWithDetails($filters, $userId, $perPage, $offset);
        $reviewedBookIds = $isAdmin ? [] : $this->bookReviewTable->getReviewedBookIds($userId);

        $viewModel = new ViewModel([
            'user'            => $user,
            'stats'           => $stats,
            'isAdmin'         => $isAdmin,
            'adminStats'      => $adminStats,
            'history'         => $history,
            'reviewedBookIds' => $reviewedBookIds,
            'page'            => $page,
            'totalPages'      => $totalPages,
            'totalCount'      => $totalCount,
            'perPage'         => $perPage,
            'search'          => $search,
            'filters'         => $filters,
        ]);

        if ($isAdmin) {
            $viewModel->setTemplate('library/profile/index-admin');
        } else {
            $viewModel->setTemplate('library/profile/index');
        }

        return $viewModel;
    }

    public function updateAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $userId = $this->currentUser()['id'] ?? 0;
        $user = $this->userTable->getUser($userId);

        if ($this->httpRequest()->isPost()) {
            $post = $this->postData();

            $user->nickname    = trim((string)($post['nickname'] ?? ''));
            $user->phone       = trim((string)($post['phone'] ?? ''));
            $user->dateOfBirth = trim((string)($post['date_of_birth'] ?? ''));

            // Handle Avatar Upload
            $file = $_FILES['avatar'] ?? null;
            if ($file && $file['error'] === UPLOAD_ERR_OK) {
                $tmpName = $file['tmp_name'];
                
                // Get extension
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
                
                if (in_array($ext, $allowed)) {
                    $maxSize = 2 * 1024 * 1024; // 2 MB
                    if ($file['size'] <= $maxSize) {
                        $fileName = 'avatar_' . $userId . '_' . time() . '.' . $ext;
                        
                        $publicDir = getcwd();
                        if (basename($publicDir) !== 'public') {
                            $publicDir .= '/public';
                        }
                        $uploadDir = $publicDir . '/img/avatars/';

                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $destPath = $uploadDir . $fileName;
                        if (move_uploaded_file($tmpName, $destPath)) {
                            // Delete old avatar if it exists
                            if ($user->avatarUrl && str_starts_with($user->avatarUrl, '/img/avatars/')) {
                                $oldPath = $publicDir . $user->avatarUrl;
                                if (file_exists($oldPath)) {
                                    @unlink($oldPath);
                                }
                            }
                            $user->avatarUrl = '/img/avatars/' . $fileName;
                        } else {
                            $this->flash()->addErrorMessage('Không thể lưu file ảnh đại diện vào thư mục.');
                        }
                    } else {
                        $this->flash()->addErrorMessage('File ảnh đại diện quá lớn. Tối đa 2MB.');
                    }
                } else {
                    $this->flash()->addErrorMessage('Định dạng ảnh đại diện không hỗ trợ. Chấp nhận: png, jpg, jpeg, webp, gif.');
                }
            } elseif ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
                $this->flash()->addErrorMessage('Lỗi tải lên ảnh đại diện. Mã lỗi: ' . $file['error']);
            }

            $this->userTable->saveUser($user);
            
            // Update session if needed
            $session = $this->authSession();
            $session->user['full_name']  = $user->fullName;
            $session->user['nickname']   = $user->nickname;
            $session->user['avatar_url'] = $user->avatarUrl;

            $this->flash()->addSuccessMessage('Đã cập nhật hồ sơ thành công.');
        }

        return $this->redirect()->toRoute($this->routeForRole('profile'));
    }
}
