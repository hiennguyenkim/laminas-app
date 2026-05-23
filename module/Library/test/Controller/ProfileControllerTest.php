<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\ProfileController;
use Library\Model\Entity\User;
use Library\Model\Table\UserTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\BookTable;
use Library\Session\AuthSessionContainer;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class ProfileControllerTest extends AbstractHttpControllerTestCase
{
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
    }

    public function testIndexActionAsStudent(): void
    {
        $this->mockLoginAsRole('student');

        $user = new User();
        $user->exchangeArray([
            'id' => 2,
            'username' => 'student1',
            'email' => 'student1@library.local',
            'full_name' => 'Nguyễn Văn An',
            'role' => 'student',
            'nickname' => 'AnDepTrai',
        ]);

        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('getUser')
            ->with(2)
            ->willReturn($user);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->method('countTotalBorrowedHistory')->willReturn(5);
        $borrowTableMock->method('countBorrowed')->willReturn(2);
        $borrowTableMock->method('countOverdue')->willReturn(0);
        $borrowTableMock->method('fetchAllWithDetails')->willReturn([]);

        $bookTableMock = $this->createMock(BookTable::class);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(UserTable::class, $userTableMock);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);
        $serviceLocator->setService(BookTable::class, $bookTableMock);

        $this->dispatch('/student/profile', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(ProfileController::class);
        $this->assertMatchedRouteName('student/profile');
    }

    public function testUpdateActionUpdatesDatabaseAndSession(): void
    {
        $this->mockLoginAsRole('student');

        $user = new User();
        $user->exchangeArray([
            'id' => 2,
            'username' => 'student1',
            'email' => 'student1@library.local',
            'full_name' => 'Nguyễn Văn An',
            'role' => 'student',
            'nickname' => '',
            'phone' => '',
            'date_of_birth' => '',
            'avatar_url' => '',
        ]);

        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('getUser')
            ->with(2)
            ->willReturn($user);

        // Verify saveUser is called with correct data values
        $userTableMock->expects(self::once())
            ->method('saveUser')
            ->with(self::callback(function (User $u) {
                return $u->nickname === 'AnTieuHoc' 
                    && $u->phone === '0912345678'
                    && $u->dateOfBirth === '2000-01-01';
            }));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        $postData = [
            'nickname' => 'AnTieuHoc',
            'phone' => '0912345678',
            'date_of_birth' => '2000-01-01',
        ];

        $this->dispatch('/student/profile/update', 'POST', $postData);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/student/profile');

        // Check if auth session was correctly updated
        /** @var AuthSessionContainer $authSession */
        $authSession = $serviceLocator->get(AuthSessionContainer::class);
        $this->assertEquals('AnTieuHoc', $authSession->user['nickname']);
        $this->assertEquals('Nguyễn Văn An', $authSession->user['full_name']);
        $this->assertEquals('', $authSession->user['avatar_url']);
    }

    private function mockLoginAsRole(string $role): void
    {
        /** @var AuthSessionContainer $authSession */
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id' => $role === 'admin' ? 1 : 2,
            'username' => $role === 'admin' ? 'admin' : 'student1',
            'email' => $role === 'admin' ? 'admin@library.local' : 'student1@library.local',
            'full_name' => $role === 'admin' ? 'Quản trị viên' : 'Nguyễn Văn An',
            'role' => $role,
            'avatar_url' => '',
            'nickname' => '',
        ];
    }
}
