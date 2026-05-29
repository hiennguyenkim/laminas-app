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
                    if (!$user->isApproved) {
                        $this->flash()->addErrorMessage('Tài khoản của bạn đang chờ thủ thư phê duyệt. Vui lòng quay lại sau.');
                        return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
                    }

                    $this->authSession()->user = [
                        'id'         => $user->id,
                        'username'   => $user->username,
                        'email'      => $user->email,
                        'full_name'  => $user->fullName,
                        'role'       => $user->role,
                        'avatar_url' => $user->avatarUrl,
                        'nickname'   => $user->nickname,
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
                /** @var array{username:string, email:string, full_name:string, password:string, password_confirm:string} $data */
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
                        'full_name' => $data['full_name'],
                        'role'      => 'student',
                        'is_approved' => false,
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
        return $this->redirect()->toRoute('announcements');
    }

    public function googleRedirectAction(): Response
    {
        // Clear existing session before social login to avoid role mixing
        $this->sessionManager->destroy();

        try {
            $clientId = $this->userTable->getSystemSetting('google_client_id');
            $clientSecret = $this->userTable->getSystemSetting('google_client_secret');
            $redirectUri = $this->getGoogleRedirectUri();
            
            if (empty($clientId) || empty($clientSecret)) {
                $this->flash()->addErrorMessage('Cấu hình Google Login chưa hoàn tất. Vui lòng cập nhật trong Cài đặt hệ thống.');
                return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
            }

            $url = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'access_type' => 'online'
            ]);

            return $this->redirect()->toUrl($url);
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi khởi tạo Google Login.');
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }
    }

    public function googleCallbackAction(): Response
    {
        $code = $this->params()->fromQuery('code');
        if (!$code) {
            $this->flash()->addErrorMessage('Không nhận được mã xác thực từ Google.');
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        try {
            $clientId = $this->userTable->getSystemSetting('google_client_id');
            $clientSecret = $this->userTable->getSystemSetting('google_client_secret');
            $redirectUri = $this->getGoogleRedirectUri();

            // 1. Exchange code for access token
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            // Chỉ tắt SSL verify trên môi trường local (localhost/127.0.0.1)
            $isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$isLocal);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code'
            ]));
            $response = curl_exec($ch);
            
            if ($response === false) {
                throw new RuntimeException('cURL Error: ' . curl_error($ch));
            }
            curl_close($ch);
            $tokenData = json_decode((string)$response, true);

            if (!isset($tokenData['access_token'])) {
                throw new RuntimeException('Lỗi xác thực Token từ Google: ' . ($tokenData['error_description'] ?? 'Unknown'));
            }

            // 2. Get user info
            $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo?access_token=' . $tokenData['access_token']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$isLocal); // Dùng lại biến $isLocal đã tính
            $userInfoResponse = curl_exec($ch);
            curl_close($ch);
            $googleUser = json_decode($userInfoResponse, true);

            if (!isset($googleUser['sub'])) {
                throw new RuntimeException('Không thể lấy thông tin người dùng từ Google.');
            }

            $googleId = (string)$googleUser['sub'];
            $email = (string)$googleUser['email'];
            $name = (string)($googleUser['name'] ?? $googleUser['given_name'] ?? 'Google User');
            $avatar = (string)($googleUser['picture'] ?? '');

            // 3. Find or Create user
            $user = $this->userTable->getByGoogleId($googleId);
            if (!$user) {
                // Check if email already exists
                $userByEmail = $this->userTable->getByEmail($email);
                if ($userByEmail) {
                    // Link existing email to Google ID
                    $userByEmail->googleId = $googleId;
                    if (empty($userByEmail->avatarUrl)) $userByEmail->avatarUrl = $avatar;
                    $this->userTable->saveUser($userByEmail);
                    $user = $userByEmail;
                } else {
                    // Create new student account
                    $user = new User();
                    $user->exchangeArray([
                        'username' => 'g_' . substr($googleId, -8),
                        'email' => $email,
                        'google_id' => $googleId,
                        'full_name' => $name,
                        'avatar_url' => $avatar,
                        'role' => 'student',
                        'is_approved' => false,
                    ]);
                    $this->userTable->saveUser($user, bin2hex(random_bytes(16))); // Random pass for social account
                }
            }

            // 4. Check approval before login
            if (!$user->isApproved) {
                $this->flash()->addErrorMessage('Tài khoản Google của bạn đang chờ thủ thư phê duyệt. Vui lòng quay lại sau.');
                return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
            }

            // 5. Log in
            $this->authSession()->user = [
                'id'         => $user->id,
                'username'   => $user->username,
                'email'      => $user->email,
                'full_name'  => $user->fullName,
                'role'       => $user->role,
                'avatar_url' => $user->avatarUrl,
                'nickname'   => $user->nickname,
            ];

            $this->flash()->addSuccessMessage('Đăng nhập Google thành công! Chào mừng ' . $user->fullName);
            return $this->redirectToRoleHome($user->role);

        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi xử lý Google Login: ' . $e->getMessage());
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }
    }

    private function redirectToRoleHome(string $role): Response
    {
        if ($role === 'admin') {
            return $this->redirect()->toRoute('library/dashboard');
        }
        if ($role === 'student') {
            return $this->redirect()->toRoute('student/dashboard');
        }

        return $this->redirect()->toRoute('announcements');
    }

    private function getGoogleRedirectUri(): string
    {
        $request = $this->getRequest();
        if (!method_exists($request, 'getUri')) {
            return '';
        }
        $uri = $request->getUri();
        $scheme = $uri->getScheme() ?: 'http';
        $host = $uri->getHost() ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $port = $uri->getPort();
        $portStr = ($port && !in_array($port, [80, 443])) ? ':' . $port : '';

        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $baseUrl = rtrim(dirname($scriptName), '/\\');

        return $scheme . '://' . $host . $portStr . $baseUrl . '/auth/google-callback';
    }
}
