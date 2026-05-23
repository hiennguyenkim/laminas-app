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
            ->with(['search' => 'PHP', 'status' => 'borrowed', 'user_id' => '', 'year' => 'all', 'period' => 'year', 'week' => 'all', 'sort' => null, 'direction' => null], null, 0, 0)
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
        $this->assertStringContainsString('bao-cao-muon-tra-', $headers->get('Content-Disposition')->getFieldValue());
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $headers->get('Content-Type')->getFieldValue());

        $content = $response->getContent();
        $this->assertNotEmpty($content);

        // Write content to temporary file to load with PhpSpreadsheet and verify values
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tempFile, $content);
        
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($tempFile);
        unlink($tempFile);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertEquals('CHI TIẾT PHIẾU MƯỢN TRẢ SÁCH THƯ VIỆN', $sheet->getCell('A1')->getValue());
        $this->assertEquals('Mã giao dịch', $sheet->getCell('A6')->getValue());
        $this->assertEquals(101, $sheet->getCell('A7')->getValue());
        $this->assertEquals('Nguyễn Văn An', $sheet->getCell('B7')->getValue());
        $this->assertEquals('Lập trình PHP', $sheet->getCell('D7')->getValue());
        $this->assertEquals('Đang mượn', $sheet->getCell('I7')->getValue());
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
        $borrowTableMock->expects(self::once())
            ->method('fetchAllWithDetails')
            ->with(['search' => '', 'status' => '', 'year' => 'all', 'period' => 'year', 'week' => 'all', 'sort' => null, 'direction' => null], 2, 0, 0)
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
        $this->assertNotEmpty($content);

        // Write content to temporary file to load with PhpSpreadsheet and verify values
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        file_put_contents($tempFile, $content);
        
        $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        $spreadsheet = $reader->load($tempFile);
        unlink($tempFile);

        $sheet = $spreadsheet->getActiveSheet();
        $this->assertEquals(102, $sheet->getCell('A7')->getValue());
        $this->assertEquals('Lập trình JS', $sheet->getCell('D7')->getValue());
        $this->assertEquals('Đã trả', $sheet->getCell('I7')->getValue());
    }

    public function testIndexActionWithYearAndPeriodFilters(): void
    {
        $this->mockLoginAsRole('admin');

        $record = new BorrowRecord();
        $record->exchangeArray([
            'id' => 103,
            'book_id' => 1,
            'user_id' => 2,
            'borrow_date' => '2026-05-23',
            'return_date' => '2026-06-06',
            'returned_at' => '',
            'status' => 'borrowed',
            'book_title' => 'PHP Book',
            'book_isbn' => '9781234567890',
            'full_name' => 'Nguyễn Văn An',
            'username' => 'student1',
        ]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('countFiltered')
            ->with(['search' => '', 'status' => '', 'year' => '2026', 'period' => 'm5', 'week' => 'all', 'sort' => null, 'direction' => null, 'user_id' => null], null)
            ->willReturn(1);
        $borrowTableMock->expects(self::once())
            ->method('fetchAllWithDetails')
            ->with(['search' => '', 'status' => '', 'year' => '2026', 'period' => 'm5', 'week' => 'all', 'sort' => null, 'direction' => null, 'user_id' => null], null, 10, 0)
            ->willReturn([$record]);
        $borrowTableMock->method('getSummary')->willReturn([
            'borrowed' => 1, 'overdue' => 0, 'returned' => 0, 'pending' => 0, 'total' => 1
        ]);

        $userTableMock = $this->createMock(UserTable::class);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        $this->dispatch('/admin/borrow?year=2026&period=m5', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TransactionController::class);
        $this->assertMatchedRouteName('library/transaction');
    }

    public function testIndexActionWithWeekFilter(): void
    {
        $this->mockLoginAsRole('admin');

        $record = new BorrowRecord();
        $record->exchangeArray([
            'id' => 104,
            'book_id' => 1,
            'user_id' => 2,
            'borrow_date' => '2026-05-23',
            'return_date' => '2026-06-06',
            'returned_at' => '',
            'status' => 'borrowed',
            'book_title' => 'PHP Book',
            'book_isbn' => '9781234567890',
            'full_name' => 'Nguyễn Văn An',
            'username' => 'student1',
        ]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('countFiltered')
            ->with(['search' => '', 'status' => '', 'year' => '2026', 'period' => 'm5', 'week' => '21', 'sort' => null, 'direction' => null, 'user_id' => null], null)
            ->willReturn(1);
        $borrowTableMock->expects(self::once())
            ->method('fetchAllWithDetails')
            ->with(['search' => '', 'status' => '', 'year' => '2026', 'period' => 'm5', 'week' => '21', 'sort' => null, 'direction' => null, 'user_id' => null], null, 10, 0)
            ->willReturn([$record]);
        $borrowTableMock->method('getSummary')->willReturn([
            'borrowed' => 1, 'overdue' => 0, 'returned' => 0, 'pending' => 0, 'total' => 1
        ]);

        $userTableMock = $this->createMock(UserTable::class);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        $this->dispatch('/admin/borrow?year=2026&period=m5&week=21', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TransactionController::class);
        $this->assertMatchedRouteName('library/transaction');
    }

    public function testIndexActionWithSorting(): void
    {
        $this->mockLoginAsRole('admin');

        $record = new BorrowRecord();
        $record->exchangeArray([
            'id' => 105,
            'book_id' => 1,
            'user_id' => 2,
            'borrow_date' => '2026-05-23',
            'return_date' => '2026-06-06',
            'returned_at' => '',
            'status' => 'borrowed',
            'book_title' => 'PHP Book',
            'book_isbn' => '9781234567890',
            'full_name' => 'Nguyễn Văn An',
            'username' => 'student1',
        ]);

        $borrowTableMock = $this->createMock(BorrowTable::class);
        $borrowTableMock->expects(self::once())
            ->method('countFiltered')
            ->with(['search' => '', 'status' => '', 'year' => 'all', 'period' => 'year', 'week' => 'all', 'sort' => 'book', 'direction' => 'asc', 'user_id' => null], null)
            ->willReturn(1);
        $borrowTableMock->expects(self::once())
            ->method('fetchAllWithDetails')
            ->with(['search' => '', 'status' => '', 'year' => 'all', 'period' => 'year', 'week' => 'all', 'sort' => 'book', 'direction' => 'asc', 'user_id' => null], null, 10, 0)
            ->willReturn([$record]);
        $borrowTableMock->method('getSummary')->willReturn([
            'borrowed' => 1, 'overdue' => 0, 'returned' => 0, 'pending' => 0, 'total' => 1
        ]);

        $userTableMock = $this->createMock(UserTable::class);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BorrowTable::class, $borrowTableMock);
        $serviceLocator->setService(UserTable::class, $userTableMock);

        $this->dispatch('/admin/borrow?sort=book&direction=asc', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(TransactionController::class);
        $this->assertMatchedRouteName('library/transaction');
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
