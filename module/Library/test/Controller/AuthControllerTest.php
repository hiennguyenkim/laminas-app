<?php

declare(strict_types=1);

namespace Library\Controller {
    class CurlMock
    {
        public static array $responses = [];
        public static array $requests = [];
        public static ?string $error = null;

        public static function reset(): void
        {
            self::$responses = [];
            self::$requests = [];
            self::$error = null;
        }
    }

    function curl_init(?string $url = null)
    {
        CurlMock::$requests[] = $url;
        return $url;
    }

    function curl_setopt($ch, int $option, $value): bool
    {
        return true;
    }

    function curl_exec($ch)
    {
        if (CurlMock::$error !== null) {
            return false;
        }
        return array_shift(CurlMock::$responses);
    }

    function curl_error($ch): string
    {
        return CurlMock::$error ?? '';
    }

    function curl_close($ch): void
    {
    }
}

namespace LibraryTest\Controller {

    use Library\Controller\AuthController;
    use Library\Model\Table\UserTable;
    use Library\Session\AuthSessionContainer;
    use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;
    use PHPUnit\Framework\MockObject\MockObject;

    class AuthControllerTest extends AbstractHttpControllerTestCase
    {
        private MockObject $userTableMock;

        protected function setUp(): void
        {
            $config = include __DIR__ . '/../../../../config/application.config.php';
            $config['module_listener_options']['config_cache_enabled'] = false;
            $config['module_listener_options']['module_map_cache_enabled'] = false;

            $this->setApplicationConfig($config);

            parent::setUp();

            /** @var AuthSessionContainer $authSession */
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            unset($authSession->user);

            // Mock SessionManager to avoid session output issues
            $sessionManagerMock = $this->createMock(\Laminas\Session\SessionManager::class);

            $this->userTableMock = $this->createMock(UserTable::class);

            $mailServiceMock = $this->createMock(\Library\Service\MailService::class);

            $serviceLocator = $this->getApplicationServiceLocator();
            $serviceLocator->setAllowOverride(true);
            $serviceLocator->setService(\Laminas\Session\SessionManager::class, $sessionManagerMock);
            $serviceLocator->setService(UserTable::class, $this->userTableMock);
            $serviceLocator->setService(\Library\Service\MailService::class, $mailServiceMock);

            // Reset Curl Mock
            \Library\Controller\CurlMock::reset();
        }

        public function testGoogleRedirectActionWithoutConfig(): void
        {
            $this->userTableMock->method('getSystemSetting')
                ->willReturnCallback(function (string $key) {
                    if ($key === 'google_client_id') {
                        return '';
                    }
                    return 'some-value';
                });

            $this->dispatch('/auth/google', 'GET');

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Cấu hình Google Login chưa hoàn tất. Vui lòng cập nhật trong Cài đặt hệ thống.',
                $flashMessenger->getCurrentErrorMessages()
            );
        }

