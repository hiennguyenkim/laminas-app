<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\TransactionController;
use Library\Model\Entity\BorrowRecord;
use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Library\Service\CirculationService;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class TransactionControllerTest extends AbstractHttpControllerTestCase
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

    public function testExportActionAsAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $record = new BorrowRecord();
        $record->exchangeArray([
            'id' => 101,
            'book_id' => 1,
            'user_id' => 2,
            'borrow_date' => '2026-05-01',
            'return_date' => '2026-05-15',
            'returned_at' => '',
            'status' => 'borrowed',
            'book_title' => 'Lập trình PHP',
            'book_isbn' => '9781234567890',
            'full_name' => 'Nguyễn Văn An',
            'username' => 'student1',
        ]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('fetchAllWithDetails')
            ->with(['search' => 'PHP', 'status' => 'borrowed', 'user_id' => ''], null, 0, 0)
            ->willReturn([$record]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        // Dispatched with search/status filters
        $this->dispatch('/admin/borrow/export?search=PHP&status=borrowed', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TransactionController::class);
        $this->assertMatchedRouteName('library/transaction');

        $response = $this->getResponse();
        $headers = $response->getHeaders();
        $this->assertTrue($headers->has('Content-Disposition'));
        $this->assertStringContainsString('danh-sach-muon-tra.csv', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('text/csv', $headers->get('Content-Type')->getFieldValue());

        $content = $response->getContent();
        // BOM is at the start
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        // Should contain record details
        $this->assertStringContainsString('Mã giao dịch', $content);
        $this->assertStringContainsString('101', $content);
        $this->assertStringContainsString('Nguyễn Văn An', $content);
        $this->assertStringContainsString('Lập trình PHP', $content);
        $this->assertStringContainsString('Đang mượn', $content);
    }

    public function testExportActionAsStudent(): void
    {
        $this->mockLoginAsRole('student');

        $record = new BorrowRecord();
        $record->exchangeArray([
            'id' => 102,
            'book_id' => 2,
            'user_id' => 2,
            'borrow_date' => '2026-05-02',
            'return_date' => '2026-05-16',
            'returned_at' => '2026-05-10 10:00:00',
            'status' => 'returned',
            'book_title' => 'Lập trình JS',
            'book_isbn' => '9789876543210',
            'full_name' => 'Nguyễn Văn An',
            'username' => 'student1',
        ]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        // Student ID is 2, so the controller must pass 2 instead of null
        $borrowTableMock->expects(self::once())
            ->method('fetchAllWithDetails')
            ->with(['search' => '', 'status' => ''], 2, 0, 0)
            ->willReturn([$record]);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);

        $this->dispatch('/student/borrow/export', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TransactionController::class);
        $this->assertMatchedRouteName('student/transaction');

        $response = $this->getResponse();
        $content = $response->getContent();
        $this->assertStringContainsString('102', $content);
        $this->assertStringContainsString('Lập trình JS', $content);
        $this->assertStringContainsString('Đã trả', $content);
        $this->assertStringContainsString('10/05/2026 10:00:00', $content);
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
