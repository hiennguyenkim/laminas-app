<?php

declare(strict_types=1);

namespace Library\Controller\Api;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Library\Model\Entity\BorrowRecord;
use Library\Model\Table\BorrowTable;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
class BorrowApiController extends AbstractRestfulController
{
    private BorrowTable $table;
    public function __construct(BorrowTable $table)
    {
        $this->table = $table;
    }

    // GET /api/borrows
    public function getList()
    {
        $records = $this->table->fetchAllWithDetails();
        $data = [];
        foreach ($records as $record) {
            if (! $record instanceof BorrowRecord) {
                continue;
            }

            $data[] = $record->getArrayCopy();
        }
        return $this->jsonResponse($data);
    }
    // GET /api/borrows/:id
    public function get(mixed $id)
    {

        try {
            $record = $this->table->getRecord((int) $id);
            return $this->jsonResponse($record->getArrayCopy());
        } catch (\Exception) {
            return $this->jsonResponse(['error' => 'Borrow record not found'], 404);
        }
    }

    // POST /api/borrows
    public function create(mixed $data)
    {
        $data = $this->resolvePayload($data);
        if (
            ! isset($data['book_id'])
            || ! isset($data['user_id'])
            || ! isset($data['borrow_date'])
            || ! isset($data['return_date'])
        ) {
            return $this->jsonResponse(['error' => 'Missing book_id, user_id, borrow_date, or return_date'], 400);
        }

        $borrowDate = (string) $data['borrow_date'];
        $returnDate = (string) $data['return_date'];

        try {
            $this->table->borrow(
                (int) $data['book_id'],
                (int) $data['user_id'],
                $borrowDate,
                $returnDate
            );
            return $this->jsonResponse(['status' => 'success', 'message' => 'Book borrowed'], 201);
        } catch (\Exception $e) {
            return $this->jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    // PUT /api/borrows/:id (Usually for returning a book)
    public function update(mixed $id, mixed $data)
    {
        $data = $this->resolvePayload($data);
        if (isset($data['action']) && $data['action'] === 'return') {
            try {
                $this->table->returnBook((int) $id);
                return $this->jsonResponse(['status' => 'success', 'message' => 'Book returned']);
            } catch (\Exception $e) {
                return $this->jsonResponse(['error' => $e->getMessage()], 500);
            }
        }

        return $this->jsonResponse(['error' => 'Unsupported action. Use action=return to return a book.'], 400);
    }

    // DELETE /api/borrows/:id
    public function delete(mixed $id)
    {
        return $this->jsonResponse(['error' => 'Delete operation not implemented for borrow records'], 501);
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment
     */
    private function resolvePayload(mixed $data): array
    {
        $content = $this->getRequest()->getContent();
        $payload = is_string($content) ? json_decode($content, true) : null;
        if (is_array($payload)) {
            return $this->normalizePayload($payload);
        }

        return is_array($data) ? $this->normalizePayload($data) : [];
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
