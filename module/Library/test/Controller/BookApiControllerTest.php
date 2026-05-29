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
        $bookTableMock->expects(self::any())
            ->method('searchAvailable')
            ->willReturnCallback(function($title, $avail, $limit) {
                if ($title === 'Chí Phèo') {
                    return [['id' => 10, 'title' => 'Chí Phèo', 'author' => 'Nam Cao']];
                }
                if ($title === 'Tắt Đèn') {
                    return [['id' => 11, 'title' => 'Tắt Đèn', 'author' => 'Ngô Tất Tố']];
                }
                return [];
            });

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        // Mock categories query
        $stmtCat = $this->createMock(StatementInterface::class);
        $resCat = $this->createMock(ResultInterface::class);
        $categories = [['name' => 'Văn học']];
        $catIdx = 0;
        $resCat->method('rewind')->willReturnCallback(function() use (&$catIdx) { $catIdx = 0; });
        $resCat->method('valid')->willReturnCallback(function() use (&$catIdx, $categories) { return $catIdx < count($categories); });
        $resCat->method('current')->willReturnCallback(function() use (&$catIdx, $categories) { return $categories[$catIdx]; });
        $resCat->method('next')->willReturnCallback(function() use (&$catIdx) { $catIdx++; });
        $stmtCat->method('execute')->willReturn($resCat);

        // Mock books query
        $stmtBooks = $this->createMock(StatementInterface::class);
        $resBooks = $this->createMock(ResultInterface::class);
        $books = [
            ['title' => 'Chí Phèo', 'author' => 'Nam Cao', 'category' => 'Văn học'],
            ['title' => 'Tắt Đèn', 'author' => 'Ngô Tất Tố', 'category' => 'Văn học']
        ];
        $bookIdx = 0;
        $resBooks->method('rewind')->willReturnCallback(function() use (&$bookIdx) { $bookIdx = 0; });
        $resBooks->method('valid')->willReturnCallback(function() use (&$bookIdx, $books) { return $bookIdx < count($books); });
        $resBooks->method('current')->willReturnCallback(function() use (&$bookIdx, $books) { return $books[$bookIdx]; });
        $resBooks->method('next')->willReturnCallback(function() use (&$bookIdx) { $bookIdx++; });
        $stmtBooks->method('execute')->willReturn($resBooks);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtCat, $stmtBooks) {
            if (strpos($sql, 'book_categories') !== false) {
                return $stmtCat;
            }
            return $stmtBooks;
        });

        $bookTableMock->method('getAdapter')->willReturn($dbMock);

        $geminiMock = $this->createMock(\Library\Service\GeminiService::class);
        $geminiMock->expects(self::once())
            ->method('generateResponse')
            ->willReturn('Chào bạn! Đây là gợi ý sách Chí Phèo và Tắt Đèn là các tiểu thuyết và sách văn học hay.');

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(\Laminas\Db\Adapter\AdapterInterface::class, $dbMock);
        $serviceLocator->setService(\Library\Service\GeminiService::class, $geminiMock);

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
        $bookTableMock->expects(self::any())
            ->method('searchAvailable')
            ->willReturnCallback(function($title, $avail, $limit) {
                if ($title === 'Lập trình PHP') {
                    return [['id' => 20, 'title' => 'Lập trình PHP', 'author' => 'Zend']];
                }
                return [];
            });

        $dbMock = $this->createMock(\Laminas\Db\Adapter\Adapter::class);
        
        // Mock categories query
        $stmtCat = $this->createMock(StatementInterface::class);
        $resCat = $this->createMock(ResultInterface::class);
        $categories = [['name' => 'Công nghệ']];
        $catIdx = 0;
        $resCat->method('rewind')->willReturnCallback(function() use (&$catIdx) { $catIdx = 0; });
        $resCat->method('valid')->willReturnCallback(function() use (&$catIdx, $categories) { return $catIdx < count($categories); });
        $resCat->method('current')->willReturnCallback(function() use (&$catIdx, $categories) { return $categories[$catIdx]; });
        $resCat->method('next')->willReturnCallback(function() use (&$catIdx) { $catIdx++; });
        $stmtCat->method('execute')->willReturn($resCat);

        // Mock books query
        $stmtBooks = $this->createMock(StatementInterface::class);
        $resBooks = $this->createMock(ResultInterface::class);
        $books = [
            ['title' => 'Lập trình PHP', 'author' => 'Zend', 'category' => 'Công nghệ']
        ];
        $bookIdx = 0;
        $resBooks->method('rewind')->willReturnCallback(function() use (&$bookIdx) { $bookIdx = 0; });
        $resBooks->method('valid')->willReturnCallback(function() use (&$bookIdx, $books) { return $bookIdx < count($books); });
        $resBooks->method('current')->willReturnCallback(function() use (&$bookIdx, $books) { return $books[$bookIdx]; });
        $resBooks->method('next')->willReturnCallback(function() use (&$bookIdx) { $bookIdx++; });
        $stmtBooks->method('execute')->willReturn($resBooks);

        $dbMock->method('query')->willReturnCallback(function($sql) use ($stmtCat, $stmtBooks) {
            if (strpos($sql, 'book_categories') !== false) {
                return $stmtCat;
            }
            return $stmtBooks;
        });

        $bookTableMock->method('getAdapter')->willReturn($dbMock);

        $geminiMock = $this->createMock(\Library\Service\GeminiService::class);
        $geminiMock->expects(self::once())
            ->method('generateResponse')
            ->willReturn('Chào bạn! Đây là gợi ý sách Lập trình PHP thuộc danh mục Công nghệ thông tin.');

        $serviceLocator = $this->getApplicationServiceLocator();
        $serviceLocator->setAllowOverride(true);
        $serviceLocator->setService(BookTable::class, $bookTableMock);
        $serviceLocator->setService(\Laminas\Db\Adapter\AdapterInterface::class, $dbMock);
        $serviceLocator->setService(\Library\Service\GeminiService::class, $geminiMock);

        $this->getRequest()->setContent(json_encode(['message' => 'Lập trình']));
        
        $this->dispatch('/api/books/chat', 'POST');
        $this->assertResponseStatusCode(200);

        $response = json_decode($this->getResponse()->getContent(), true);
        $this->assertStringContainsString('Công nghệ thông tin', $response['reply']);
        $this->assertCount(1, $response['suggestions']);
    }
}
