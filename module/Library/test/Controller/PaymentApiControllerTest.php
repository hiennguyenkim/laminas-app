<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\Api\PaymentApiController;
use Library\Model\Entity\PaymentSession;
use Library\Model\Table\PaymentSessionTable;
use Library\Model\Table\UserTable;
use Library\Model\Table\SystemSettingsTable;
use Library\Service\GmailService;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class PaymentApiControllerTest extends AbstractHttpControllerTestCase
{
    protected function setUp(): void
    {
        $config = include __DIR__ . '/../../../../config/application.config.php';
        $config['module_listener_options']['config_cache_enabled'] = false;
        $config['module_listener_options']['module_map_cache_enabled'] = false;

        $this->setApplicationConfig($config);

        parent::setUp();
    }

    public function testCreateSessionActionGetMethodNotAllowed(): void
    {
        $this->dispatch('/api/payment/create-session', 'GET');
        $this->assertResponseStatusCode(405);
        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('Method not allowed', $response['error']);
    }

    public function testCreateSessionActionSuccess(): void
    {
        // 1. Mock DB adapter to return fine info
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $resMock = $this->createMock(ResultInterface::class);
        
        $fineRow = [
            'fine_id' => 12,
            'user_id' => 5,
            'amount' => 15000.0,
            'status' => 'unpaid',
            'reason' => 'Trễ hạn trả sách'
        ];
        
        $resMock->method('current')->willReturn($fineRow);
        $stmtMock->method('execute')->with([12])->willReturn($resMock);
        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtMock) {
            if (strpos($sql, 'SELECT * FROM fines') !== false) {
                return $stmtMock;
            }
            throw new \Exception("Unexpected sql: " . $sql);
        });

        // 2. Mock PaymentSessionTable to allow saveSession
        $sessionTableMock = $this->createMock(PaymentSessionTable::class);
        $sessionTableMock->expects(self::once())
            ->method('saveSession')
            ->with(self::isInstanceOf(PaymentSession::class));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);
        $serviceLocator->setService(PaymentSessionTable::class, $sessionTableMock);

        // Dispatch request
        $this->getRequest()->setContent(json_encode([
            'fine_id' => 12,
            'channel' => 'vnpay'
        ]));
        
        $this->dispatch('/api/payment/create-session', 'POST');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('success', $response['status']);
        $this->assertArrayHasKey('orderCode', $response);
        $this->assertStringStartsWith('HDPE12X', $response['orderCode']);
        $this->assertEquals(15000.0, $response['amount']);
    }

    public function testCheckStatusActionSuccess(): void
    {
        // 1. Create simulated payment session
        $session = new PaymentSession();
        $session->id = 101;
        $session->orderCode = 'HDPE12XTEST';
        $session->amount = 15000.0;
        $session->status = 'paid';
        $session->expiredAt = date('Y-m-d H:i:s', time() + 300);

        // 2. Mock PaymentSessionTable
        $sessionTableMock = $this->createMock(PaymentSessionTable::class);
        $sessionTableMock->expects(self::once())
            ->method('getSession')
            ->with('HDPE12XTEST')
            ->willReturn($session);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(PaymentSessionTable::class, $sessionTableMock);

        $this->dispatch('/api/payment/check-status?orderCode=HDPE12XTEST', 'GET');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('paid', $response['paymentStatus']);
    }

    public function testGmailWebhookActionMockSimulation(): void
    {
        // 1. Prepare pending payment session
        $session = new PaymentSession();
        $session->id = 45;
        $session->orderCode = 'HDPE5XTESTSIM';
        $session->amount = 30000.0;
        $session->status = 'pending';
        $session->targetId = 5;
        $session->targetType = 'fine';
        $session->expiredAt = date('Y-m-d H:i:s', time() + 900); // not expired

        // 2. Mock tables & services
        $sessionTableMock = $this->createMock(PaymentSessionTable::class);
        $sessionTableMock->method('getSession')
            ->with('HDPE5XTESTSIM')
            ->willReturn($session);

        $settingsTableMock = $this->createMock(SystemSettingsTable::class);
        $settingsTableMock->method('getSetting')->willReturn(null);
        
        $sessionTableMock->expects(self::once())
            ->method('saveSession')
            ->with(self::callback(function(PaymentSession $s) {
                return $s->status === 'paid' && strpos($s->transactionId, 'MOCK_TXT_') === 0;
            }));

        // DB Adapter Mock for business logic
        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $connectionMock = $this->createMock(\Laminas\Db\Adapter\Driver\ConnectionInterface::class);
        $driverMock = $this->createMock(\Laminas\Db\Adapter\Driver\DriverInterface::class);
        $driverMock->method('getConnection')->willReturn($connectionMock);
        $dbMock->method('getDriver')->willReturn($driverMock);

        // Stub SELECT * FROM fines WHERE fine_id = ?
        $stmtMock = $this->createMock(StatementInterface::class);
        $resMock = $this->createMock(ResultInterface::class);
        $resMock->method('current')->willReturn([
            'fine_id' => 5,
            'user_id' => 18,
            'amount' => 30000.0,
            'status' => 'unpaid'
        ]);
        $stmtMock->method('execute')->willReturn($resMock);

        // Stub SELECT COUNT(*) AS cnt FROM fines WHERE user_id = ? AND status = 'unpaid' (unpaid count = 0 after payment)
        $stmtCountMock = $this->createMock(StatementInterface::class);
        $resCountMock = $this->createMock(ResultInterface::class);
        $resCountMock->method('current')->willReturn(['cnt' => 0]);
        $stmtCountMock->method('execute')->willReturn($resCountMock);

        $dbMock->method('createStatement')->willReturnCallback(function($sql) use ($stmtCountMock) {
            if (strpos($sql, 'SELECT COUNT(*)') !== false) {
                return $stmtCountMock;
            }
            throw new \Exception("Unexpected sql: " . $sql);
        });

        // Mock statement executes (UPDATE fines, INSERT notifications)
        $stmtExecMock = $this->createMock(StatementInterface::class);
        $resExecMock = $this->createMock(ResultInterface::class);
        $resExecMock->method('getAffectedRows')->willReturn(1);
        $stmtExecMock->method('execute')->willReturn($resExecMock);
        
        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtMock, $stmtExecMock) {
            if (strpos($sql, 'SELECT * FROM fines') !== false) {
                return $stmtMock;
            }
            return $stmtExecMock;
        });

        // Mock user table actions
        $userTableMock = $this->createMock(UserTable::class);
        $userTableMock->expects(self::once())
            ->method('unlockUser')
            ->with(18);

        $userObj = new \Library\Model\Entity\User();
        $userObj->id = 18;
        $userObj->borrowLimit = 1;
        $userTableMock->method('getUser')->with(18)->willReturn($userObj);
        
        $userTableMock->expects(self::once())
            ->method('saveUser')
            ->with(self::callback(function($user) {
                return $user->borrowLimit === 5;
            }));

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(PaymentSessionTable::class, $sessionTableMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);
        $serviceLocator->setService(SystemSettingsTable::class, $settingsTableMock);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        // Dispatch request to simulator
        $mockEmail = "BIDV: 10/06/2026 14:00. TK: 9876543210. Giao dich: +30,000đ. Noi dung: HDPE5XTESTSIM";
        
        $this->getRequest()->setContent(json_encode([
            'simulate_email_body' => $mockEmail
        ]));

        $this->dispatch('/api/payment/gmail-webhook', 'POST');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('success', $response['status']);
        $this->assertEquals('simulator', $response['source']);
        $this->assertContains('HDPE5XTESTSIM', $response['processedCodes']);
    }
}
