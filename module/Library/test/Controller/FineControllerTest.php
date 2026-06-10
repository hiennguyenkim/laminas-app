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

    public function testAdminIndexActionAccessForAdmin(): void
    {
        // Establish admin session
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id'             => 1,
            'username'       => 'lib_admin',
            'email'          => 'admin@hdpe.local',
            'full_name'      => 'Admin',
            'role'           => 'admin',
            'avatar_url'     => '',
            'nickname'       => '',
            'account_status' => 'active',
            'locked_until'   => '',
        ];

        // Mock DB statement for count
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtCount = $this->createMock(StatementInterface::class);
        $resCount = $this->createMock(ResultInterface::class);
        $resCount->method('current')->willReturn(['cnt' => 1]);
        $stmtCount->method('execute')->willReturn($resCount);

        // Mock DB statement for select
        $stmtSelect = $this->createMock(StatementInterface::class);
        $resSelect = $this->createMock(ResultInterface::class);
        $finesData = [
            [
                'fine_id' => 1,
                'user_id' => 10,
                'amount' => '50000.00',
                'reason' => 'Trễ hạn trả sách',
                'status' => 'unpaid',
                'created_at' => '2026-06-01 10:00:00',
                'paid_at' => null,
                'username' => 'student_1',
                'email' => 'student_1@hdpe.local',
                'full_name' => 'Student One',
            ]
        ];
        $idx = 0;
        $resSelect->method('rewind')->willReturnCallback(function() use (&$idx) { $idx = 0; });
        $resSelect->method('valid')->willReturnCallback(function() use (&$idx, $finesData) { return $idx < count($finesData); });
        $resSelect->method('current')->willReturnCallback(function() use (&$idx, $finesData) { return $finesData[$idx]; });
        $resSelect->method('next')->willReturnCallback(function() use (&$idx) { $idx++; });
        $stmtSelect->method('execute')->willReturn($resSelect);

        $dbMock->method('createStatement')->willReturnCallback(function($sql) use ($stmtCount, $stmtSelect) {
            if (strpos($sql, 'SELECT COUNT(*)') !== false) {
                return $stmtCount;
            }
            if (strpos($sql, 'SELECT f.*, u.username') !== false) {
                return $stmtSelect;
            }
            throw new \RuntimeException("Unexpected SQL: " . $sql);
        });

        // Mock summary query
        $stmtSummary = $this->createMock(StatementInterface::class);
        $resSummary = $this->createMock(ResultInterface::class);
        $resSummary->method('current')->willReturn([
            'total_unpaid' => '50000.00',
            'total_paid' => '0.00',
            'total_count' => 1
        ]);
        $stmtSummary->method('execute')->willReturn($resSummary);
        $dbMock->method('query')->with(self::stringContains('SELECT'))->willReturn($stmtSummary);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/fine', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertModuleName('Library');
        $this->assertControllerName(FineController::class);
        $this->assertMatchedRouteName('library/fine');

        $content = $this->getResponse()->getContent();
        $this->assertStringContainsString('Student One', $content);
        $this->assertStringContainsString('50,000đ', $content);
    }

    public function testAdminIndexActionAccessDeniedForStudent(): void
    {
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

        $this->dispatch('/admin/fine', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/student/dashboard');
    }

    public function testAdminPayActionManualCashSuccess(): void
    {
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id'             => 1,
            'username'       => 'lib_admin',
            'email'          => 'admin@hdpe.local',
            'full_name'      => 'Admin',
            'role'           => 'admin',
            'avatar_url'     => '',
            'nickname'       => '',
            'account_status' => 'active',
            'locked_until'   => '',
        ];

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
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
            'amount' => '50000.00',
            'reason' => 'Trễ hạn trả sách',
            'status' => 'unpaid',
            'created_at' => '2026-06-01 10:00:00',
            'paid_at' => null,
        ];
        $resSelectFine->method('current')->willReturn($fineData);
        $stmtSelectFine->method('execute')->willReturn($resSelectFine);

        // Mock statement for checking other unpaid fines
        $stmtCheckUnpaid = $this->createMock(StatementInterface::class);
        $resCheckUnpaid = $this->createMock(ResultInterface::class);
        $resCheckUnpaid->method('current')->willReturn(['cnt' => 0]);
        $stmtCheckUnpaid->method('execute')->willReturn($resCheckUnpaid);

        $dbMock->method('createStatement')->willReturnCallback(function($sql) use ($stmtSelectFine, $stmtCheckUnpaid) {
            if (strpos($sql, 'SELECT * FROM fines WHERE fine_id') !== false) {
                return $stmtSelectFine;
            }
            if (strpos($sql, 'SELECT COUNT(*) AS cnt FROM fines') !== false) {
                return $stmtCheckUnpaid;
            }
            throw new \RuntimeException("Unexpected SQL: " . $sql);
        });

        // Mock query for UPDATE and INSERT notification
        $stmtQuery = $this->createMock(StatementInterface::class);
        $dbMock->method('query')->willReturn($stmtQuery);

        $userTableMock = $this->createMock(UserTable::class);
        $userObj = new User();
        $userObj->exchangeArray([
            'user_id' => 10,
            'username' => 'student_1',
            'email' => 'student_1@hdpe.local',
            'full_name' => 'Student One',
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

        $this->dispatch('/admin/fine/adminPay/1', 'POST');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/fine');
    }

    public function testAdminExportActionReturnsExcelSpreadsheet(): void
    {
        $authSession = $this->getApplicationServiceLocator()->get(AuthSessionContainer::class);
        $authSession->user = [
            'id'             => 1,
            'username'       => 'lib_admin',
            'email'          => 'admin@hdpe.local',
            'full_name'      => 'Admin',
            'role'           => 'admin',
            'avatar_url'     => '',
            'nickname'       => '',
            'account_status' => 'active',
            'locked_until'   => '',
        ];

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtSelect = $this->createMock(StatementInterface::class);
        $resSelect = $this->createMock(ResultInterface::class);
        $finesData = [
            [
                'fine_id' => 1,
                'user_id' => 10,
                'amount' => '50000.00',
                'reason' => 'Trễ hạn trả sách',
                'status' => 'unpaid',
                'created_at' => '2026-06-01 10:00:00',
                'paid_at' => null,
                'username' => 'student_1',
                'email' => 'student_1@hdpe.local',
                'full_name' => 'Student One',
            ]
        ];
        $idx = 0;
        $resSelect->method('rewind')->willReturnCallback(function() use (&$idx) { $idx = 0; });
        $resSelect->method('valid')->willReturnCallback(function() use (&$idx, $finesData) { return $idx < count($finesData); });
        $resSelect->method('current')->willReturnCallback(function() use (&$idx, $finesData) { return $finesData[$idx]; });
        $resSelect->method('next')->willReturnCallback(function() use (&$idx) { $idx++; });
        $stmtSelect->method('execute')->willReturn($resSelect);

        $dbMock->method('createStatement')->with(self::stringContains('SELECT f.*, u.username'))->willReturn($stmtSelect);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/fine/adminExport', 'GET');
        $this->assertResponseStatusCode(200);
        $headers = $this->getResponse()->getHeaders();
        $this->assertTrue($headers->has('Content-Type'));
        $this->assertStringContainsString('spreadsheetml', $headers->get('Content-Type')->getFieldValue());
    }
}