        public function testGoogleRedirectActionWithConfig(): void
        {
            $this->userTableMock->method('getSystemSetting')
                ->willReturnCallback(function (string $key) {
                    if ($key === 'google_client_id') {
                        return 'mock-client-id';
                    }
                    if ($key === 'google_client_secret') {
                        return 'mock-client-secret';
                    }
                    if ($key === 'google_redirect_uri') {
                        return 'http://localhost/auth/google-callback';
                    }
                    return '';
                });

            $this->dispatch('/auth/google', 'GET');

            $this->assertResponseStatusCode(302);
            $response = $this->getResponse();
            $headers = $response->getHeaders();
            $this->assertTrue($headers->has('Location'));
            $location = $headers->get('Location')->getFieldValue();
            $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $location);
            $this->assertStringContainsString('client_id=mock-client-id', $location);
            $this->assertStringContainsString('redirect_uri=' . urlencode('http://localhost/auth/google-callback'), $location);
        }

        public function testGoogleCallbackActionWithoutCode(): void
        {
            $this->dispatch('/auth/google-callback', 'GET');

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Không nhận được mã xác thực từ Google.',
                $flashMessenger->getCurrentErrorMessages()
            );
        }

        public function testGoogleCallbackActionExchangeTokenError(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');

            // Force cURL mock to return error
            \Library\Controller\CurlMock::$error = 'Connection Timeout';

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $errorMessages = $flashMessenger->getCurrentErrorMessages();
            $this->assertStringContainsString('Lỗi xử lý Google Login: cURL Error: Connection Timeout', $errorMessages[0]);
        }

        public function testGoogleCallbackActionExchangeTokenJsonError(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');

            // Return token exchange error response
            \Library\Controller\CurlMock::$responses[] = json_encode([
                'error_description' => 'Invalid auth code'
            ]);

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $errorMessages = $flashMessenger->getCurrentErrorMessages();
            $this->assertStringContainsString('Lỗi xử lý Google Login: Lỗi xác thực Token từ Google: Invalid auth code', $errorMessages[0]);
        }

        public function testGoogleCallbackActionUserInfoError(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');

            // Token exchange success
            \Library\Controller\CurlMock::$responses[] = json_encode(['access_token' => 'mock-token']);
            // Userinfo response error
            \Library\Controller\CurlMock::$responses[] = json_encode(['error' => 'not found']);

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $errorMessages = $flashMessenger->getCurrentErrorMessages();
            $this->assertStringContainsString('Không thể lấy thông tin người dùng từ Google', $errorMessages[0]);
        }

        public function testGoogleCallbackActionNewApprovedUserLogin(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');
            $this->userTableMock->method('getByGoogleId')->willReturn(null);
            $this->userTableMock->method('getByEmail')->willReturn(null);

            // Expected new user save (initially, then for OTP)
            $this->userTableMock->expects($this->exactly(2))
                ->method('saveUser')
                ->with(
                    $this->callback(function (\Library\Model\Entity\User $user) {
                        return $user->email === 'new_user@gmail.com'
                            && $user->googleId === '1234567890'
                            && $user->fullName === 'New Google User'
                            && $user->role === 'student'
                            && !$user->isApproved;
                    }),
                    $this->anything()
                );

            // Mock token exchange
            \Library\Controller\CurlMock::$responses[] = json_encode(['access_token' => 'mock-token']);
            // Mock userinfo response
            \Library\Controller\CurlMock::$responses[] = json_encode([
                'sub' => '1234567890',
                'email' => 'new_user@gmail.com',
                'name' => 'New Google User',
                'picture' => 'http://avatar.url'
            ]);

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/verifyOtp');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentInfoMessages());
            $this->assertContains(
                'Mã xác thực OTP đã được gửi đến email của bạn. Vui lòng nhập mã để kích hoạt tài khoản.',
                $flashMessenger->getCurrentInfoMessages()
            );
        }

        public function testGoogleCallbackActionExistingApprovedUser(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');

            $existingUser = new \Library\Model\Entity\User();
            $existingUser->exchangeArray([
                'id' => 12,
                'username' => 'g_34567890',
                'email' => 'existing@gmail.com',
                'google_id' => '1234567890',
                'full_name' => 'Existing User',
                'role' => 'student',
                'is_approved' => true,
            ]);

            $this->userTableMock->method('getByGoogleId')->willReturn($existingUser);

            // Mock token exchange
            \Library\Controller\CurlMock::$responses[] = json_encode(['access_token' => 'mock-token']);
            // Mock userinfo response
            \Library\Controller\CurlMock::$responses[] = json_encode([
                'sub' => '1234567890',
                'email' => 'existing@gmail.com',
                'name' => 'Existing User',
                'picture' => 'http://avatar.url'
            ]);

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/student/dashboard');

            // Check success message
            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
            $this->assertContains(
                'Đăng nhập Google thành công! Chào mừng Existing User',
                $flashMessenger->getCurrentSuccessMessages()
            );

            // Check session user
            /** @var AuthSessionContainer $authSession */
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $this->assertEquals(12, $authSession->user['id']);
            $this->assertEquals('g_34567890', $authSession->user['username']);
            $this->assertEquals('student', $authSession->user['role']);
        }

        public function testGoogleCallbackActionLinkExistingEmailUserApproved(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');
            $this->userTableMock->method('getByGoogleId')->willReturn(null);

            $existingUser = new \Library\Model\Entity\User();
            $existingUser->exchangeArray([
                'id' => 15,
                'username' => 'existing_email_user',
                'email' => 'existing_email@gmail.com',
                'google_id' => '',
                'full_name' => 'Existing Email User',
                'role' => 'student',
                'is_approved' => true,
                'avatar_url' => '',
            ]);
            $this->userTableMock->method('getByEmail')->willReturn($existingUser);

            // Verify saveUser is called to link googleId
            $this->userTableMock->expects($this->once())
                ->method('saveUser')
                ->with($this->callback(function (\Library\Model\Entity\User $user) {
                    return $user->email === 'existing_email@gmail.com'
                        && $user->googleId === '1234567890'
                        && $user->avatarUrl === 'http://avatar.url'
                        && $user->isApproved;
                }));

            // Mock token exchange
            \Library\Controller\CurlMock::$responses[] = json_encode(['access_token' => 'mock-token']);
            // Mock userinfo response
            \Library\Controller\CurlMock::$responses[] = json_encode([
                'sub' => '1234567890',
                'email' => 'existing_email@gmail.com',
                'name' => 'Existing Email User',
                'picture' => 'http://avatar.url'
            ]);

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/student/dashboard');

            // Check success message
            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
            $this->assertContains(
                'Đăng nhập Google thành công! Chào mừng Existing Email User',
                $flashMessenger->getCurrentSuccessMessages()
            );
        }

        public function testGoogleCallbackActionLinkExistingEmailUserNotApproved(): void
        {
            $this->userTableMock->method('getSystemSetting')->willReturn('mock-value');
            $this->userTableMock->method('getByGoogleId')->willReturn(null);

            $existingUser = new \Library\Model\Entity\User();
            $existingUser->exchangeArray([
                'id' => 15,
                'username' => 'existing_email_user',
                'email' => 'existing_email@gmail.com',
                'google_id' => '',
                'full_name' => 'Existing Email User',
                'role' => 'student',
                'is_approved' => false,
                'avatar_url' => '',
            ]);
            $this->userTableMock->method('getByEmail')->willReturn($existingUser);

            // Verify saveUser is called to link googleId
            $this->userTableMock->expects($this->exactly(2))
                ->method('saveUser')
                ->with(
                    $this->callback(function (\Library\Model\Entity\User $user) {
                        return $user->email === 'existing_email@gmail.com'
                            && $user->googleId === '1234567890'
                            && !$user->isApproved;
                    }),
                    $this->anything()
                );

            // Mock token exchange
            \Library\Controller\CurlMock::$responses[] = json_encode(['access_token' => 'mock-token']);
            // Mock userinfo response
            \Library\Controller\CurlMock::$responses[] = json_encode([
                'sub' => '1234567890',
                'email' => 'existing_email@gmail.com',
                'name' => 'Existing Email User',
                'picture' => 'http://avatar.url'
            ]);

            $this->dispatch('/auth/google-callback', 'GET', ['code' => 'mock-code']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/verifyOtp');

            // Check info message
            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentInfoMessages());
            $this->assertContains(
                'Mã xác thực OTP đã được gửi đến email của bạn. Vui lòng nhập mã để kích hoạt tài khoản.',
                $flashMessenger->getCurrentInfoMessages()
            );
        }

        public function testForgotPasswordActionGet(): void
        {
            $this->dispatch('/auth/forgotPassword', 'GET');
            $this->assertResponseStatusCode(200);
            $this->assertModuleName('Library');
            $this->assertControllerName(AuthController::class);
            $this->assertControllerClass('AuthController');
            $this->assertMatchedRouteName('auth');
        }

        public function testForgotPasswordActionPostIdentityEmpty(): void
        {
            $this->dispatch('/auth/forgotPassword', 'POST', ['identity' => '']);
            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/forgotPassword');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Vui lòng nhập tên đăng nhập hoặc email.',
                $flashMessenger->getCurrentErrorMessages()
            );
        }

        public function testForgotPasswordActionPostUserNotFound(): void
        {
            $this->userTableMock->method('getByEmail')->willReturn(null);
            $this->userTableMock->method('getByUsername')->willReturn(null);

            $this->dispatch('/auth/forgotPassword', 'POST', ['identity' => 'nonexistent']);
            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/forgotPassword');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Tên đăng nhập hoặc email không tồn tại.',
                $flashMessenger->getCurrentErrorMessages()
            );
        }

        public function testForgotPasswordActionPostSuccess(): void
        {
            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'username' => 'test_user',
                'email' => 'test@example.com',
                'full_name' => 'Test User',
                'role' => 'student',
            ]);
            $this->userTableMock->method('getByEmail')->willReturn($user);

            $this->userTableMock->expects($this->once())
                ->method('saveUser')
                ->with($this->callback(function (\Library\Model\Entity\User $savedUser) {
                    return $savedUser->id === 99 
                        && !empty($savedUser->otpCode)
                        && !empty($savedUser->otpExpiresAt);
                }));

            $this->dispatch('/auth/forgotPassword', 'POST', ['identity' => 'test@example.com']);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/resetPassword');

            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $this->assertEquals(99, $authSession->resetPasswordUserId);

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentInfoMessages());
        }

        public function testResetPasswordActionGetWithoutSession(): void
        {
            $this->dispatch('/auth/resetPassword', 'GET');
            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/forgotPassword');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
        }

        public function testResetPasswordActionPostSuccess(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;

            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'username' => 'test_user',
                'email' => 'test@example.com',
                'full_name' => 'Test User',
                'role' => 'student',
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);

            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->userTableMock->expects($this->once())
                ->method('saveUser')
                ->with(
                    $this->callback(function (\Library\Model\Entity\User $savedUser) {
                        return $savedUser->id === 99
                            && $savedUser->isApproved === true
                            && empty($savedUser->otpCode)
                            && empty($savedUser->otpExpiresAt);
                    }),
                    $this->callback(function ($hash) {
                        return password_verify('NewPassword@123', $hash);
                    })
                );

            $this->dispatch('/auth/resetPassword', 'POST', [
                'otp_code' => '123456',
                'password' => 'NewPassword@123',
                'password_confirm' => 'NewPassword@123',
            ]);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth'); // login action is default

            $this->assertNull($authSession->resetPasswordUserId);

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
        }

        public function testResetPasswordActionPostPasswordTooShort(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;

            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/resetPassword', 'POST', [
                'otp_code' => '123456',
                'password' => 'S@1',
                'password_confirm' => 'S@1',
            ]);

            $this->assertResponseStatusCode(200); // Renders reset form on validation failure
            $this->assertStringContainsString(
                'Mật khẩu mới phải có độ dài từ 8 ký tự trở lên.',
                $this->getResponse()->getContent()
            );
        }

        public function testResetPasswordActionPostPasswordNoUppercase(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;

            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/resetPassword', 'POST', [
                'otp_code' => '123456',
                'password' => 'password@123',
                'password_confirm' => 'password@123',
            ]);

            $this->assertResponseStatusCode(200);
            $this->assertStringContainsString(
                'Mật khẩu mới phải chứa ít nhất một ký tự in hoa.',
                $this->getResponse()->getContent()
            );
        }

        public function testResetPasswordActionPostPasswordNoSpecialChar(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;

            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/resetPassword', 'POST', [
                'otp_code' => '123456',
                'password' => 'Password123',
                'password_confirm' => 'Password123',
            ]);

            $this->assertResponseStatusCode(200);
            $this->assertStringContainsString(
                'Mật khẩu mới phải chứa ít nhất một ký tự đặc biệt.',
                $this->getResponse()->getContent()
            );
        }

        public function testResetPasswordActionIncorrectOtpIncrementsAttempts(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;
            $authSession->otpAttempts = 2; // already 2 attempts

            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/resetPassword', 'POST', [
                'otp_code' => '999999', // wrong OTP
                'password' => 'NewPass@123',
                'password_confirm' => 'NewPass@123',
            ]);

            $this->assertResponseStatusCode(200);
            $this->assertEquals(3, $authSession->otpAttempts); // incremented to 3
            $this->assertStringContainsString(
                'Mã OTP không chính xác. Bạn còn 2 lần thử.',
                $this->getResponse()->getContent()
            );
        }

        public function testResetPasswordActionIncorrectOtpExceedsLimitInvalidatesOtp(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;
            $authSession->otpAttempts = 4; // 4 incorrect attempts

            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            // Expect userTable->saveUser to clear OTP
            $this->userTableMock->expects($this->once())
                ->method('saveUser')
                ->with($this->callback(function (\Library\Model\Entity\User $savedUser) {
                    return $savedUser->id === 99
                        && empty($savedUser->otpCode)
                        && empty($savedUser->otpExpiresAt);
                }));

            $this->dispatch('/auth/resetPassword', 'POST', [
                'otp_code' => '999999', // 5th incorrect attempt
                'password' => 'NewPass@123',
                'password_confirm' => 'NewPass@123',
            ]);

            $this->assertResponseStatusCode(302);
            $this->assertRedirectTo('/auth/forgotPassword');

            $this->assertNull($authSession->otpAttempts);
            $this->assertNull($authSession->resetPasswordUserId);

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Bạn đã nhập sai mã OTP quá 5 lần. Mã OTP này đã bị hủy vì lý do bảo mật. Vui lòng gửi lại yêu cầu.',
                $flashMessenger->getCurrentErrorMessages()
            );
        }

        public function testVerifyOtpActionGetMasksEmail(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->otpUserId = 99;

            // Scenario 1: short email name (1-2 chars)
            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'email' => 'a@gmail.com',
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/verifyOtp', 'GET');
            $this->assertResponseStatusCode(200);
            
            // Check that the response contains masked email
            $this->assertStringContainsString('a***@gmail.com', $this->getResponse()->getContent());
        }

        public function testResetPasswordActionGetMasksEmail(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;

            // Scenario 2: medium email name (3-5 chars)
            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'email' => 'huy@abc.com',
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/resetPassword', 'GET');
            $this->assertResponseStatusCode(200);

            // Check that the response contains masked email
            $this->assertStringContainsString('hu***@abc.com', $this->getResponse()->getContent());
        }

        public function testResetPasswordActionGetMasksLongEmail(): void
        {
            $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
            $authSession->resetPasswordUserId = 99;

            // Scenario 3: long email name (6+ chars)
            $user = new \Library\Model\Entity\User();
            $user->exchangeArray([
                'id' => 99,
                'email' => 'nguyenvana@gmail.com',
                'otp_code' => '123456',
                'otp_expires_at' => date('Y-m-d H:i:s', time() + 300),
            ]);
            $this->userTableMock->method('getUser')->with(99)->willReturn($user);

            $this->dispatch('/auth/resetPassword', 'GET');
            $this->assertResponseStatusCode(200);

            // Check that the response contains masked email
            $this->assertStringContainsString('ngu***@gmail.com', $this->getResponse()->getContent());
        }
    }
}
