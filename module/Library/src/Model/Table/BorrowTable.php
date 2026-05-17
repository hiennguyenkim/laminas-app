<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Library\Model\Entity\BorrowRecord;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;

class BorrowTable
{
    private const PK = 'borrow_id';
    private const RETURNED_HISTORY_RETENTION_DAYS = 3650;

    private TableGateway $tableGateway;
    private bool $expiredReturnedHistoryCleaned = false;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    /**
     * Fetch all borrow records joined with book title and user info.
     *
     * @return array<int, BorrowRecord>
     */
    public function fetchAllWithDetails(array $filters = [], ?int $userId = null, int $limit = 0): array
    {
        $this->cleanupExpiredReturnedHistory();

        $sql      = $this->tableGateway->getSql();
        $select   = $sql->select()
            ->columns([
                'id' => self::PK,
                'book_id',
                'user_id',
                'borrow_date',
                'return_date',
                'returned_at',
                'created_at',
                'status' => new Expression(
                    "CASE
                        WHEN borrow_records.status = 'borrowed' AND borrow_records.return_date < CURDATE()
                            THEN 'overdue'
                        ELSE borrow_records.status
                     END"
                ),
            ])
            ->join(
                'books',
                'borrow_records.book_id = books.book_id',
                ['book_title' => 'title', 'book_isbn' => 'isbn']
            )
            ->join(
                'users',
                'borrow_records.user_id = users.user_id',
                ['full_name', 'username']
            )
            ->order('borrow_records.created_at DESC');

        if ($userId !== null) {
            $select->where(['borrow_records.user_id' => $userId]);
        }

        $searchValue = trim((string) ($filters['search'] ?? ''));
        if ($searchValue !== '') {
            $search = '%' . $searchValue . '%';
            $select->where(function (Where $where) use ($search): void {
                $where->nest()
                    ->like('books.title', $search)
                    ->or
                    ->like('books.author', $search)
                    ->or
                    ->like('books.isbn', $search)
                    ->or
                    ->like('users.full_name', $search)
                    ->or
                    ->like('users.username', $search)
                    ->unnest();
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if ($status === 'overdue') {
                $select->where(
                    "(borrow_records.status = 'overdue' "
                    . "OR (borrow_records.status = 'borrowed' "
                    . "AND borrow_records.return_date < CURDATE()))"
                );
            } elseif ($status === 'borrowed') {
                $select->where(
                    "(borrow_records.status = 'borrowed' AND borrow_records.return_date >= CURDATE())"
                );
            } else {
                $select->where(['borrow_records.status' => $status]);
            }
        }

        $filterUserId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId === null && $filterUserId !== '') {
            $select->where(['borrow_records.user_id' => (int) $filterUserId]);
        }

        if ($limit > 0) {
            $select->limit($limit);
        }

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        $records = [];
        foreach ($result as $row) {
            if (! is_array($row)) {
                continue;
            }

            $record = new BorrowRecord();
            $record->exchangeArray($row);
            $records[] = $record;
        }
        return $records;
    }

    public function getRecord(int $id): BorrowRecord
    {
        $this->cleanupExpiredReturnedHistory();

        $rowset = $this->tableGateway->select([self::PK => $id]);
        $row = $this->firstRecordFromRowset($rowset);

        if (! $row instanceof BorrowRecord) {
            throw new \RuntimeException(sprintf('Không tìm thấy phiếu mượn ID %d.', $id));
        }
        return $row;
    }

    public function borrow(int $bookId, int $userId, string $borrowDate, string $returnDate): void
    {
        $this->cleanupExpiredReturnedHistory();

        $this->tableGateway->insert([
            'book_id'     => $bookId,
            'user_id'     => $userId,
            'borrow_date' => $borrowDate,
            'return_date' => $returnDate,
            'status'      => 'borrowed',
            'returned_at' => null,
        ]);
    }

    public function returnBook(int $id): void
    {
        $this->cleanupExpiredReturnedHistory();

        $this->tableGateway->update([
            'status'      => 'returned',
            'returned_at' => new Expression('NOW()'),
        ], [self::PK => $id]);
    }

    public function countBorrowed(?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'c' => new Expression(
                "SUM(CASE
                    WHEN borrow_records.status = 'borrowed'
                     AND borrow_records.return_date >= CURDATE()
                        THEN 1
                    ELSE 0
                 END)"
            ),
        ]);

        if ($userId !== null) {
            $select->where(['user_id' => $userId]);
        }

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function countOverdue(?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'c' => new Expression(
                "SUM(CASE
                    WHEN borrow_records.status = 'overdue'
                      OR (borrow_records.status = 'borrowed' AND borrow_records.return_date < CURDATE())
                        THEN 1
                    ELSE 0
                 END)"
            ),
        ]);

        if ($userId !== null) {
            $select->where(['user_id' => $userId]);
        }

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function countReturned(?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression("SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END)"),
            ]);

        if ($userId !== null) {
            $select->where(['user_id' => $userId]);
        }

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function countDueSoon(int $userId, int $days = 7): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression(
                    sprintf(
                        "SUM(CASE
                            WHEN status = 'borrowed'
                             AND return_date >= CURDATE()
                             AND return_date <= DATE_ADD(CURDATE(), INTERVAL %d DAY)
                                THEN 1
                            ELSE 0
                         END)",
                        $days
                    )
                ),
            ])
            ->where(['user_id' => $userId]);
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function countActiveLoansForUser(int $userId): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression(
                    "SUM(CASE
                        WHEN status IN ('borrowed', 'overdue')
                            THEN 1
                        ELSE 0
                     END)"
                ),
            ])
            ->where(['user_id' => $userId]);
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function hasOverdueLoans(int $userId): bool
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([self::PK])
            ->where(['user_id' => $userId])
            ->where("(status = 'overdue' OR (status = 'borrowed' AND return_date < CURDATE()))")
            ->limit(1);
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return (bool) $result->current();
    }

    public function hasActiveLoan(int $userId, int $bookId): bool
    {
        $this->cleanupExpiredReturnedHistory();

        $rowset = $this->tableGateway->select(function (Select $select) use ($userId, $bookId) {
            $select->columns([self::PK]);
            $select->where([
                'user_id' => $userId,
                'book_id' => $bookId,
            ]);
            $select->where->in('status', ['borrowed', 'overdue']);
            $select->limit(1);
        });
        return $rowset->count() > 0;
    }

    public function hasActiveBorrowForBook(int $bookId): bool
    {
        $this->cleanupExpiredReturnedHistory();

        $rowset = $this->tableGateway->select(function (Select $select) use ($bookId) {
            $select->columns([self::PK]);
            $select->where(['book_id' => $bookId]);
            $select->where->in('status', ['borrowed', 'overdue']);
            $select->limit(1);
        });

        return $rowset->count() > 0;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function hasBorrowHistoryForBook(int $bookId): bool
    {
        $this->cleanupExpiredReturnedHistory();

        $rowset = $this->tableGateway->select(function (Select $select) use ($bookId) {
            $select->columns([self::PK]);
            $select->where(['book_id' => $bookId]);
            $select->limit(1);
        });

        return $rowset->count() > 0;
    }

    public function hasBorrowHistoryForUser(int $userId): bool
    {
        $this->cleanupExpiredReturnedHistory();

        $rowset = $this->tableGateway->select(function (Select $select) use ($userId) {
            $select->columns([self::PK]);
            $select->where(['user_id' => $userId]);
            $select->limit(1);
        });

        return $rowset->count() > 0;
    }

    public function getSummary(?int $userId = null): array
    {
        $this->cleanupExpiredReturnedHistory();

        return [
            'borrowed'  => $this->countBorrowed($userId),
            'overdue'   => $this->countOverdue($userId),
            'returned'  => $this->countReturned($userId),
            'due_soon'  => $userId !== null ? $this->countDueSoon($userId) : 0,
        ];
    }

    /**
     * Get monthly borrow/return counts for the current year (12 months).
     * Returns ['borrow' => [0..11], 'return' => [0..11]]
     */
    public function getMonthlyStats(int $year): array
    {
        $borrowCounts = array_fill(0, 12, 0);
        $returnCounts = array_fill(0, 12, 0);

        $sql = $this->tableGateway->getSql();

        // Borrow counts per month
        $borrowSelect = $sql->select()
            ->columns([
                'month' => new Expression('MONTH(borrow_date)'),
                'cnt'   => new Expression('COUNT(*)'),
            ])
            ->where(new Expression("YEAR(borrow_date) = $year"))
            ->group(new Expression('MONTH(borrow_date)'));
        $borrowResult = $sql->prepareStatementForSqlObject($borrowSelect)->execute();
        foreach ($borrowResult as $row) {
            if (is_array($row)) {
                $borrowCounts[(int)$row['month'] - 1] = (int)$row['cnt'];
            }
        }

        // Return counts per month
        $returnSelect = $sql->select()
            ->columns([
                'month' => new Expression('MONTH(returned_at)'),
                'cnt'   => new Expression('COUNT(*)'),
            ])
            ->where(["status" => 'returned'])
            ->where('returned_at IS NOT NULL')
            ->where(new Expression("YEAR(returned_at) = $year"))
            ->group(new Expression('MONTH(returned_at)'));
        $returnResult = $sql->prepareStatementForSqlObject($returnSelect)->execute();
        foreach ($returnResult as $row) {
            if (is_array($row)) {
                $returnCounts[(int)$row['month'] - 1] = (int)$row['cnt'];
            }
        }

        return [
            'borrow' => $borrowCounts,
            'return' => $returnCounts,
        ];
    }

    private function cleanupExpiredReturnedHistory(): void
    {
        if ($this->expiredReturnedHistoryCleaned) {
            return;
        }

        $sql = $this->tableGateway->getSql();
        $delete = $sql->delete();
        $delete->where(['status' => 'returned']);
        $delete->where('returned_at IS NOT NULL');
        $delete->where(sprintf(
            'returned_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
            self::RETURNED_HISTORY_RETENTION_DAYS,
        ));

        $stmt = $sql->prepareStatementForSqlObject($delete);
        $stmt->execute();

        $this->expiredReturnedHistoryCleaned = true;
    }

    private function extractCount(mixed $current): int
    {
        if (! is_array($current)) {
            return 0;
        }

        return (int) ($current['c'] ?? 0);
    }

    /**
     * @psalm-suppress MixedAssignment
     */
    private function firstRecordFromRowset(iterable $rowset): ?BorrowRecord
    {
        foreach ($rowset as $row) {
            return $row instanceof BorrowRecord ? $row : null;
        }

        return null;
    }
}
