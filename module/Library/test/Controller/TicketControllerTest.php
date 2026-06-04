<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\TicketController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class TicketControllerTest extends AbstractHttpControllerTestCase
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

    public function testIndexActionRequiresLogin(): void
    {
        $this->dispatch('/admin/ticket', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/auth');
    }

    public function testIndexActionAsAdminWithSorting(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Sequence of query results
        $mockResults = [
            // 1. countSql
            [['cnt' => 1]],
            // 2. select tickets query (with ORDER BY verification in callback)
            [[
                'id' => 10,
                'user_id' => 2,
                'title' => 'Lỗi mượn sách',
                'description' => 'Không thể mượn sách Lập trình PHP',
                'status' => 'open',
                'created_at' => '2026-05-23 10:00:00',
                'updated_at' => '2026-05-23 10:00:00',
                'author_name' => 'Nguyễn Văn An'
            ]],
            // 3. unansweredCountSql
            [['cnt' => 1]],
            // 4. answeredCountSql
            [['cnt' => 0]],
            // 5. allCountSql
            [['cnt' => 1]]
        ];

        $queryIndex = 0;
        $dbMock->method('query')->willReturnCallback(function($sql) use (&$queryIndex, $mockResults) {
            // Verify that for query index 1 (fetching tickets list), the order by is correct
            if ($queryIndex === 1) {
                self::assertStringContainsString('ORDER BY t.title ASC', $sql);
            }
            
            $currentResult = $mockResults[$queryIndex] ?? [];
            $queryIndex++;

            $res = $this->createMock(ResultInterface::class);
            $idx = 0;
            $res->method('rewind')->willReturnCallback(function() use (&$idx) { $idx = 0; });
            $res->method('valid')->willReturnCallback(function() use (&$idx, $currentResult) { return $idx < count($currentResult); });
            $res->method('current')->willReturnCallback(function() use (&$idx, $currentResult) { return $currentResult[$idx]; });
            $res->method('key')->willReturnCallback(function() use (&$idx) { return $idx; });
            $res->method('next')->willReturnCallback(function() use (&$idx) { $idx++; });
            
            $stmt = $this->createMock(StatementInterface::class);
            $stmt->method('execute')->willReturn($res);
            return $stmt;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        // Dispatching with sort filter
        $this->dispatch('/admin/ticket?sort=title&direction=asc', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TicketController::class);
        $this->assertMatchedRouteName('library/ticket');
    }

    public function testIndexActionAsStudent(): void
    {
        $this->mockLoginAsRole('student');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Sequence of query results
        $mockResults = [
            // 1. countSql
            [['cnt' => 1]],
            // 2. select tickets query (verify user_id is parameterized)
            [[
                'id' => 11,
                'user_id' => 2,
                'title' => 'Hỏi hạn trả sách',
                'description' => 'Sách sắp hết hạn có được gia hạn không?',
                'status' => 'in_progress',
                'created_at' => '2026-05-23 10:15:00',
                'updated_at' => '2026-05-23 10:30:00',
                'author_name' => 'Nguyễn Văn An'
            ]],
            // 3. unansweredCountSql
            [['cnt' => 0]],
            // 4. answeredCountSql
            [['cnt' => 1]],
            // 5. allCountSql
            [['cnt' => 1]]
        ];

        $queryIndex = 0;
        $dbMock->method('query')->willReturnCallback(function($sql) use (&$queryIndex, $mockResults) {
            if ($queryIndex === 0 || $queryIndex === 1) {
                // Ensure query filters by student user_id
                self::assertStringContainsString('t.user_id = ?', $sql);
            }

            $currentResult = $mockResults[$queryIndex] ?? [];
            $queryIndex++;

            $res = $this->createMock(ResultInterface::class);
            $idx = 0;
            $res->method('rewind')->willReturnCallback(function() use (&$idx) { $idx = 0; });
            $res->method('valid')->willReturnCallback(function() use (&$idx, $currentResult) { return $idx < count($currentResult); });
            $res->method('current')->willReturnCallback(function() use (&$idx, $currentResult) { return $currentResult[$idx]; });
            $res->method('key')->willReturnCallback(function() use (&$idx) { return $idx; });
            $res->method('next')->willReturnCallback(function() use (&$idx) { $idx++; });
            
            $stmt = $this->createMock(StatementInterface::class);
            $stmt->method('execute')->willReturn($res);
            return $stmt;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/student/ticket', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TicketController::class);
        $this->assertMatchedRouteName('student/ticket');
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
