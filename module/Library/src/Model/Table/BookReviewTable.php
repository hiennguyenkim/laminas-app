<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class BookReviewTable
{
    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    private function getAdapter(): AdapterInterface
    {
        return $this->tableGateway->getAdapter();
    }

    public function hasBorrowedAny(int $userId, int $bookId): bool
    {
        $sql = "SELECT COUNT(*) as cnt
                FROM borrow_records
                WHERE user_id = ? AND book_id = ?
                AND status IN ('borrowed', 'returned', 'overdue')";
        $row = $this->getAdapter()->query($sql)->execute([$userId, $bookId])->current();
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    public function hasBorrowedAndReturned(int $userId, int $bookId): bool
    {
        $sql = "SELECT COUNT(*) as cnt
                FROM borrow_records
                WHERE user_id = ? AND book_id = ?
                AND status = 'returned'";
        $row = $this->getAdapter()->query($sql)->execute([$userId, $bookId])->current();
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    public function hasReviewed(int $userId, int $bookId): bool
    {
        $sql = "SELECT COUNT(*) as cnt
                FROM book_reviews
                WHERE user_id = ? AND book_id = ?";
        $row = $this->getAdapter()->query($sql)->execute([$userId, $bookId])->current();
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    public function addReview(int $bookId, int $userId, int $rating, string $comment): void
    {
        $sql = 'INSERT INTO book_reviews (book_id, user_id, rating, comment, created_at) VALUES (?, ?, ?, ?, NOW())';
        $this->getAdapter()->query($sql)->execute([$bookId, $userId, $rating, $comment]);
    }


    public function fetchReviewsForBook(int $bookId): array
    {
        $sql = 'SELECT r.*, u.full_name, u.role FROM book_reviews r 
                JOIN users u ON r.user_id = u.user_id 
                WHERE r.book_id = ? 
                ORDER BY r.created_at DESC';
        $results = $this->getAdapter()->query($sql)->execute([$bookId]);
        return iterator_to_array($results);
    }
}
