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

            $serviceLocator = $this->getApplicationServiceLocator();
            $serviceLocator->setAllowOverride(true);
            $serviceLocator->setService(\Laminas\Session\SessionManager::class, $sessionManagerMock);
            $serviceLocator->setService(UserTable::class, $this->userTableMock);

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
            $this->assertRedirectTo('/admin/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Cấu hình Google Login chưa hoàn tất (Thiếu Client Secret). Vui lòng cập nhật trong Cài đặt hệ thống.',
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
            $this->assertRedirectTo('/admin/auth');

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
            $this->assertRedirectTo('/admin/auth');

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
            $this->assertRedirectTo('/admin/auth');

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
            $this->assertRedirectTo('/admin/auth');

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

            // Expected new user save
            $this->userTableMock->expects($this->once())
                ->method('saveUser')
                ->with($this->callback(function (\Library\Model\Entity\User $user) {
                    return $user->email === 'new_user@gmail.com'
                        && $user->googleId === '1234567890'
                        && $user->fullName === 'New Google User'
                        && $user->role === 'student'
                        && !$user->isApproved;
                }), $this->stringContains(''));

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
            $this->assertRedirectTo('/admin/auth');

            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Tài khoản Google của bạn đang chờ thủ thư phê duyệt. Vui lòng quay lại sau.',
                $flashMessenger->getCurrentErrorMessages()
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
            $this->userTableMock->expects($this->once())
                ->method('saveUser')
                ->with($this->callback(function (\Library\Model\Entity\User $user) {
                    return $user->email === 'existing_email@gmail.com'
                        && $user->googleId === '1234567890'
                        && !$user->isApproved;
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
            $this->assertRedirectTo('/admin/auth');

            // Check error message
            $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
            $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
            $this->assertContains(
                'Tài khoản Google của bạn đang chờ thủ thư phê duyệt. Vui lòng quay lại sau.',
                $flashMessenger->getCurrentErrorMessages()
            );
        }
    }
}
