<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Form\LoginForm;
use Library\Form\RegisterForm;
use Library\Model\Entity\User;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Form\FormElementManager;
use Laminas\Http\Response;
use Laminas\Session\SessionManager;
use Laminas\View\Model\ViewModel;
use RuntimeException;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class AuthController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private UserTable $userTable,
        private FormElementManager $formElementManager,
        private SessionManager $sessionManager
    ) {
        parent::__construct($authSessionContainer);
    }

    public function loginAction(): Response|ViewModel
    {
        $currentUser = $this->currentUser();
        if ($currentUser !== null) {
            return $this->redirectToRoleHome($currentUser['role'] ?? '');
        }

        $form = $this->formElementManager->get(LoginForm::class);
        if (! $form instanceof LoginForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu đăng nhập.');
        }

        $request = $this->httpRequest();

        if ($request->isPost()) {
            $form->setData($this->postData());
            if ($form->isValid()) {
                /** @var array{username:string, password:string} $data */
                $data = $form->getData();
                $user = $this->userTable->getByUsername($data['username']);

                if ($user && password_verify($data['password'], $user->password)) {
                    $this->authSession()->user = [
                        'id'        => $user->id,
                        'username'  => $user->username,
                        'email'     => $user->email,
                        'full_name' => $user->fullName,
                        'role'      => $user->role,
                    ];
                    $this->flash()->addSuccessMessage('Chào mừng ' . $user->fullName . '!');
                    return $this->redirectToRoleHome($user->role);
                }

                $this->flash()->addErrorMessage('Tên đăng nhập hoặc mật khẩu không đúng.');
            }
        }

        return new ViewModel(['form' => $form]);
    }

    public function registerAction(): Response|ViewModel
    {
        $currentUser = $this->currentUser();
        if ($currentUser !== null) {
            return $this->redirectToRoleHome($currentUser['role'] ?? '');
        }

        $form = $this->formElementManager->get(RegisterForm::class);
        if (! $form instanceof RegisterForm) {
            throw new RuntimeException('Không thể khởi tạo biểu mẫu đăng ký.');
        }

        if ($this->httpRequest()->isPost()) {
            $form->setData($this->postData());

            if ($form->isValid()) {
                /** @var array{username:string, email:string, password:string, password_confirm:string} $data */
                $data = $form->getData();

                if ($this->userTable->usernameExists($data['username'])) {
                    $form->get('username')->setMessages(['Tên đăng nhập đã tồn tại.']);
                } elseif ($this->userTable->emailExists($data['email'])) {
                    $form->get('email')->setMessages(['Email đã được sử dụng.']);
                } else {
                    $user = new User();
                    $user->exchangeArray([
                        'username'  => $data['username'],
                        'email'     => $data['email'],
                        'full_name' => $data['username'],
                        'role'      => 'student',
                    ]);

                    $this->userTable->saveUser(
                        $user,
                        password_hash($data['password'], PASSWORD_DEFAULT)
                    );

                    $this->flash()->addSuccessMessage('Đăng ký thành công. Vui lòng đăng nhập.');

                    return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
                }
            }
        }

        return new ViewModel(['form' => $form]);
    }

    public function logoutAction(): Response
    {
        $this->sessionManager->destroy();
        $this->flash()->addInfoMessage('Bạn đã đăng xuất.');
        return $this->redirect()->toRoute('catalog');
    }

    private function redirectToRoleHome(string $role): Response
    {
        if ($role === 'admin') {
            return $this->redirect()->toRoute('library/book');
        }

        return $this->redirect()->toRoute('catalog');
    }
}
