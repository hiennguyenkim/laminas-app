<?php

declare(strict_types=1);

namespace LibraryTest\Controller;

use Library\Controller\Api\BookApiController;
use Library\Model\Table\BookTable;
use Library\Session\AuthSessionContainer;
use Laminas\Db\Adapter\AdapterInterface;
use Laminas\Db\Adapter\Driver\StatementInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Test\PHPUnit\Controller\AbstractHttpControllerTestCase;

class BookApiControllerTest extends AbstractHttpControllerTestCase
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

    public function testChatActionGetMethodNotAllowed(): void
    {
        $this->dispatch('/api/books/chat', 'GET');
        $this->assertResponseStatusCode(405);
        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('Method not allowed', $response['error']);
    }

    public function testChatActionEmptyMessage(): void
    {
        $this->dispatch('/api/books/chat', 'POST', ['message' => '']);
        $this->assertResponseStatusCode(400);
        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertEquals('Tin nhắn trống', $response['error']);
    }

    public function testChatActionLiteratureCategory(): void
    {
        $bookTableMock = $this->createMock(BookTable::class);
        $bookTableMock->expects(self::once())
            ->method('searchAvailable')
            ->with('Văn học', true, 3)
            ->willReturn([
                ['id' => 10, 'title' => 'Chí Phèo', 'author' => 'Nam Cao'],
                ['id' => 11, 'title' => 'Tắt Đèn', 'author' => 'Ngô Tất Tố']
            ]);

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $stmtMock->method('execute')->willReturn($this->createMock(ResultInterface::class));
        $dbMock->method('query')->willReturn($stmtMock);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(\Laminas\Db\Adapter\AdapterInterface::class, $dbMock);

        // Laminas Test uses post body as JSON when dispatching JSON payload. 
        // We set the raw request body in the request object directly.
        $this->getRequest()->setContent(json_encode(['message' => 'Tôi muốn tìm sách văn học']));
        
        $this->dispatch('/api/books/chat', 'POST');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertStringContainsString('tiểu thuyết và sách văn học hay', $response['reply']);
        $this->assertCount(2, $response['suggestions']);
        $this->assertEquals('Chí Phèo', $response['suggestions'][0]['title']);
    }

    public function testChatActionTechCategory(): void
    {
        $bookTableMock = $this->createMock(BookTable::class);
        $bookTableMock->expects(self::once())
            ->method('searchAvailable')
            ->with('Công nghệ', true, 3)
            ->willReturn([
                ['id' => 20, 'title' => 'Lập trình PHP', 'author' => 'Zend']
            ]);

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        $stmtMock = $this->createMock(StatementInterface::class);
        $stmtMock->method('execute')->willReturn($this->createMock(ResultInterface::class));
        $dbMock->method('query')->willReturn($stmtMock);

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(\Laminas\Db\Adapter\AdapterInterface::class, $dbMock);

        $this->getRequest()->setContent(json_encode(['message' => 'Lập trình']));
        
        $this->dispatch('/api/books/chat', 'POST');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertStringContainsString('Công nghệ thông tin', $response['reply']);
        $this->assertCount(1, $response['suggestions']);
    }
}
