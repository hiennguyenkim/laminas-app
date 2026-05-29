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

        // Clear request content to prevent test leaks
        $this->getRequest()->setContent('');
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
        $borrowTableMock->method('getCategoryMonthlyStats')->willReturn([
            'Văn học' => [1, 0, 0, 2, 0, 0, 0, 0, 0, 0, 0, 0],
            'Khoa học' => [0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
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
        
        $stmtInsert = $this->createMock(StatementInterface::class);
        $stmtInsert->method('execute')->willReturn($this->createMock(ResultInterface::class));

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtInsert) {
            if (strpos($sql, 'INSERT INTO public_chats') !== false) {
                return $stmtInsert;
            }
            $stmtEmpty = $this->createMock(StatementInterface::class);
            $resEmpty = $this->createMock(ResultInterface::class);
            $resEmpty->method('rewind')->willReturnCallback(function() {});
            $resEmpty->method('valid')->willReturn(false);
            $stmtEmpty->method('execute')->willReturn($resEmpty);
            return $stmtEmpty;
        });

        $geminiServiceMock = $this->createMock(\Library\Service\GeminiService::class);
        $geminiServiceMock->method('checkContent')->willReturn(true);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);
        $serviceLocator->setService(\Library\Service\GeminiService::class, $geminiServiceMock);

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

    public function testStatsActionAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('getMonthlyStats')
            ->with(2025, null)
            ->willReturn([
                'borrow' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                'return' => [12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1],
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/admin/dashboard/stats?year=2025', 'GET');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertArrayHasKey('borrow', $response);
        $this->assertArrayHasKey('return', $response);
        $this->assertEquals([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], $response['borrow']);
    }

    public function testStatsActionStudent(): void
    {
        $this->mockLoginAsRole('student'); // ID is 2

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('getMonthlyStats')
            ->with(2026, 2)
            ->willReturn([
                'borrow' => array_fill(0, 12, 0),
                'return' => array_fill(0, 12, 0),
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/student/dashboard/stats?year=2026', 'GET');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertArrayHasKey('borrow', $response);
        $this->assertArrayHasKey('return', $response);
    }

    public function testCategoryStatsActionAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('getCategoryMonthlyStats')
            ->with(2025)
            ->willReturn([
                'Thiếu nhi' => [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/admin/dashboard/category-stats?year=2025', 'GET');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertArrayHasKey('Thiếu nhi', $response);
        $this->assertEquals([1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0], $response['Thiếu nhi']);
    }

    public function testCategoryStatsActionStudentForbidden(): void
    {
        $this->mockLoginAsRole('student');

        $this->dispatch('/student/dashboard/category-stats?year=2025', 'GET');
        $this->assertResponseStatusCode(403);
    }

    public function testExportStatsActionAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('getMonthlyStats')
            ->with(2026, null)
            ->willReturn([
                'borrow' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12],
                'return' => [12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1],
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/admin/dashboard/export-stats?year=2026&quarter=all', 'GET');
        $this->assertResponseStatusCode(200);
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $this->assertTrue($headers->has('Content-Disposition'));
        $this->assertStringContainsString('thong-ke-muon-tra-2026.xlsx', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('spreadsheetml', $headers->get('Content-Type')->getFieldValue());

        // XLSX is binary — verify it starts with the ZIP/PK signature
        $content = $response->getContent();
        $this->assertStringStartsWith('PK', $content);
    }

    public function testExportStatsActionStudent(): void
    {
        $this->mockLoginAsRole('student'); // ID is 2

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('getMonthlyStats')
            ->with(2026, 2)
            ->willReturn([
                'borrow' => array_fill(0, 12, 0),
                'return' => array_fill(0, 12, 0),
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/student/dashboard/export-stats?year=2026&quarter=1', 'GET');
        $this->assertResponseStatusCode(200);
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $this->assertTrue($headers->has('Content-Disposition'));
        $this->assertStringContainsString('thong-ke-muon-tra-2026-quy-1.xlsx', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('spreadsheetml', $headers->get('Content-Type')->getFieldValue());

        // XLSX is binary — verify it starts with the ZIP/PK signature
        $content = $response->getContent();
        $this->assertStringStartsWith('PK', $content);
    }

    public function testExportCategoryMonthlyStatsActionAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('getCategoryMonthlyStats')
            ->with(2026)
            ->willReturn([
                'Thiếu nhi' => [1, 2, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/admin/dashboard/export-category-monthly-stats?year=2026&month=year', 'GET');
        $this->assertResponseStatusCode(200);
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $this->assertTrue($headers->has('Content-Disposition'));
        $this->assertStringContainsString('the-loai-sach-duoc-muon-2026.xlsx', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('spreadsheetml', $headers->get('Content-Type')->getFieldValue());

        // XLSX is binary — verify it starts with the ZIP/PK signature
        $content = $response->getContent();
        $this->assertStringStartsWith('PK', $content);
    }

    public function testExportCategoryMonthlyStatsActionStudentForbidden(): void
    {
        $this->mockLoginAsRole('student');

        $this->dispatch('/student/dashboard/export-category-monthly-stats', 'GET');
        $this->assertResponseStatusCode(403);
    }

    public function testExportCategoryStatsActionAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $bookTableMock = $this->createMock(BookTable::class);
        $bookTableMock->expects(self::once())
            ->method('getCategoryStats')
            ->with(null)
            ->willReturn([
                'Văn học' => 10,
                'Khoa học' => 5,
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);

        $this->dispatch('/admin/dashboard/export-category-stats', 'GET');
        $this->assertResponseStatusCode(200);
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $this->assertTrue($headers->has('Content-Disposition'));
        $this->assertStringContainsString('phan-bo-the-loai-sach.xlsx', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('spreadsheetml', $headers->get('Content-Type')->getFieldValue());

        // XLSX is binary — verify it starts with the ZIP/PK signature
        $content = $response->getContent();
        $this->assertStringStartsWith('PK', $content);
    }

    public function testExportCategoryStatsActionStudent(): void
    {
        $this->mockLoginAsRole('student'); // ID is 2

        $bookTableMock = $this->createMock(BookTable::class);
        $bookTableMock->expects(self::once())
            ->method('getCategoryStats')
            ->with(2)
            ->willReturn([
                'Văn học' => 2,
            ]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);

        $this->dispatch('/student/dashboard/export-category-stats', 'GET');
        $this->assertResponseStatusCode(200);
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $this->assertTrue($headers->has('Content-Disposition'));
        $this->assertStringContainsString('the-loai-sach-da-muon.xlsx', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('spreadsheetml', $headers->get('Content-Type')->getFieldValue());

        // XLSX is binary — verify it starts with the ZIP/PK signature
        $content = $response->getContent();
        $this->assertStringStartsWith('PK', $content);
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
