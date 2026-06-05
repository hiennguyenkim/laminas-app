<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\BookController;
use Library\Model\Entity\Book;
use Library\Model\Table\BookTable;
use Library\Session\AuthSessionContainer;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class BookControllerTest extends AbstractHttpControllerTestCase
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

    public function testIndexActionCanBeAccessedPublicly(): void
    {
        $this->dispatch('/books', 'GET');
        $this->assertResponseStatusCode(200);
        $this->assertModuleName('Library');
        $this->assertControllerName(BookController::class);
        $this->assertControllerClass('BookController');
        $this->assertMatchedRouteName('catalog');
    }

    public function testViewActionWithPreviewUrlDisplaysPreviewButton(): void
    {
        $this->mockLoginAsRole('student');

        // Get BookTable and mock getBook
        $bookTableMock = $this->createMock(BookTable::class);
        $book = new Book();
        $book->id = 99;
        $book->title = 'Sách Thử Nghiệm';
        $book->author = 'Tác giả A';
        $book->category = 'Công nghệ thông tin';
        $book->quantity = 2;
        $book->status = 'available';
        $book->previewUrl = 'https://example.com/test-preview.pdf';
        
        $bookTableMock->method('getBook')->with(99)->willReturn($book);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);

        $this->dispatch('/student/book/view/99', 'GET');
        
        $this->assertResponseStatusCode(200);
        $this->assertQuery('button[onclick*="openPreviewModal"]');
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
