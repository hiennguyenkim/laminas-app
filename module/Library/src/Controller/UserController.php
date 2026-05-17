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
            'search' => trim($this->queryString('search')),
            'role'   => $this->queryString('role'),
        ];

        $currentUser = $this->currentUser() ?? [
            'id' => 0,
            'username' => '',
            'email' => '',
            'full_name' => '',
            'role' => '',
        ];

        return new ViewModel([
            'users'     => $this->userTable->fetchAll($filters),
            'filters'   => $filters,
            'isAdmin'   => true,
            'summary'   => [
                'total'    => $this->userTable->countAll(),
                'admins'   => $this->userTable->countByRole('admin'),
                'students' => $this->userTable->countByRole('student'),
            ],
            'currentId' => $currentUser['id'],
        ]);
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
        $hasBorrowHistory = $this->borrowTable->hasBorrowHistoryForUser($id);
        $canDelete = $user->role === 'student'
            && $id !== $currentId
            && ! $hasBorrowHistory;

        $deleteBlockedReason = null;
        if (! $canDelete) {
            if ($user->role !== 'student') {
                $deleteBlockedReason = 'Chỉ có thể xóa tài khoản sinh viên.';
            } elseif ($id === $currentId) {
                $deleteBlockedReason = 'Không thể xóa tài khoản đang đăng nhập.';
            } elseif ($hasBorrowHistory) {
                $deleteBlockedReason = 'Không thể xóa sinh viên đã có lịch sử mượn/trả.';
            }
        }

        return new ViewModel([
            'user'               => $user,
            'currentId'          => $currentId,
            'activeLoans'        => $this->borrowTable->countActiveLoansForUser($id),
            'hasOverdueLoans'    => $this->borrowTable->hasOverdueLoans($id),
            'hasBorrowHistory'   => $hasBorrowHistory,
            'borrowRecords'      => $this->borrowTable->fetchAllWithDetails([], $id),
            'canDelete'          => $canDelete,
            'deleteBlockedReason' => $deleteBlockedReason,
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
                /** @var array{username:string, email:string, full_name:string, role:string, password:string} $data */
                $data = $form->getData();

                if ($this->userTable->usernameExists($data['username'])) {
                    $form->get('username')->setMessages(['Tên đăng nhập đã tồn tại.']);
                } elseif ($this->userTable->emailExists($data['email'])) {
                    $form->get('email')->setMessages(['Email đã được sử dụng.']);
                } else {
                    $user = new User();
                    $user->exchangeArray($data);
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
                    $updated->exchangeArray([
                        'id'        => $id,
                        'username'  => $data['username'],
                        'email'     => $data['email'],
                        'full_name' => $data['full_name'],
                        'role'      => $data['role'],
                    ]);

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

        if ($this->borrowTable->hasBorrowHistoryForUser($id)) {
            $this->flash()->addErrorMessage('Không thể xóa sinh viên đã có lịch sử mượn/trả.');

            return $this->redirect()->toRoute('library/user');
        }

        $this->userTable->deleteUser($id);
        $this->flash()->addSuccessMessage('Đã xóa tài khoản sinh viên thành công.');

        return $this->redirect()->toRoute('library/user');
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
            $this->flash()->addSuccessMessage('Đã mở khóa tài khoản ' . $user->username . '.');
        } catch (\Exception $e) {
            $this->flash()->addErrorMessage($e->getMessage());
        }

        return $this->redirect()->toRoute('library/user');
    }
}
