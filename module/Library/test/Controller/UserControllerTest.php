<?php
 
declare(strict_types=1);
 
namespace LibraryTest\Controller;
 
use Library\Controller\UserController;
use Library\Model\Entity\User;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;
use Laminas\Db\ResultSet\ResultSet;
 
class UserControllerTest extends AbstractHttpControllerTestCase
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
 
    public function testIndexActionRequiresAdmin(): void
    {
        $this->dispatch('/admin/users', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/auth');
    }
 
    public function testIndexActionWithDefaultPagination(): void
    {
        $this->mockLoginAsRole('admin');
 
        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('countFiltered')
            ->with(['search' => '', 'role' => ''])
            ->willReturn(15);
 
        $resultSet = new ResultSet();
        $resultSet->initialize([]);
        $userTableMock->expects(self::once())
            ->method('fetchPage')
            ->with(['search' => '', 'role' => ''], 1, 10)
            ->willReturn($resultSet);
 
        $userTableMock->expects(self::once())
            ->method('countAll')
            ->willReturn(15);
 
        $userTableMock->expects(self::exactly(2))
            ->method('countByRole')
            ->willReturnMap([
                ['admin', 2],
                ['student', 13],
            ]);
 
        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(UserTable::class, $userTableMock);
 
        $this->dispatch('/admin/users', 'GET');
 
        $this->assertResponseStatusCode(200);
        $this->assertControllerName(UserController::class);
        $this->assertMatchedRouteName('library/user');
    }
 
    public function testIndexActionWithCustomPerPageAndRoleFilter(): void
    {
        $this->mockLoginAsRole('admin');
 
        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('countFiltered')
            ->with(['search' => 'Nguyễn', 'role' => 'admin'])
            ->willReturn(3);
 
        $resultSet = new ResultSet();
        $resultSet->initialize([]);
        $userTableMock->expects(self::once())
            ->method('fetchPage')
            ->with(['search' => 'Nguyễn', 'role' => 'admin'], 1, 20)
            ->willReturn($resultSet);
 
        $userTableMock->expects(self::once())
            ->method('countAll')
            ->willReturn(15);
 
        $userTableMock->expects(self::exactly(2))
            ->method('countByRole')
            ->willReturnMap([
                ['admin', 2],
                ['student', 13],
            ]);
 
        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(UserTable::class, $userTableMock);
 
        $this->dispatch('/admin/users?search=Nguyễn&role=admin&perPage=20', 'GET');
 
        $this->assertResponseStatusCode(200);
        $this->assertControllerName(UserController::class);
        $this->assertMatchedRouteName('library/user');
    }
 
    public function testIndexActionWithPerPageAll(): void
    {
        $this->mockLoginAsRole('admin');
 
        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('countFiltered')
            ->with(['search' => '', 'role' => ''])
            ->willReturn(60);
 
        $resultSet = new ResultSet();
        $resultSet->initialize([]);
        // "all" converts perPage to 999999
        $userTableMock->expects(self::once())
            ->method('fetchPage')
            ->with(['search' => '', 'role' => ''], 1, 999999)
            ->willReturn($resultSet);
 
        $userTableMock->expects(self::once())
            ->method('countAll')
            ->willReturn(60);
 
        $userTableMock->expects(self::exactly(2))
            ->method('countByRole')
            ->willReturnMap([
                ['admin', 5],
                ['student', 55],
            ]);
 
        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(UserTable::class, $userTableMock);
 
        $this->dispatch('/admin/users?perPage=all', 'GET');
 
        $this->assertResponseStatusCode(200);
        $this->assertControllerName(UserController::class);
        $this->assertMatchedRouteName('library/user');
    }
 
    public function testIndexActionWithInvalidPerPageDefaultsToTen(): void
    {
        $this->mockLoginAsRole('admin');
 
        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('countFiltered')
            ->willReturn(5);
 
        $resultSet = new ResultSet();
        $resultSet->initialize([]);
        // Invalid perPage defaults to 10
        $userTableMock->expects(self::once())
            ->method('fetchPage')
            ->with(self::anything(), 1, 10)
            ->willReturn($resultSet);
 
        $userTableMock->expects(self::once())
            ->method('countAll')
            ->willReturn(5);
 
        $userTableMock->expects(self::exactly(2))
            ->method('countByRole')
            ->willReturnMap([
                ['admin', 1],
                ['student', 4],
            ]);
 
        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(UserTable::class, $userTableMock);
 
        $this->dispatch('/admin/users?perPage=invalid_value', 'GET');
 
        $this->assertResponseStatusCode(200);
        $this->assertControllerName(UserController::class);
        $this->assertMatchedRouteName('library/user');
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
        ];
    }
}
