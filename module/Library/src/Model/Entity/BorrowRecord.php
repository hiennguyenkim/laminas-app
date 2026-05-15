<?php

declare(strict_types=1);

namespace Library\Model\Entity;

class BorrowRecord
{
    public int $id         = 0;
    public int $bookId     = 0;
    public int $userId     = 0;
    public string $borrowDate = '';
    public string $returnDate = '';
    public string $status     = 'borrowed';
    public string $returnedAt = '';
    public string $createdAt  = '';

    // Joined fields
    public string $bookTitle  = '';
    public string $bookIsbn   = '';
    public string $userFullName = '';
    public string $username   = '';

    public function exchangeArray(array $data): void
    {
        $this->id           = (int)    ($data['id'] ?? $data['borrow_id'] ?? 0);
        $this->bookId       = (int)    ($data['book_id'] ?? 0);
        $this->userId       = (int)    ($data['user_id'] ?? 0);
        $this->borrowDate   = (string) ($data['borrow_date'] ?? '');
        $this->returnDate   = (string) ($data['return_date'] ?? '');
        $this->status       = (string) ($data['status'] ?? 'borrowed');
        $this->returnedAt   = (string) ($data['returned_at'] ?? '');
        $this->createdAt    = (string) ($data['created_at'] ?? '');
        $this->bookTitle    = (string) ($data['book_title'] ?? '');
        $this->bookIsbn     = (string) ($data['book_isbn'] ?? '');
        $this->userFullName = (string) ($data['full_name'] ?? '');
        $this->username     = (string) ($data['username'] ?? '');
    }

    public function isOverdue(): bool
    {
        if ($this->status !== 'borrowed' || $this->returnDate === '') {
            return false;
        }
        return $this->returnDate < date('Y-m-d');
    }

    /**
     * @return array<string, int|string>
     */
    public function getArrayCopy(): array
    {
        return [
            'id'          => $this->id,
            'book_id'     => $this->bookId,
            'user_id'     => $this->userId,
            'borrow_date' => $this->borrowDate,
            'return_date' => $this->returnDate,
            'status'      => $this->status,
            'returned_at' => $this->returnedAt,
            'created_at'  => $this->createdAt,
            'book_title'  => $this->bookTitle,
            'book_isbn'   => $this->bookIsbn,
            'full_name'   => $this->userFullName,
            'username'    => $this->username,
        ];
    }
}
