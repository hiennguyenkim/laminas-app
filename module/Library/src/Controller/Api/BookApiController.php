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
use Library\Model\Table\ChatLogTable;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
class BookApiController extends AbstractRestfulController
{
    public function __construct(
        private BookTable $table,
        private ChatLogTable $chatLogTable,
        private \Library\Service\GeminiService $geminiService
    ) {
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

        // Lấy danh sách sách khớp từ khóa (hoặc sách khả dụng ban đầu nếu từ khóa rỗng)
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
     * Endpoint xử lý Chatbox tư vấn sách với Gemini AI
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

        // Lấy danh sách thể loại và một số sách mới nhất để AI có dữ liệu gợi ý
        $categories = [];
        try {
            $catResults = $this->table->getAdapter()->query("SELECT name FROM book_categories LIMIT 20")->execute();
            foreach ($catResults as $cat) $categories[] = $cat['name'];
        } catch (\Throwable $e) {}

        $recentBooks = [];
        try {
            $bookResults = $this->table->getAdapter()->query("SELECT title, author, category FROM books WHERE status = 'available' ORDER BY created_at DESC LIMIT 10")->execute();
            foreach ($bookResults as $b) $recentBooks[] = "{$b['title']} (Tác giả: {$b['author']}, Thể loại: {$b['category']})";
        } catch (\Throwable $e) {}

        $systemPrompt = "Bạn là thủ thư ảo của Thư viện HDPE. Bạn có nhiệm vụ tư vấn sách và trả lời các thắc mắc về thư viện một cách chuyên nghiệp, thân thiện.
        Các thể loại sách hiện có: " . implode(', ', $categories) . ".
        Một số sách mới và đang có sẵn: " . implode('; ', $recentBooks) . ".
        Nếu người dùng hỏi về sách, hãy dựa trên dữ liệu này để gợi ý. Nếu không có, hãy khuyên họ tìm theo các thể loại trên.
        Câu trả lời nên ngắn gọn, súc tích và bằng tiếng Việt.";

        $responseMsg = $this->geminiService->generateResponse($message, $systemPrompt);
        
        // Extract suggestions from AI response or manual search if AI mentions a book
        $suggestions = [];
        $lowerResponse = mb_strtolower($responseMsg, 'UTF-8');
        
        // Tìm kiếm nhanh trong DB các sách mà AI gợi ý (để hiển thị link/nút)
        foreach ($recentBooks as $bStr) {
            $parts = explode(' (', $bStr);
            $title = $parts[0];
            if (mb_strpos($lowerResponse, mb_strtolower($title, 'UTF-8')) !== false) {
                $bookData = $this->table->searchAvailable($title, true, 1);
                if ($bookData) {
                    $suggestions[] = ['title' => $bookData[0]['title'], 'author' => $bookData[0]['author'], 'id' => $bookData[0]['id']];
                }
            }
        }

        // Save to DB
        try {
            $this->chatLogTable->insertLog($userId, $message, $responseMsg);
        } catch (\Exception $e) {}

        return $this->jsonResponse([
            'reply' => $responseMsg,
            'suggestions' => array_slice($suggestions, 0, 3)
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
