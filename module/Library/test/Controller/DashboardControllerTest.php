<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\DashboardController;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class DashboardControllerTest extends AbstractHttpControllerTestCase
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

    public function testAdminDashboardGetsGlobalCategoryStats(): void
    {
        $this->mockLoginAsRole('admin');

        $bookTableMock = $this->createMock(BookTable::class);
        $bookTableMock->method('getSummary')->willReturn(['total_titles' => 10]);
        // Expect getCategoryStats to be called with NULL
        $bookTableMock->expects(self::once())
            ->method('getCategoryStats')
            ->with(null)
            ->willReturn(['Văn học' => 5, 'Khoa học' => 3]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->method('getSummary')->willReturn([
            'borrowed' => 2,
            'overdue' => 0,
            'returned' => 1,
            'due_soon' => 0,
        ]);
        $borrowTableMock->method('fetchAllWithDetails')->willReturn([]);
        $borrowTableMock->method('getMonthlyStats')->willReturn([
            'borrow' => array_fill(0, 12, 0),
            'return' => array_fill(0, 12, 0),
        ]);

        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->method('countByRole')->willReturn(5);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        $this->dispatch('/admin/dashboard', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(DashboardController::class);
        $this->assertMatchedRouteName('library/dashboard');
    }

    public function testStudentDashboardGetsStudentCategoryStats(): void
    {
        $this->mockLoginAsRole('student');

        $bookTableMock = $this->createMock(BookTable::class);
        $bookTableMock->method('getSummary')->willReturn(['total_titles' => 10]);
        // Expect getCategoryStats to be called with the student user ID (which is 2)
        $bookTableMock->expects(self::once())
            ->method('getCategoryStats')
            ->with(2)
            ->willReturn(['Văn học' => 1]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->method('getSummary')->with(2)->willReturn([
            'borrowed' => 1,
            'overdue' => 0,
            'returned' => 0,
            'due_soon' => 0,
        ]);
        $borrowTableMock->method('fetchAllWithDetails')->with([], 2, 6)->willReturn([]);
        $borrowTableMock->method('getMonthlyStats')->with((int) date('Y'), 2)->willReturn([
            'borrow' => array_fill(0, 12, 0),
            'return' => array_fill(0, 12, 0),
        ]);

        $userTableMock = $this->createMock(UserTable::class);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        $this->dispatch('/student/dashboard', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(DashboardController::class);
        $this->assertMatchedRouteName('student/dashboard');
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
