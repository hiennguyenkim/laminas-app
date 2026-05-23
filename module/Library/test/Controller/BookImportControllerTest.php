<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\BookImportController;
use Library\Model\Table\BookTable;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class BookImportControllerTest extends AbstractHttpControllerTestCase
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

    public function testIndexActionRequiresAdmin(): void
    {
        $this->dispatch('/admin/books/import', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/auth');
    }

    public function testIndexActionStudentForbidden(): void
    {
        $this->mockLoginAsRole('student');
        $this->dispatch('/admin/books/import', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/student/dashboard');
    }

    public function testIndexActionWithSortingAndFilters(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $resultMock = $this->createMock(ResultInterface::class);

        // We make 4 queries in indexAction:
        // 1. typeCountSql
        // 2. totalSql
        // 3. fetch paginated imports
        // 4. statsSql

        $mockResults = [
            // 1. typeCounts
            [['import_type' => 'purchase', 'cnt' => 2]],
            // 2. totalCount
            [['cnt' => 2]],
            // 3. paginated imports (must verify order by is applied)
            [
                [
                    'import_id' => 1,
                    'book_id' => 10,
                    'invoice_code' => 'INV-1',
                    'title' => 'Book A',
                    'author' => 'Author A',
                    'isbn' => '1234567890',
                    'category' => 'IT',
                    'publisher' => 'Pub A',
                    'published_year' => 2026,
                    'quantity' => 5,
                    'price' => 10000.0,
                    'import_type' => 'purchase',
                    'status' => 'approved',
                    'admin_name' => 'admin',
                    'existing_book_title' => 'Book A',
                    'imported_by' => 1,
                    'invoice_url' => '',
                    'note' => '',
                    'import_date' => '2026-05-23',
                    'created_at' => '2026-05-23 12:00:00'
                ]
            ],
            // 4. quarterly stats
            [['qtr' => 2, 'total_count' => 1, 'total_spend' => 50000.0]]
        ];

        $queryIndex = 0;
        $dbMock->method('query')->willReturnCallback(function ($sql) use (&$queryIndex, $stmtMock, $resultMock, $mockResults) {
            // Verify that for query index 2 (fetching imports), it contains the order by clause
            if (strpos($sql, 'ORDER BY') !== false) {
                if ($queryIndex === 2) {
                    self::assertStringContainsString('ORDER BY i.price ASC', $sql);
                }
            }

            $currentResult = $mockResults[$queryIndex] ?? [];
            $queryIndex++;

            $res = $this->createMock(ResultInterface::class);
            $idx = 0;
            $res->method('rewind')->willReturnCallback(function () use (&$idx) {
                $idx = 0;
            });
            $res->method('valid')->willReturnCallback(function () use (&$idx, $currentResult) {
                return $idx < count($currentResult);
            });
            $res->method('current')->willReturnCallback(function () use (&$idx, $currentResult) {
                return $currentResult[$idx];
            });
            $res->method('key')->willReturnCallback(function () use (&$idx) {
                return $idx;
            });
            $res->method('next')->willReturnCallback(function () use (&$idx) {
                $idx++;
            });

            $stmt = $this->createMock(StatementInterface::class);
            $stmt->method('execute')->willReturn($res);
            return $stmt;
        });

        $bookTableMock = $this->createMock(BookTable::class);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/books/import?sort=price&direction=asc', 'GET');

        $this->assertResponseStatusCode(200);
        $this->assertControllerName(BookImportController::class);
        $this->assertMatchedRouteName('library/books-import');
    }

    public function testAddActionGet(): void
    {
        $this->mockLoginAsRole('admin');
        $this->dispatch('/admin/books/import/add', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertControllerName(BookImportController::class);
        $this->assertMatchedRouteName('library/books-import');
    }

    public function testAddActionPost(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $resMock = $this->createMock(ResultInterface::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $driverMock = $this->createMock(\Laminas\Db\Adapter\Driver\DriverInterface::class);

        $resMock->method('current')->willReturn(null);
        $driverMock->method('getLastGeneratedValue')->willReturn(99);
        $dbMock->method('getDriver')->willReturn($driverMock);

        $stmtMock->method('execute')->willReturn($resMock);
        $dbMock->method('query')->willReturn($stmtMock);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $postData = [
            'title' => 'Direct Import Book Title',
            'quantity' => 10,
            'price' => 50000,
            'import_type' => 'purchase'
        ];
        $this->dispatch('/admin/books/import/add', 'POST', $postData);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/books/import');
    }

    public function testDownloadTemplateAction(): void
    {
        $this->mockLoginAsRole('admin');
        $this->dispatch('/admin/books/import/downloadTemplate', 'GET');
        $this->assertResponseStatusCode(200);
        $responseHeaders = $this->getResponse()->getHeaders();
        $this->assertTrue($responseHeaders->has('Content-Disposition'));
        $contentDisposition = $responseHeaders->get('Content-Disposition')->getFieldValue();
        $this->assertStringContainsString('mau_nhap_kho_sach.xlsx', $contentDisposition);
    }

    public function testImportExcelAction(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $resMock = $this->createMock(ResultInterface::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $driverMock = $this->createMock(\Laminas\Db\Adapter\Driver\DriverInterface::class);

        $resMock->method('current')->willReturn(null);
        $driverMock->method('getLastGeneratedValue')->willReturn(101);
        $dbMock->method('getDriver')->willReturn($driverMock);

        $stmtMock->method('execute')->willReturn($resMock);
        $dbMock->method('query')->willReturn($stmtMock);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Tên sách (Bắt buộc)', 'Tác giả', 'ISBN', 'Thể loại', 'Nhà xuất bản', 'Năm xuất bản', 'Hình thức nhập', 'Số lượng nhập', 'Đơn giá', 'Mã hóa đơn', 'Đường dẫn hóa đơn', 'Ghi chú'],
            ['Excel Import Book 1', 'Author X', '9781234567890', 'Science', 'Publisher Y', '2023', 'purchase', '12', '120000', 'HD-EX-001', '', 'Excel Note']
        ]);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'test_excel_import');
        $writer->save($tempFile);

        $request = $this->getRequest();
        $fileParams = new \Laminas\Stdlib\Parameters([
            'excel_file' => [
                'name' => 'test_import.xlsx',
                'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'tmp_name' => $tempFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tempFile)
            ]
        ]);
        $request->setFiles($fileParams);

        $this->dispatch('/admin/books/import/importExcel', 'POST');

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/books/import');
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
