<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;

class ProfileController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private UserTable $userTable,
        private BorrowTable $borrowTable
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

        $stats = [
            'total_borrowed' => $this->borrowTable->countBorrowed($userId) + $this->borrowTable->countReturned($userId),
            'active_loans'   => $this->borrowTable->countBorrowed($userId),
            'overdue_count'  => $this->borrowTable->countOverdue($userId),
        ];

        return new ViewModel([
            'user'    => $user,
            'stats'   => $stats,
            'history' => $this->borrowTable->fetchAllWithDetails([], $userId, 50),
        ]);
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
            $files = $this->httpRequest()->getFiles();
            if (isset($files['avatar']) && $files['avatar']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $files['avatar']['tmp_name'];
                $fileName = 'avatar_' . $userId . '_' . time() . '.jpg';
                $destPath = __DIR__ . '/../../../../public/img/avatars/' . $fileName;

                if (!is_dir(dirname($destPath))) {
                    mkdir(dirname($destPath), 0777, true);
                }

                if (move_uploaded_file($tmpName, $destPath)) {
                    $user->avatarUrl = '/img/avatars/' . $fileName;
                }
            }

            $this->userTable->saveUser($user);
            
            // Update session if needed
            $session = $this->authSession();
            $session->user['full_name'] = $user->fullName; // Keep it simple

            $this->flash()->addSuccessMessage('Đã cập nhật hồ sơ thành công.');
        }

        return $this->redirect()->toRoute('library/profile');
    }
}
