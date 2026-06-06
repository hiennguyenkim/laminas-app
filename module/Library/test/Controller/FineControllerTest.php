<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\FineController;
use Library\Model\Table\UserTable;
use Library\Model\Entity\User;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class FineControllerTest extends AbstractHttpControllerTestCase
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

    public function testFineListActionGuestRedirectsToLogin(): void
    {
        $this->dispatch('/student/fine', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/auth');
    }

    public function testFineListActionStudentDisplaysFines(): void
    {
        // 1. Establish student session
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id'             => 10,
            'username'       => 'test_student',
            'email'          => 'student@hdpe.local',
            'full_name'      => 'Test Student',
            'role'           => 'student',
            'avatar_url'     => '',
            'nickname'       => '',
            'account_status' => 'active',
            'locked_until'   => '',
        ];

        // 2. Mock DB query returning a list of fines
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $resMock = $this->createMock(ResultInterface::class);

        $finesData = [
            [
                'fine_id' => 1,
                'user_id' => 10,
                'amount' => '20000.00',
                'reason' => 'Phạt trễ hạn sách: Test Book',
                'status' => 'unpaid',
                'created_at' => '2026-06-01 10:00:00',
                'paid_at' => null,
            ]
        ];

        $idx = 0;
        $resMock->method('rewind')->willReturnCallback(function() use (&$idx) { $idx = 0; });
        $resMock->method('valid')->willReturnCallback(function() use (&$idx, $finesData) { return $idx < count($finesData); });
        $resMock->method('current')->willReturnCallback(function() use (&$idx, $finesData) { return $finesData[$idx]; });
        $resMock->method('next')->willReturnCallback(function() use (&$idx) { $idx++; });

        $stmtMock->method('execute')->willReturn($resMock);
        $dbMock->method('createStatement')->with(self::stringContains('SELECT * FROM fines'))->willReturn($stmtMock);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        // 3. Dispatch and verify
        $this->dispatch('/student/fine', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertModuleName('Library');
        $this->assertControllerName(FineController::class);
        $this->assertControllerClass('FineController');
        $this->assertMatchedRouteName('student/fine');
        
        $content = $this->getResponse()->getContent();
        $this->assertStringContainsString('Phạt trễ hạn sách: Test Book', $content);
        $this->assertStringContainsString('20,000đ', $content);
    }

    public function testCheckoutActionGetUnpaidFine(): void
    {
        // 1. Establish student session
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id'             => 10,
            'username'       => 'test_student',
            'email'          => 'student@hdpe.local',
            'full_name'      => 'Test Student',
            'role'           => 'student',
            'avatar_url'     => '',
            'nickname'       => '',
            'account_status' => 'locked',
            'locked_until'   => '9999-12-31',
        ];

        // 2. Mock DB query returning a single unpaid fine
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $resMock = $this->createMock(ResultInterface::class);

        $fineData = [
            'fine_id' => 1,
            'user_id' => 10,
            'amount' => '20000.00',
            'reason' => 'Phạt trễ hạn sách: Test Book',
            'status' => 'unpaid',
            'created_at' => '2026-06-01 10:00:00',
            'paid_at' => null,
        ];

        $resMock->method('current')->willReturn($fineData);
        $stmtMock->method('execute')->willReturn($resMock);
        $dbMock->method('createStatement')->with(self::stringContains('SELECT * FROM fines WHERE fine_id'))->willReturn($stmtMock);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        // 3. Dispatch and verify
        $this->dispatch('/student/fine/checkout/1', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertModuleName('Library');
        $this->assertControllerName(FineController::class);
        
        $content = $this->getResponse()->getContent();
        $this->assertStringContainsString('Phạt trễ hạn sách: Test Book', $content);
        $this->assertStringContainsString('20,000đ', $content);
    }

    public function testCheckoutActionPostConfirmsAndUnlocksUser(): void
    {
        // 1. Establish student session
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id'             => 10,
            'username'       => 'test_student',
            'email'          => 'student@hdpe.local',
            'full_name'      => 'Test Student',
            'role'           => 'student',
            'avatar_url'     => '',
            'nickname'       => '',
            'account_status' => 'locked',
            'locked_until'   => '9999-12-31',
        ];

        // 2. Setup DB mocks for transactions and queries
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        // Mock connection transaction methods
        $connMock = $this->createMock(\Laminas\Db\Adapter\Driver\ConnectionInterface::class);
        $driverMock = $this->createMock(\Laminas\Db\Adapter\Driver\DriverInterface::class);
        $driverMock->method('getConnection')->willReturn($connMock);
        $dbMock->method('getDriver')->willReturn($driverMock);

        // Mock statement for selecting specific fine
        $stmtSelectFine = $this->createMock(StatementInterface::class);
        $resSelectFine = $this->createMock(ResultInterface::class);
        $fineData = [
            'fine_id' => 1,
            'user_id' => 10,
            'amount' => '20000.00',
            'reason' => 'Phạt trễ hạn sách: Test Book',
            'status' => 'unpaid',
            'created_at' => '2026-06-01 10:00:00',
            'paid_at' => null,
        ];
        $resSelectFine->method('current')->willReturn($fineData);
        $stmtSelectFine->method('execute')->willReturn($resSelectFine);

        // Mock statement for checking other unpaid fines (returns 0 unpaid fines left)
        $stmtCheckUnpaid = $this->createMock(StatementInterface::class);
        $resCheckUnpaid = $this->createMock(ResultInterface::class);
        $resCheckUnpaid->method('current')->willReturn(['cnt' => 0]);
        $stmtCheckUnpaid->method('execute')->willReturn($resCheckUnpaid);

        // Map statements based on SQL queries
        $dbMock->method('createStatement')->willReturnCallback(function($sql) use ($stmtSelectFine, $stmtCheckUnpaid) {
            if (strpos($sql, 'SELECT * FROM fines WHERE fine_id') !== false) {
                return $stmtSelectFine;
            }
            if (strpos($sql, 'SELECT COUNT(*) AS cnt FROM fines') !== false) {
                return $stmtCheckUnpaid;
            }
            throw new \RuntimeException("Unexpected createStatement call: " . $sql);
        });

        // Mock execute query for UPDATE and INSERT notification
        $stmtExecMock = $this->createMock(StatementInterface::class);
        $dbMock->method('query')->willReturn($stmtExecMock);

        // 3. Mock UserTable methods
        $userTableMock = $this->createMock(UserTable::class);
        $userObj = new User();
        $userObj->exchangeArray([
            'user_id' => 10,
            'username' => 'test_student',
            'email' => 'student@hdpe.local',
            'full_name' => 'Test Student',
            'role' => 'student',
            'account_status' => 'locked',
            'borrow_limit' => 0,
        ]);
        $userTableMock->expects(self::once())->method('unlockUser')->with(10);
        $userTableMock->expects(self::once())->method('getUser')->with(10)->willReturn($userObj);
        $userTableMock->expects(self::once())->method('saveUser')->with($userObj);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        // 4. Dispatch Post and check redirect and session update
        $this->dispatch('/student/fine/checkout/1', 'POST');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/student/fine');

        // Check if session status updated immediately to active
        $this->assertEquals('active', $authSession->user['account_status']);
        $this->assertEquals('', $authSession->user['locked_until']);
    }
}
