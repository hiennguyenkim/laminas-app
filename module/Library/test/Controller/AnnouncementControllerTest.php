<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\AnnouncementController;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class AnnouncementControllerTest extends AbstractHttpControllerTestCase
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

    public function testAddAnnouncementActionRedirectsWithErrorWhenEndDateIsBeforeStartDate(): void
    {
        $this->mockLoginAsRole('admin');

        $this->dispatch('/admin/announcements/add', 'POST', [
            'title' => 'Test Announcement',
            'content' => 'Test Content',
            'type' => 'general',
            'start_date' => '2026-05-24',
            'end_date' => '2026-05-23',
            'is_active' => '1',
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/announcements/add');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
        $errorMessages = $flashMessenger->getCurrentErrorMessages();
        $this->assertContains('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày bắt đầu (Từ ngày).', $errorMessages);
    }

    public function testAddAnnouncementActionRedirectsWithErrorWhenEndDateIsBeforeToday(): void
    {
        $this->mockLoginAsRole('admin');

        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->dispatch('/admin/announcements/add', 'POST', [
            'title' => 'Test Announcement',
            'content' => 'Test Content',
            'type' => 'general',
            'start_date' => null,
            'end_date' => $yesterday,
            'is_active' => '1',
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/announcements/add');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentErrorMessages());
        $errorMessages = $flashMessenger->getCurrentErrorMessages();
        $this->assertContains('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày hiện tại.', $errorMessages);
    }

    public function testAddAnnouncementActionSavesWhenDatesAreValid(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        // Mock system_settings queries (maintenance mode check in Module.php)
        $stmtSettingsMock = $this->createMock(StatementInterface::class);
        $resultSettingsMock = $this->createMock(ResultInterface::class);
        $resultSettingsMock->method('rewind')->willReturnCallback(function() {});
        $resultSettingsMock->method('valid')->willReturn(false); // No maintenance settings (mode is off)
        $stmtSettingsMock->method('execute')->willReturn($resultSettingsMock);

        // Mock insert query
        $stmtInsertMock = $this->createMock(StatementInterface::class);
        $resultInsertMock = $this->createMock(ResultInterface::class);
        $stmtInsertMock->method('execute')->willReturn($resultInsertMock);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtSettingsMock, $stmtInsertMock) {
            if (strpos($sql, 'system_settings') !== false) {
                return $stmtSettingsMock;
            }
            if (strpos($sql, 'INSERT INTO announcements') !== false) {
                return $stmtInsertMock;
            }
            throw new \Exception("Unexpected query: " . $sql);
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/announcements/add', 'POST', [
            'title' => 'Test Announcement',
            'content' => 'Test Content',
            'type' => 'general',
            'start_date' => '2026-05-23',
            'end_date' => '2026-05-24',
            'is_active' => '1',
        ]);

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/announcements');

        $flashMessenger = $this->getApplicationServiceLocator()->get('ControllerPluginManager')->get('flashMessenger');
        $this->assertTrue($flashMessenger->hasCurrentSuccessMessages());
        $successMessages = $flashMessenger->getCurrentSuccessMessages();
        $this->assertContains('Đã đăng bản tin "Test Announcement" thành công.', $successMessages);
    }

    public function testAnnouncementsActionForAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        $stmtMock1 = $this->createMock(StatementInterface::class);
        $resultMock1 = $this->createMock(ResultInterface::class);
        $resultMock1->method('current')->willReturn([
            'total_count' => 10,
            'active_count' => 4,
            'upcoming_count' => 2,
            'expired_count' => 3,
            'hidden_count' => 1
        ]);
        $stmtMock1->method('execute')->willReturn($resultMock1);

        $stmtMock2 = $this->createMock(StatementInterface::class);
        $resultMock2 = $this->createMock(ResultInterface::class);
        $announcements = [
            [
                'id' => 1,
                'title' => 'Active Ann',
                'content' => 'Content',
                'type' => 'general',
                'start_date' => null,
                'end_date' => null,
                'is_active' => 1,
                'created_at' => '2026-05-23 12:00:00',
                'creator_name' => 'Quản trị viên'
            ]
        ];
        
        $index = 0;
        $resultMock2->method('rewind')->willReturnCallback(function() use (&$index) { $index = 0; });
        $resultMock2->method('valid')->willReturnCallback(function() use (&$index, $announcements) { return $index < count($announcements); });
        $resultMock2->method('current')->willReturnCallback(function() use (&$index, $announcements) { return $announcements[$index]; });
        $resultMock2->method('next')->willReturnCallback(function() use (&$index) { $index++; });
        $stmtMock2->method('execute')->willReturn($resultMock2);

        $stmtMock3 = $this->createMock(StatementInterface::class);
        $resultMock3 = $this->createMock(ResultInterface::class);
        $resultMock3->method('current')->willReturn(['cnt' => 1]);
        $stmtMock3->method('execute')->willReturn($resultMock3);

        $stmtMock4 = $this->createMock(StatementInterface::class);
        $resultMock4 = $this->createMock(ResultInterface::class);
        $resultMock4->method('rewind')->willReturnCallback(function() {});
        $resultMock4->method('valid')->willReturn(false);
        $stmtMock4->method('execute')->willReturn($resultMock4);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtMock1, $stmtMock2, $stmtMock3, $stmtMock4) {
            if (strpos($sql, 'COUNT(*) AS total_count') !== false) {
                return $stmtMock1;
            }
            if (strpos($sql, 'SELECT a.*, u.full_name AS creator_name') !== false) {
                return $stmtMock2;
            }
            if (strpos($sql, 'SELECT COUNT(*) as cnt') !== false) {
                return $stmtMock3;
            }
            return $stmtMock4;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/admin/announcements?status=active', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertModuleName('Library');
        $this->assertControllerName(AnnouncementController::class);
        $this->assertControllerClass('AnnouncementController');
        $this->assertMatchedRouteName('library/announcements');
    }

    public function testAdminVisitsGuestAnnouncementsRedirectsToAdminAnnouncements(): void
    {
        $this->mockLoginAsRole('admin');

        $this->dispatch('/announcements', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/announcements');
    }

    public function testStudentVisitsGuestAnnouncementsRedirectsToStudentAnnouncements(): void
    {
        $this->mockLoginAsRole('student');

        $this->dispatch('/announcements', 'GET');
        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/student/announcements');
    }

    public function testGuestVisitsAnnouncementsRendersSuccessfully(): void
    {
        // No login (guest)
        $this->dispatch('/announcements', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertMatchedRouteName('announcements');
    }

    public function testViewAnnouncementActionForAdmin(): void
    {
        $this->mockLoginAsRole('admin');

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);

        $stmtMock = $this->createMock(StatementInterface::class);
        $resultMock = $this->createMock(ResultInterface::class);
        $resultMock->method('current')->willReturn([
            'id' => 5,
            'title' => 'Test View Ann',
            'content' => 'Test Content to View',
            'type' => 'general',
            'start_date' => null,
            'end_date' => null,
            'is_active' => 1,
            'created_at' => '2026-05-23 12:00:00',
            'creator_name' => 'Quản trị viên'
        ]);
        $stmtMock->method('execute')->willReturn($resultMock);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtMock) {
            if (strpos($sql, 'SELECT a.*, COALESCE') !== false) {
                return $stmtMock;
            }
            // Ignore other maintenance mode checks
            $stmtEmpty = $this->createMock(StatementInterface::class);
            $resEmpty = $this->createMock(ResultInterface::class);
            $resEmpty->method('rewind')->willReturnCallback(function() {});
            $resEmpty->method('valid')->willReturn(false);
            $stmtEmpty->method('execute')->willReturn($resEmpty);
            return $stmtEmpty;
        });

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(AdapterInterface::class, $dbMock);

        $this->dispatch('/announcements/view/5', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertMatchedRouteName('announcements/view');
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
