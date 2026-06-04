<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\SettingsController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class SettingsControllerTest extends AbstractHttpControllerTestCase
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

    public function testMaintenanceActionRedirectsWithErrorWhenUntilTimeIsPast(): void
    {
        $this->mockLoginAsRole('admin');

        $yesterday = date('Y-m-d H:i:s', strtotime('-1 day'));

        $this->dispatch('/admin/settings/maintenance', 'POST', [
            'maintenance_mode' => '1',
            'maintenance_until' => $yesterday,
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/settings');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
        $errorMessages = $flashMessenger->getCurrentErrorMessages();
        $this->assertContains('Thời gian kết thúc bảo trì phải ở tương lai.', $errorMessages);
    }

    public function testMaintenanceActionSavesWhenValid(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        $stmtCheck = $this->createMock(StatementInterface::class);
        $resCheck = $this->createMock(ResultInterface::class);
        $resCheck->method('current')->willReturn(['count' => 1]);
        $stmtCheck->method('execute')->willReturn($resCheck);

        $stmtUpdate = $this->createMock(StatementInterface::class);
        $resUpdate = $this->createMock(ResultInterface::class);
        $stmtUpdate->method('execute')->willReturn($resUpdate);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtCheck, $stmtUpdate) {
            if (strpos($sql, 'SELECT COUNT(*)') !== false) {
                return $stmtCheck;
            }
            if (strpos($sql, 'UPDATE system_settings') !== false) {
                return $stmtUpdate;
            }
            throw new \Exception("Unexpected SQL: " . $sql);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $futureTime = date('Y-m-d H:i:s', strtotime('+2 hours'));

        $this->dispatch('/admin/settings/maintenance', 'POST', [
            'maintenance_mode' => '1',
            'maintenance_until' => $futureTime,
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/settings');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
        $successMessages = $flashMessenger->getCurrentSuccessMessages();
        $this->assertContains('Đã kích hoạt chế độ bảo trì thành công đến ' . date('H:i d/m/Y', strtotime($futureTime)) . '.', $successMessages);
    }



    public function testAddCategoryActionAddsCategory(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        // Mock check query SELECT id FROM book_categories WHERE name = ?
        $stmtCheckMock = $this->createMock(StatementInterface::class);
        $resultCheckMock = $this->createMock(ResultInterface::class);
        $resultCheckMock->method('current')->willReturn(false); // does not exist
        $stmtCheckMock->method('execute')->with(['Tiểu thuyết viễn tưởng'])->willReturn($resultCheckMock);

        // Mock insert query INSERT INTO book_categories (name) VALUES (?)
        $stmtInsertMock = $this->createMock(StatementInterface::class);
        $resultInsertMock = $this->createMock(ResultInterface::class);
        $stmtInsertMock->method('execute')->with(['Tiểu thuyết viễn tưởng'])->willReturn($resultInsertMock);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtCheckMock, $stmtInsertMock) {
            if (strpos($sql, 'SELECT id FROM book_categories') !== false) {
                return $stmtCheckMock;
            }
            if (strpos($sql, 'INSERT INTO book_categories') !== false) {
                return $stmtInsertMock;
            }
            throw new \Exception("Unexpected query: " . $sql);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/settings/add-category', 'POST', [
            'category_name' => 'Tiểu thuyết viễn tưởng',
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/book/categories');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
        $this->assertContains('Đã thêm danh mục "Tiểu thuyết viễn tưởng" thành công.', $flashMessenger->getCurrentSuccessMessages());
    }

    public function testDeleteCategoryActionDeletesWhenUnused(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Mock check ID select name FROM book_categories WHERE id = ?
        $stmtNameMock = $this->createMock(StatementInterface::class);
        $resultNameMock = $this->createMock(ResultInterface::class);
        $resultNameMock->method('current')->willReturn(['name' => 'Tiểu thuyết viễn tưởng']);
        $stmtNameMock->method('execute')->with([99])->willReturn($resultNameMock);

        // Mock count books query SELECT COUNT(*) as cnt FROM books WHERE category = ?
        $stmtCountMock = $this->createMock(StatementInterface::class);
        $resultCountMock = $this->createMock(ResultInterface::class);
        $resultCountMock->method('current')->willReturn(['cnt' => 0]); // no books
        $stmtCountMock->method('execute')->with(['Tiểu thuyết viễn tưởng'])->willReturn($resultCountMock);

        // Mock delete query DELETE FROM book_categories WHERE id = ?
        $stmtDeleteMock = $this->createMock(StatementInterface::class);
        $resultDeleteMock = $this->createMock(ResultInterface::class);
        $stmtDeleteMock->method('execute')->with([99])->willReturn($resultDeleteMock);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtNameMock, $stmtCountMock, $stmtDeleteMock) {
            if (strpos($sql, 'SELECT name FROM book_categories') !== false) {
                return $stmtNameMock;
            }
            if (strpos($sql, 'SELECT COUNT(*) as cnt FROM books') !== false) {
                return $stmtCountMock;
            }
            if (strpos($sql, 'DELETE FROM book_categories') !== false) {
                return $stmtDeleteMock;
            }
            throw new \Exception("Unexpected query: " . $sql);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/settings/delete-category/99', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/book/categories');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
        $this->assertContains('Đã xóa danh mục "Tiểu thuyết viễn tưởng" thành công.', $flashMessenger->getCurrentSuccessMessages());
    }

    public function testDeleteCategoryActionFailsWhenUsed(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        // Mock check ID select name FROM book_categories WHERE id = ?
        $stmtNameMock = $this->createMock(StatementInterface::class);
        $resultNameMock = $this->createMock(ResultInterface::class);
        $resultNameMock->method('current')->willReturn(['name' => 'Công nghệ thông tin']);
        $stmtNameMock->method('execute')->with([1])->willReturn($resultNameMock);

        // Mock count books query SELECT COUNT(*) as cnt FROM books WHERE category = ?
        $stmtCountMock = $this->createMock(StatementInterface::class);
        $resultCountMock = $this->createMock(ResultInterface::class);
        $resultCountMock->method('current')->willReturn(['cnt' => 5]); // 5 books exist!
        $stmtCountMock->method('execute')->with(['Công nghệ thông tin'])->willReturn($resultCountMock);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtNameMock, $stmtCountMock) {
            if (strpos($sql, 'SELECT name FROM book_categories') !== false) {
                return $stmtNameMock;
            }
            if (strpos($sql, 'SELECT COUNT(*) as cnt FROM books') !== false) {
                return $stmtCountMock;
            }
            throw new \Exception("Unexpected query: " . $sql);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/settings/delete-category/1', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/book/categories');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
        $this->assertContains('Không thể xóa danh mục "Công nghệ thông tin" vì có 5 đầu sách đang thuộc danh mục này.', $flashMessenger->getCurrentErrorMessages());
    }

    public function testEditCategoryActionRenamesCategory(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        $stmtNameMock = $this->createMock(StatementInterface::class);
        $resultNameMock = $this->createMock(ResultInterface::class);
        $resultNameMock->method('current')->willReturn(['name' => 'Công nghệ thông tin']);
        $stmtNameMock->method('execute')->with([1])->willReturn($resultNameMock);

        $stmtCheckMock = $this->createMock(StatementInterface::class);
        $resultCheckMock = $this->createMock(ResultInterface::class);
        $resultCheckMock->method('current')->willReturn(false);
        $stmtCheckMock->method('execute')->with(['IT mới', 1])->willReturn($resultCheckMock);

        $stmtUpdateCatMock = $this->createMock(StatementInterface::class);
        $stmtUpdateCatMock->method('execute')->with(['IT mới', 1])->willReturn($this->createMock(ResultInterface::class));

        $stmtUpdateBooksMock = $this->createMock(StatementInterface::class);
        $stmtUpdateBooksMock->method('execute')->with(['IT mới', 'Công nghệ thông tin'])->willReturn($this->createMock(ResultInterface::class));

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtNameMock, $stmtCheckMock, $stmtUpdateCatMock, $stmtUpdateBooksMock) {
            if (strpos($sql, 'SELECT name FROM book_categories') !== false) {
                return $stmtNameMock;
            }
            if (strpos($sql, 'SELECT id FROM book_categories WHERE name = ? AND id != ?') !== false) {
                return $stmtCheckMock;
            }
            if (strpos($sql, 'UPDATE book_categories') !== false) {
                return $stmtUpdateCatMock;
            }
            if (strpos($sql, 'UPDATE books SET category = ? WHERE category = ?') !== false) {
                return $stmtUpdateBooksMock;
            }
            throw new \Exception("Unexpected query: " . $sql);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/settings/edit-category/1', 'POST', [
            'category_name' => 'IT mới',
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/book/categories');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
        $this->assertContains('Đã đổi tên danh mục "Công nghệ thông tin" thành "IT mới" thành công.', $flashMessenger->getCurrentSuccessMessages());
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
