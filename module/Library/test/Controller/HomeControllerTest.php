<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\HomeController;
use Library\Session\AuthSessionContainer;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class HomeControllerTest extends AbstractHttpControllerTestCase
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

    public function testRootRedirectsToCatalog(): void
    {
        $this->dispatch('/', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertControllerName(HomeController::class);
        $this->assertMatchedRouteName('home');
        $this->assertRedirectTo('/books');
    }

    public function testRootRedirectsAuthenticatedAdminToAdminBooks(): void
    {
        $this->mockLoginAsRole('admin');

        $this->dispatch('/', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/books');
    }

    public function testRootRedirectsAuthenticatedStudentToCatalog(): void
    {
        $this->mockLoginAsRole('student');

        $this->dispatch('/', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/books');
    }

    public function testAdminEntryRedirectsToLogin(): void
    {
        $this->dispatch('/admin', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertControllerName(HomeController::class);
        $this->assertMatchedRouteName('library');
        $this->assertRedirectTo('/admin/auth');
    }

    public function testBooksIndexRequiresLogin(): void
    {
        $this->dispatch('/admin/books', 'GET');

        $this->assertResponseStatusCode(302);
        $this->assertRedirectTo('/admin/auth');
    }

    public function testInvalidRouteDoesNotCrash(): void
    {
        $this->dispatch('/khong-ton-tai', 'GET');

        $this->assertResponseStatusCode(404);
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
