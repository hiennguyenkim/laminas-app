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

    // Phase 3.2
    public string $description   = '';
    public string $publisher     = '';
    public string $publishedYear = '';
    public string $importDate    = '';
    public string $coverImageUrl = '';

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

        // Phase 3.2
        $this->description   = (string) ($data['description'] ?? '');
        $this->publisher     = (string) ($data['publisher'] ?? '');
        $this->publishedYear = (string) ($data['published_year'] ?? '');
        $this->importDate    = (string) ($data['import_date'] ?? '');
        $this->coverImageUrl = (string) ($data['cover_image_url'] ?? '');
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
            'description'    => $this->description,
            'publisher'      => $this->publisher,
            'published_year' => $this->publishedYear,
            'import_date'    => $this->importDate,
            'cover_image_url'=> $this->coverImageUrl,
        ];
    }
}
