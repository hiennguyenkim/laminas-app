<?php

declare(strict_types=1);

namespace Library\Model\Entity;

/**
 * @psalm-suppress PossiblyUnusedProperty
 */
class Book
{
    public int $id       = 0;
    public string $title    = '';
    public string $author   = '';
    public string $isbn     = '';
    public string $category = '';
    public int $quantity = 1;
    public string $status   = 'available';
    public string $createdAt = '';
    public string $lastReturnedAt = '';

    public function exchangeArray(array $data): void
    {
        $this->id        = (int)    ($data['id'] ?? $data['book_id'] ?? 0);
        $this->title     = (string) ($data['title'] ?? '');
        $this->author    = (string) ($data['author'] ?? '');
        $this->isbn      = (string) ($data['isbn'] ?? '');
        $this->category  = (string) ($data['category'] ?? '');
        $this->quantity  = (int)    ($data['quantity'] ?? 1);
        $this->status    = (string) ($data['status'] ?? 'available');
        $this->createdAt = (string) ($data['created_at'] ?? '');
        $this->lastReturnedAt = (string) ($data['last_returned_at'] ?? '');
    }

    public function getArrayCopy(): array
    {
        return [
            'id'       => $this->id,
            'title'    => $this->title,
            'author'   => $this->author,
            'isbn'     => $this->isbn,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'status'   => $this->status,
        ];
    }
}
