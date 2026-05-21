<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\DashboardController;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
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

    public function testChatActionGetReturnsJson(): void
    {
        $this->mockLoginAsRole('student');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Mock pinned statement & result
        $pinnedStmtMock = $this->createMock(StatementInterface::class);
        $pinnedResultMock = $this->createMock(ResultInterface::class);
        $pinnedResultMock->method('current')->willReturn([
            'id'         => 10,
            'user_id'    => 1,
            'nickname'   => 'Thủ thư',
            'message'    => 'Thông báo ghim',
            'role'       => 'admin',
            'avatar_url' => '',
            'is_pinned'  => 1,
            'reactions'  => '',
            'created_at' => '2026-05-21 12:00:00',
        ]);
        $pinnedStmtMock->method('execute')->willReturn($pinnedResultMock);

        // Mock messages statement & result (Iterator)
        $chatStmtMock = $this->createMock(StatementInterface::class);
        $chatResultMock = $this->createMock(ResultInterface::class);

        $messages = [
            [
                'id'         => 1,
                'user_id'    => 2,
                'nickname'   => 'Sinh viên A',
                'message'    => 'Chào mọi người',
                'role'       => 'student',
                'avatar_url' => '',
                'is_pinned'  => 0,
                'reactions'  => '{"👍":[2]}',
                'created_at' => '2026-05-21 12:05:00',
            ]
        ];

        $index = 0;
        $chatResultMock->method('rewind')->willReturnCallback(function() use (&$index) { $index = 0; });
        $chatResultMock->method('valid')->willReturnCallback(function() use (&$index, $messages) { return $index < count($messages); });
        $chatResultMock->method('current')->willReturnCallback(function() use (&$index, $messages) { return $messages[$index]; });
        $chatResultMock->method('key')->willReturnCallback(function() use (&$index) { return $index; });
        $chatResultMock->method('next')->willReturnCallback(function() use (&$index) { $index++; });
        $chatStmtMock->method('execute')->willReturn($chatResultMock);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($pinnedStmtMock, $chatStmtMock) {
            if (strpos($sql, 'is_pinned = 1') !== false) {
                return $pinnedStmtMock;
            }
            return $chatStmtMock;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/student/dashboard/chat', 'GET');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertArrayHasKey('messages', $response);
        $this->assertArrayHasKey('pinned', $response);

        $this->assertEquals('Thông báo ghim', $response['pinned']['message']);
        $this->assertCount(1, $response['messages']);
        $this->assertEquals('Chào mọi người', $response['messages'][0]['message']);
        $this->assertEquals('12:05', $response['messages'][0]['created_at']);
    }

    public function testChatActionPostMessage(): void
    {
        $this->mockLoginAsRole('student');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains('INSERT INTO public_chats'), [2, 'Test message'])
            ->willReturn($this->createMock(ResultInterface::class));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/student/dashboard/chat', 'POST', ['message' => 'Test message']);
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertTrue($response['success']);
    }

    public function testChatActionPostMessageUnauthorized(): void
    {
        // No login
        $this->dispatch('/student/dashboard/chat', 'POST', ['message' => 'Hello']);
        $this->assertResponseStatusCode(401);
    }

    public function testChatActionPostDeleteAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains('DELETE FROM public_chats WHERE id = ?'), [5])
            ->willReturn($this->createMock(ResultInterface::class));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/dashboard/chat', 'POST', ['action' => 'delete', 'id' => 5]);
        $this->assertResponseStatusCode(200);
    }

    public function testChatActionPostDeleteStudentOwnMessage(): void
    {
        $this->mockLoginAsRole('student'); // ID is 2

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains('DELETE FROM public_chats WHERE id = ? AND user_id = ?'), [5, 2])
            ->willReturn($this->createMock(ResultInterface::class));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/student/dashboard/chat', 'POST', ['action' => 'delete', 'id' => 5]);
        $this->assertResponseStatusCode(200);
    }

    public function testChatActionPostPinAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $dbMock->expects(self::exactly(2))
            ->method('query')
            ->willReturnCallback(function($sql, $params = []) {
                if (strpos($sql, 'is_pinned = 0') !== false) {
                    return $this->createMock(ResultInterface::class);
                }
                if (strpos($sql, 'is_pinned = 1 WHERE id = ?') !== false) {
                    $this->assertEquals([15], $params);
                    return $this->createMock(ResultInterface::class);
                }
                return $this->createMock(ResultInterface::class);
            });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/dashboard/chat', 'POST', ['action' => 'pin', 'id' => 15]);
        $this->assertResponseStatusCode(200);
    }

    public function testChatActionPostPinForbiddenForStudent(): void
    {
        $this->mockLoginAsRole('student');

        $this->dispatch('/student/dashboard/chat', 'POST', ['action' => 'pin', 'id' => 15]);
        $this->assertResponseStatusCode(403);
    }

    public function testChatActionPostUnpinAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $dbMock->expects(self::once())
            ->method('query')
            ->with(self::stringContains('UPDATE public_chats SET is_pinned = 0'))
            ->willReturn($this->createMock(ResultInterface::class));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/dashboard/chat', 'POST', ['action' => 'unpin']);
        $this->assertResponseStatusCode(200);
    }

    public function testChatActionPostReactNew(): void
    {
        $this->mockLoginAsRole('student'); // ID is 2

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Mock SELECT reactions query
        $selectStmtMock = $this->createMock(StatementInterface::class);
        $selectResultMock = $this->createMock(ResultInterface::class);
        $selectResultMock->method('current')->willReturn(['reactions' => '']);
        $selectStmtMock->method('execute')->with([12])->willReturn($selectResultMock);

        $dbMock->method('query')->willReturnCallback(function($sql, $params = []) use ($selectStmtMock) {
            if (strpos($sql, 'SELECT reactions') !== false) {
                return $selectStmtMock;
            }
            if (strpos($sql, 'UPDATE public_chats SET reactions') !== false) {
                // Reactions should contain user 2 for emoji 👍
                $expectedReactions = json_encode(['👍' => [2]], JSON_UNESCAPED_UNICODE);
                $this->assertEquals([$expectedReactions, 12], $params);
                return $this->createMock(ResultInterface::class);
            }
            return $this->createMock(ResultInterface::class);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/student/dashboard/chat', 'POST', [
            'action' => 'react',
            'id'     => 12,
            'emoji'  => '👍'
        ]);
        $this->assertResponseStatusCode(200);
    }

    public function testChatActionPostReactToggleOff(): void
    {
        $this->mockLoginAsRole('student'); // ID is 2

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Mock SELECT reactions query showing user 2 already reacted
        $selectStmtMock = $this->createMock(StatementInterface::class);
        $selectResultMock = $this->createMock(ResultInterface::class);
        $selectResultMock->method('current')->willReturn([
            'reactions' => json_encode(['👍' => [2]])
        ]);
        $selectStmtMock->method('execute')->with([12])->willReturn($selectResultMock);

        $dbMock->method('query')->willReturnCallback(function($sql, $params = []) use ($selectStmtMock) {
            if (strpos($sql, 'SELECT reactions') !== false) {
                return $selectStmtMock;
            }
            if (strpos($sql, 'UPDATE public_chats SET reactions') !== false) {
                // Reactions should be empty json [] because user 2 toggled off
                $expectedReactions = json_encode([], JSON_UNESCAPED_UNICODE);
                $this->assertEquals([$expectedReactions, 12], $params);
                return $this->createMock(ResultInterface::class);
            }
            return $this->createMock(ResultInterface::class);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/student/dashboard/chat', 'POST', [
            'action' => 'react',
            'id'     => 12,
            'emoji'  => '👍'
        ]);
        $this->assertResponseStatusCode(200);
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
