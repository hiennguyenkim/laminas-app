<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Library\Model\Entity\Book;
use Library\Model\Table\BookTable;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
class BookApiController extends AbstractRestfulController
{
    private BookTable $table;
    private \Laminas\Db\Adapter\AdapterInterface $dbAdapter;

    public function __construct(BookTable $table, \Laminas\Db\Adapter\AdapterInterface $dbAdapter = null)
    {
        $this->table = $table;
        $this->dbAdapter = $dbAdapter ?: \Laminas\Db\TableGateway\Feature\GlobalAdapterFeature::getStaticAdapter();
    }

    // GET /api/books
    public function getList()
    {
        $books = $this->table->fetchAll();
        $data = [];
        foreach ($books as $book) {
            if (! $book instanceof Book) {
                continue;
            }

            $data[] = $book->getArrayCopy();
        }
        return $this->jsonResponse($data);
    }
    // GET /api/books/:id
    public function get(mixed $id)
    {
        try {
            $book = $this->table->getBook((int) $id);
            return $this->jsonResponse($book->getArrayCopy());
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => 'Book not found'], 404);
        }
    }

    // POST /api/books
    public function create(mixed $data)
    {
        $data = $this->requestJsonBody();
        $book = new Book();
        $book->exchangeArray($data);
        try {
            $this->table->saveBook($book);
            return $this->jsonResponse(['status' => 'success', 'message' => 'Book created'], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // PUT /api/books/:id
    public function update(mixed $id, mixed $data)
    {
        $data = $this->requestJsonBody();
        try {
            $book = $this->table->getBook((int) $id);
            $book->exchangeArray($data);
            $book->id = (int) $id;
            $this->table->saveBook($book);
            return $this->jsonResponse(['status' => 'success', 'message' => 'Book updated']);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    // DELETE /api/books/:id
    public function delete(mixed $id)
    {
        try {
            $this->table->deleteBook((int) $id);
            return $this->jsonResponse(['status' => 'success', 'message' => 'Book deleted']);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/books/search?q=...&available_only=1&limit=20
     *
     * Endpoint AJAX autocomplete cho form mượn sách.
     * Trả về JSON array các sách phù hợp với từ khóa tìm kiếm.
     */
    public function searchAction(): Response
    {
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();

        // Chỉ cho phép GET
        if (! $request->isGet()) {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Lấy tham số từ query string
        $query         = trim((string) $request->getQuery('q', ''));
        $availableOnly = $request->getQuery('available_only', '1') !== '0';
        $limit         = max(1, min(50, (int) $request->getQuery('limit', '20')));

        // Cần ít nhất 1 ký tự để tìm kiếm
        if ($query === '') {
            return $this->jsonResponse([]);
        }

        $books = $this->table->searchAvailable($query, $availableOnly, $limit);

        // Format kết quả cho autocomplete dropdown
        $results = array_map(static function (array $book): array {
            return [
                'id'       => $book['id'],
                'title'    => $book['title'],
                'author'   => $book['author'],
                'isbn'     => $book['isbn'],
                'category' => $book['category'],
                'quantity' => $book['quantity'],
                // Label hiển thị trong dropdown
                'label'    => $book['title'] . ' — ' . $book['author']
                    . ' (còn ' . $book['quantity'] . ' cuốn)',
            ];
        }, $books);

        return $this->jsonResponse($results);
    }

    /**
     * POST /api/books/chat
     * Endpoint xử lý Chatbox tư vấn sách
     */
    public function chatAction(): Response
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        $payload = $this->requestJsonBody();
        $message = trim((string)($payload['message'] ?? ''));
        $userId  = !empty($payload['user_id']) ? (int)$payload['user_id'] : null;

        if ($message === '') {
            return $this->jsonResponse(['error' => 'Tin nhắn trống'], 400);
        }

        $responseMsg = "Xin lỗi, tôi chưa hiểu rõ ý bạn. Bạn có thể nói rõ thể loại hoặc tên sách bạn muốn tìm không?";
        $suggestions = [];

        $lowerMsg = mb_strtolower($message, 'UTF-8');
        
        // Keyword-based recommendation
        if (strpos($lowerMsg, 'văn học') !== false || strpos($lowerMsg, 'tiểu thuyết') !== false) {
            $books = $this->table->searchAvailable('Văn học', true, 3);
            if ($books) {
                $responseMsg = "Chào bạn! Đây là một số cuốn tiểu thuyết và sách văn học hay mà thư viện đang có sẵn:";
                foreach ($books as $b) {
                    $suggestions[] = ['title' => $b['title'], 'author' => $b['author'], 'id' => $b['id']];
                }
            } else {
                $responseMsg = "Hiện tại các sách Văn học đã hết, bạn vui lòng quay lại sau nhé.";
            }
        } elseif (strpos($lowerMsg, 'công nghệ') !== false || strpos($lowerMsg, 'cntt') !== false || strpos($lowerMsg, 'lập trình') !== false) {
            $books = $this->table->searchAvailable('Công nghệ', true, 3);
            if ($books) {
                $responseMsg = "Bạn đang tìm sách Công nghệ thông tin? Dưới đây là các đầu sách nổi bật:";
                foreach ($books as $b) {
                    $suggestions[] = ['title' => $b['title'], 'author' => $b['author'], 'id' => $b['id']];
                }
            }
        } elseif (strpos($lowerMsg, 'kinh tế') !== false || strpos($lowerMsg, 'kinh doanh') !== false) {
            $books = $this->table->searchAvailable('Kinh tế', true, 3);
            if ($books) {
                $responseMsg = "Sách Kinh tế - Quản trị thư viện đang có sẵn những cuốn này nè:";
                foreach ($books as $b) {
                    $suggestions[] = ['title' => $b['title'], 'author' => $b['author'], 'id' => $b['id']];
                }
            }
        } else {
            // General search
            $books = $this->table->searchAvailable($lowerMsg, true, 2);
            if ($books) {
                $responseMsg = "Tôi tìm thấy vài cuốn sách có thể khớp với từ khóa của bạn:";
                foreach ($books as $b) {
                    $suggestions[] = ['title' => $b['title'], 'author' => $b['author'], 'id' => $b['id']];
                }
            } else {
                $responseMsg = "Hiện tại tôi chưa tìm thấy sách nào khớp với yêu cầu của bạn. Bạn có thể tìm thể loại khác nhé (VD: Công nghệ, Văn học, Khoa học...).";
            }
        }

        // Save to DB
        if ($this->dbAdapter) {
            try {
                $sql = 'INSERT INTO chat_logs (user_id, message, response, created_at) VALUES (?, ?, ?, NOW())';
                $this->dbAdapter->query($sql, [$userId, $message, $responseMsg]);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return $this->jsonResponse([
            'reply' => $responseMsg,
            'suggestions' => $suggestions
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJsonBody(): array
    {
        $content = $this->getRequest()->getContent();
        if (! is_string($content) || $content === '') {
            return [];
        }

        $payload = json_decode($content, true);
        if (! is_array($payload)) {
            return [];
        }

        return $this->normalizePayload($payload);
    }

    /**
     * Helper to return JSON response with correct Vietnamese encoding.
     */
    private function jsonResponse(array $data, int $statusCode = 200): Response
    {

        $response = $this->getResponse();
        if (! $response instanceof Response) {
            throw new \RuntimeException('Unexpected response instance.');
        }

        $response->setStatusCode($statusCode);
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        return $response;
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
