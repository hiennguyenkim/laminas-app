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
        private SessionManager $sessionManager,
        private \Library\Service\MailService $mailService
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
                        try {
                            $this->sendOtpToUser($user);
                            $this->authSession()->otpUserId = $user->id;
                            $this->flash()->addInfoMessage('Mã xác thực OTP đã được gửi đến email của bạn. Vui lòng nhập mã để kích hoạt tài khoản.');
                            return $this->redirect()->toRoute('library/auth', ['action' => 'verifyOtp']);
                        } catch (\Throwable $e) {
                            $this->flash()->addErrorMessage('Không thể gửi mã OTP: ' . $e->getMessage());
                            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
                        }
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

                    try {
                        $this->sendOtpToUser($user);
                        $this->authSession()->otpUserId = $user->id;
                        $this->flash()->addSuccessMessage('Đăng ký thành công! Mã xác thực OTP đã được gửi đến email của bạn. Vui lòng nhập mã để kích hoạt tài khoản.');
                        return $this->redirect()->toRoute('library/auth', ['action' => 'verifyOtp']);
                    } catch (\Throwable $e) {
                        $this->flash()->addWarningMessage('Đăng ký thành công, nhưng không thể gửi mã OTP: ' . $e->getMessage() . '. Vui lòng đăng nhập để gửi lại mã.');
                        return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
                    }
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

            // 4. Check approval before login -> Verify via OTP
            if (!$user->isApproved) {
                try {
                    $this->sendOtpToUser($user);
                    $this->authSession()->otpUserId = $user->id;
                    $this->flash()->addInfoMessage('Mã xác thực OTP đã được gửi đến email của bạn. Vui lòng nhập mã để kích hoạt tài khoản.');
                    return $this->redirect()->toRoute('library/auth', ['action' => 'verifyOtp']);
                } catch (\Throwable $e) {
                    $this->flash()->addErrorMessage('Không thể gửi mã OTP: ' . $e->getMessage());
                    return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
                }
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

    public function verifyOtpAction(): Response|ViewModel
    {
        $otpUserId = $this->authSession()->otpUserId ?? null;
        if (!$otpUserId) {
            $this->flash()->addErrorMessage('Phiên xác thực không hợp lệ hoặc đã hết hạn.');
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        try {
            $user = $this->userTable->getUser((int)$otpUserId);
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Không tìm thấy thông tin tài khoản.');
            return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
        }

        // Resend OTP logic
        if ($this->params()->fromQuery('resend') === '1') {
            try {
                $this->sendOtpToUser($user);
                $this->flash()->addSuccessMessage('Mã OTP mới đã được gửi lại vào email của bạn.');
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Không thể gửi lại mã OTP: ' . $e->getMessage());
            }
            return $this->redirect()->toRoute('library/auth', ['action' => 'verifyOtp']);
        }

        $request = $this->httpRequest();
        if ($request->isPost()) {
            $otpInput = trim((string)($this->postData()['otp_code'] ?? ''));
            
            if ($otpInput === '') {
                $this->flash()->addErrorMessage('Vui lòng nhập mã OTP.');
            } elseif ($user->otpCode !== $otpInput) {
                $this->flash()->addErrorMessage('Mã OTP không chính xác.');
            } elseif (strtotime($user->otpExpiresAt) < time()) {
                $this->flash()->addErrorMessage('Mã OTP đã hết hạn. Vui lòng nhấn gửi lại mã.');
            } else {
                // Success! Approve and log in
                $user->isApproved = true;
                $user->otpCode = '';
                $user->otpExpiresAt = '';
                $this->userTable->saveUser($user);

                // Add system notification for user approval
                try {
                    $stmt = $this->userTable->getAdapter()->createStatement(
                        "INSERT INTO notifications (user_id, sender_id, title, message, type, related_id) 
                         VALUES (?, NULL, 'Tài khoản đã được phê duyệt', ?, 'borrow_approved', ?)"
                    );
                    $stmt->execute([
                        $user->id,
                        "Chúc mừng! Tài khoản của bạn đã được phê duyệt tự động qua xác thực OTP thành công.",
                        $user->id
                    ]);
                } catch (\Throwable $e) {}

                // Establish session
                $this->authSession()->user = [
                    'id'         => $user->id,
                    'username'   => $user->username,
                    'email'      => $user->email,
                    'full_name'  => $user->fullName,
                    'role'       => $user->role,
                    'avatar_url' => $user->avatarUrl,
                    'nickname'   => $user->nickname,
                ];
                unset($this->authSession()->otpUserId);

                $this->flash()->addSuccessMessage('Xác thực OTP thành công! Chào mừng ' . $user->fullName);
                return $this->redirectToRoleHome($user->role);
            }
        }

        return new ViewModel([
            'email' => $user->email,
        ]);
    }

    public function forgotPasswordAction(): Response|ViewModel
    {
        $currentUser = $this->currentUser();
        if ($currentUser !== null) {
            return $this->redirectToRoleHome($currentUser['role'] ?? '');
        }

        $request = $this->httpRequest();
        if ($request->isPost()) {
            $identity = trim((string)($this->postData()['identity'] ?? ''));
            if ($identity === '') {
                $this->flash()->addErrorMessage('Vui lòng nhập tên đăng nhập hoặc email.');
                return $this->redirect()->toRoute('library/auth', ['action' => 'forgotPassword']);
            }

            $user = null;
            if (filter_var($identity, FILTER_VALIDATE_EMAIL)) {
                $user = $this->userTable->getByEmail($identity);
            }
            if (!$user) {
                $user = $this->userTable->getByUsername($identity);
            }

            if (!$user) {
                $this->flash()->addErrorMessage('Tên đăng nhập hoặc email không tồn tại.');
                return $this->redirect()->toRoute('library/auth', ['action' => 'forgotPassword']);
            }

            try {
                $this->sendPasswordResetOtpToUser($user);
                $this->authSession()->resetPasswordUserId = $user->id;
                $this->flash()->addInfoMessage('Mã OTP khôi phục mật khẩu đã được gửi đến email của bạn.');
                return $this->redirect()->toRoute('library/auth', ['action' => 'resetPassword']);
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Không thể gửi mã OTP: ' . $e->getMessage());
                return $this->redirect()->toRoute('library/auth', ['action' => 'forgotPassword']);
            }
        }

        return new ViewModel();
    }

    public function resetPasswordAction(): Response|ViewModel
    {
        $currentUser = $this->currentUser();
        if ($currentUser !== null) {
            return $this->redirectToRoleHome($currentUser['role'] ?? '');
        }

        $resetPasswordUserId = $this->authSession()->resetPasswordUserId ?? null;
        if (!$resetPasswordUserId) {
            $this->flash()->addErrorMessage('Phiên khôi phục mật khẩu không hợp lệ hoặc đã hết hạn.');
            return $this->redirect()->toRoute('library/auth', ['action' => 'forgotPassword']);
        }

        try {
            $user = $this->userTable->getUser((int)$resetPasswordUserId);
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Không tìm thấy thông tin tài khoản.');
            return $this->redirect()->toRoute('library/auth', ['action' => 'forgotPassword']);
        }

        // Resend OTP logic
        if ($this->params()->fromQuery('resend') === '1') {
            try {
                $this->sendPasswordResetOtpToUser($user);
                $this->flash()->addSuccessMessage('Mã OTP mới đã được gửi lại vào email của bạn.');
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Không thể gửi lại mã OTP: ' . $e->getMessage());
            }
            return $this->redirect()->toRoute('library/auth', ['action' => 'resetPassword']);
        }

        $request = $this->httpRequest();
        if ($request->isPost()) {
            $otpInput = trim((string)($this->postData()['otp_code'] ?? ''));
            $newPassword = (string)($this->postData()['password'] ?? '');
            $confirmPassword = (string)($this->postData()['password_confirm'] ?? '');

            // Initialize or increment OTP attempts count in session
            if (!isset($this->authSession()->otpAttempts)) {
                $this->authSession()->otpAttempts = 0;
            }

            if ($otpInput === '') {
                $this->flash()->addErrorMessage('Vui lòng nhập mã OTP.');
            } elseif ($user->otpCode !== $otpInput) {
                $this->authSession()->otpAttempts++;
                
                if ($this->authSession()->otpAttempts >= 5) {
                    // Invalidate current OTP
                    $user->otpCode = '';
                    $user->otpExpiresAt = '';
                    $this->userTable->saveUser($user);
                    
                    unset($this->authSession()->otpAttempts);
                    unset($this->authSession()->resetPasswordUserId);
                    
                    $this->flash()->addErrorMessage('Bạn đã nhập sai mã OTP quá 5 lần. Mã OTP này đã bị hủy vì lý do bảo mật. Vui lòng gửi lại yêu cầu.');
                    return $this->redirect()->toRoute('library/auth', ['action' => 'forgotPassword']);
                }
                
                $remaining = 5 - $this->authSession()->otpAttempts;
                $this->flash()->addErrorMessage('Mã OTP không chính xác. Bạn còn ' . $remaining . ' lần thử.');
            } elseif (strtotime($user->otpExpiresAt) < time()) {
                $this->flash()->addErrorMessage('Mã OTP đã hết hạn. Vui lòng nhấn gửi lại mã.');
            } elseif (strlen($newPassword) < 8) {
                $this->flash()->addErrorMessage('Mật khẩu mới phải có độ dài từ 8 ký tự trở lên.');
            } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                $this->flash()->addErrorMessage('Mật khẩu mới phải chứa ít nhất một ký tự in hoa.');
            } elseif (!preg_match('/[^a-zA-Z0-9\s]/', $newPassword)) {
                $this->flash()->addErrorMessage('Mật khẩu mới phải chứa ít nhất một ký tự đặc biệt.');
            } elseif ($newPassword !== $confirmPassword) {
                $this->flash()->addErrorMessage('Mật khẩu mới và xác nhận mật khẩu không khớp.');
            } else {
                // Success! Set new password, activate if not approved, clear OTP fields
                $user->isApproved = true; // Auto approve since email is verified
                $user->otpCode = '';
                $user->otpExpiresAt = '';
                
                $this->userTable->saveUser($user, password_hash($newPassword, PASSWORD_DEFAULT));
                
                // Clear session trackers
                unset($this->authSession()->resetPasswordUserId);
                unset($this->authSession()->otpAttempts);

                $this->flash()->addSuccessMessage('Đặt lại mật khẩu thành công! Vui lòng đăng nhập bằng mật khẩu mới.');
                return $this->redirect()->toRoute('library/auth', ['action' => 'login']);
            }
        }

        return new ViewModel([
            'email' => $user->email,
        ]);
    }

    private function sendPasswordResetOtpToUser(\Library\Model\Entity\User $user): void
    {
        $otp = sprintf('%06d', random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        $user->otpCode = $otp;
        $user->otpExpiresAt = $expiresAt;
        $this->userTable->saveUser($user);

        // Send Email
        $subject = "[Thư viện HDPE] Mã OTP khôi phục mật khẩu";
        $body = "Chào " . $user->fullName . ",\n\n"
              . "Bạn nhận được email này vì đã gửi yêu cầu khôi phục mật khẩu.\n"
              . "Mã xác thực OTP của bạn là: " . $otp . "\n"
              . "Mã OTP này có hiệu lực trong vòng 5 phút.\n\n"
              . "Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email này.\n\n"
              . "Trân trọng,\n"
              . "Thư viện HDPE";
              
        $this->mailService->sendEmail($user->email, $user->fullName, $subject, $body);
    }

    private function sendOtpToUser(\Library\Model\Entity\User $user): void
    {
        $otp = sprintf('%06d', random_int(0, 999999));
        $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

        $user->otpCode = $otp;
        $user->otpExpiresAt = $expiresAt;
        $this->userTable->saveUser($user);

        // Send Email
        $subject = "[Thư viện HDPE] Mã xác thực OTP kích hoạt tài khoản";
        $body = "Chào " . $user->fullName . ",\n\n"
              . "Bạn hoặc ai đó vừa thực hiện yêu cầu đăng nhập/đăng ký tài khoản.\n"
              . "Mã xác thực OTP của bạn là: " . $otp . "\n"
              . "Mã OTP này có hiệu lực trong vòng 5 phút.\n\n"
              . "Vui lòng nhập mã này vào trang xác thực để kích hoạt tài khoản.\n\n"
              . "Trân trọng,\n"
              . "Thư viện HDPE";
              
        $this->mailService->sendEmail($user->email, $user->fullName, $subject, $body);
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
        if (str_contains($scriptName, 'phpunit') || str_contains($scriptName, 'vendor')) {
            $baseUrl = '';
        } else {
            $baseUrl = rtrim(dirname($scriptName), '/\\');
        }

        return $scheme . '://' . $host . $portStr . $baseUrl . '/auth/google-callback';
    }
}
