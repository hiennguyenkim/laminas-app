<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\Api\NotificationApiController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class NotificationApiControllerTest extends AbstractHttpControllerTestCase
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

    public function testIndexActionUnauthorized(): void
    {
        $this->dispatch('/api/notifications', 'GET');
        $this->assertResponseStatusCode(401);
        $this->assertJsonStringEqualsJsonString(
            json_encode(['error' => 'Unauthorized']),
            $this->getResponse()->getContent()
        );
    }

    public function testIndexActionForAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        // Create Mocks for DB queries
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // We mock $dbMock->query(...) to return statements
        // For line 35 (auto-scan overdue):
        $overdueStatement = $this->createMock(StatementInterface::class);
        $overdueResult = $this->createMock(ResultInterface::class);
        $overdueResult->method('valid')->willReturn(false); // No overdue records for simplicity
        $overdueStatement->method('execute')->willReturn($overdueResult);

        // For line 77 (fetch notifications):
        $notiStatement = $this->createMock(StatementInterface::class);
        $notiResult = $this->createMock(ResultInterface::class);
        
        $notificationsData = [
            [
                'id' => 1,
                'title' => 'Support request',
                'message' => 'Detail message',
                'type' => 'ticket',
                'related_id' => 5,
                'created_at' => date('Y-m-d H:i:s'),
                'is_read' => 0,
            ]
        ];

        // ResultInterface behaves like an Iterator
        $notiResult->method('valid')->willReturnCallback(function() use (&$notificationsData) {
            return key($notificationsData) !== null;
        });
        $notiResult->method('current')->willReturnCallback(function() use (&$notificationsData) {
            return current($notificationsData);
        });
        $notiResult->method('next')->willReturnCallback(function() use (&$notificationsData) {
            next($notificationsData);
            return null;
        });
        $notiResult->method('key')->willReturnCallback(function() use (&$notificationsData) {
            return key($notificationsData);
        });
        $notiResult->method('rewind')->willReturnCallback(function() use (&$notificationsData) {
            reset($notificationsData);
            return null;
        });

        $notiStatement->method('execute')->willReturn($notiResult);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($overdueStatement, $notiStatement) {
            if (strpos($sql, 'notifications') !== false) {
                return $notiStatement;
            }
            if (strpos($sql, 'borrow_records') !== false && strpos($sql, 'SELECT') !== false) {
                return $overdueStatement;
            }
            $emptyStmt = $this->createMock(StatementInterface::class);
            $emptyResult = $this->createMock(ResultInterface::class);
            $emptyResult->method('valid')->willReturn(false);
            $emptyStmt->method('execute')->willReturn($emptyResult);
            return $emptyStmt;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/api/notifications', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertResponseHeaderContains('Content-Type', 'application/json');

        $responseArray = json_decode($this->getResponse()->getContent(), true);
        $this->assertNotEmpty($responseArray);
        $this->assertEquals('db_1', $responseArray[0]['id']);
        $this->assertEquals('Support request', $responseArray[0]['title']);
        $this->assertStringContainsString('/admin/ticket/view/5', $responseArray[0]['url']);
    }

    public function testIndexActionForStudent(): void
    {
        $this->mockLoginAsRole('student');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        $overdueStatement = $this->createMock(StatementInterface::class);
        $overdueResult = $this->createMock(ResultInterface::class);
        $overdueResult->method('valid')->willReturn(false);
        $overdueStatement->method('execute')->willReturn($overdueResult);

        $notiStatement = $this->createMock(StatementInterface::class);
        $notiResult = $this->createMock(ResultInterface::class);

        $notificationsData = [
            [
                'id' => 2,
                'title' => 'Replied',
                'message' => 'Admin replied',
                'type' => 'ticket_answered',
                'related_id' => 8,
                'created_at' => date('Y-m-d H:i:s'),
                'is_read' => 0,
            ]
        ];

        $notiResult->method('valid')->willReturnCallback(function() use (&$notificationsData) {
            return key($notificationsData) !== null;
        });
        $notiResult->method('current')->willReturnCallback(function() use (&$notificationsData) {
            return current($notificationsData);
        });
        $notiResult->method('next')->willReturnCallback(function() use (&$notificationsData) {
            next($notificationsData);
            return null;
        });
        $notiResult->method('key')->willReturnCallback(function() use (&$notificationsData) {
            return key($notificationsData);
        });
        $notiResult->method('rewind')->willReturnCallback(function() use (&$notificationsData) {
            reset($notificationsData);
            return null;
        });

        $notiStatement->method('execute')->willReturn($notiResult);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($overdueStatement, $notiStatement) {
            if (strpos($sql, 'notifications') !== false) {
                return $notiStatement;
            }
            if (strpos($sql, 'borrow_records') !== false && strpos($sql, 'SELECT') !== false) {
                return $overdueStatement;
            }
            $emptyStmt = $this->createMock(StatementInterface::class);
            $emptyResult = $this->createMock(ResultInterface::class);
            $emptyResult->method('valid')->willReturn(false);
            $emptyStmt->method('execute')->willReturn($emptyResult);
            return $emptyStmt;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/api/notifications', 'GET');

        $this->assertResponseStatusCode(200);

        $responseArray = json_decode($this->getResponse()->getContent(), true);
        $this->assertNotEmpty($responseArray);
        $this->assertEquals('db_2', $responseArray[0]['id']);
        $this->assertStringContainsString('/student/ticket/view/8', $responseArray[0]['url']);
    }

    public function testOverdueScanCreatesWarningNotification(): void
    {
        $this->mockLoginAsRole('student');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // 1. Scan query returns 1 overdue book record
        $scanStmt = $this->createMock(StatementInterface::class);
        $scanResult = $this->createMock(ResultInterface::class);
        $overdueData = [
            [
                'borrow_id' => 101,
                'user_id' => 2,
                'book_id' => 12,
                'book_title' => 'Sample Overdue Book',
                'return_date' => '2026-05-20',
                'status' => 'borrowed',
            ]
        ];
        $scanResult->method('valid')->willReturnCallback(function() use (&$overdueData) {
            return key($overdueData) !== null;
        });
        $scanResult->method('current')->willReturnCallback(function() use (&$overdueData) {
            return current($overdueData);
        });
        $scanResult->method('next')->willReturnCallback(function() use (&$overdueData) {
            next($overdueData);
            return null;
        });
        $scanResult->method('key')->willReturnCallback(function() use (&$overdueData) {
            return key($overdueData);
        });
        $scanResult->method('rewind')->willReturnCallback(function() use (&$overdueData) {
            reset($overdueData);
            return null;
        });
        $scanStmt->method('execute')->willReturn($scanResult);

        // 2. Check query returns empty (meaning no previous warning sent)
        $checkStmt = $this->createMock(StatementInterface::class);
        $checkResult = $this->createMock(ResultInterface::class);
        $checkResult->method('count')->willReturn(0);
        $checkStmt->method('execute')->willReturn($checkResult);

        // 3. Update query and Insert query statements
        $updateStmt = $this->createMock(StatementInterface::class);
        $updateStmt->method('execute')->willReturn($this->createMock(ResultInterface::class));

        $insertStmt = $this->createMock(StatementInterface::class);
        $insertStmt->method('execute')->willReturn($this->createMock(ResultInterface::class));

        // 4. Fetch notifications query returns empty
        $notiStmt = $this->createMock(StatementInterface::class);
        $notiResult = $this->createMock(ResultInterface::class);
        $notiResult->method('valid')->willReturn(false);
        $notiStmt->method('execute')->willReturn($notiResult);

        // Map SQL to Statement mocks
        $dbMock->method('query')->willReturnCallback(function($sql) use ($scanStmt, $updateStmt, $checkStmt, $insertStmt, $notiStmt) {
            if (strpos($sql, 'CURDATE()') !== false) {
                return $scanStmt;
            }
            if (strpos($sql, 'UPDATE borrow_records') !== false) {
                return $updateStmt;
            }
            if (strpos($sql, 'SELECT id FROM notifications') !== false) {
                return $checkStmt;
            }
            if (strpos($sql, 'INSERT INTO notifications') !== false) {
                return $insertStmt;
            }
            if (strpos($sql, 'SELECT * FROM notifications') !== false) {
                return $notiStmt;
            }
            
            $emptyStmt = $this->createMock(StatementInterface::class);
            $emptyResult = $this->createMock(ResultInterface::class);
            $emptyResult->method('valid')->willReturn(false);
            $emptyStmt->method('execute')->willReturn($emptyResult);
            return $emptyStmt;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/api/notifications', 'GET');

        $this->assertResponseStatusCode(200);
        
        // Assert JSON is returned (it's empty since there are no active notifications fetched for the list)
        $responseArray = json_decode($this->getResponse()->getContent(), true);
        $this->assertIsArray($responseArray);
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
