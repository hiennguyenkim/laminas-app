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
    public function fetchAllWithDetails(array $filters = [], ?int $userId = null, int $limit = 0, int $offset = 0): array
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
                'renew_count',
                'is_renew_pending',
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
                ['book_title' => 'title', 'book_isbn' => 'isbn', 'cover_image_url']
            )
            ->join(
                'users',
                'borrow_records.user_id = users.user_id',
                ['full_name', 'username', 'avatar_url']
            );

        $sort = $filters['sort'] ?? null;
        $direction = strtoupper($filters['direction'] ?? 'DESC');
        if (!in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        $allowedSorts = [
            'member' => 'users.full_name',
            'book' => 'books.title',
            'borrow_date' => 'borrow_records.borrow_date',
            'return_date' => 'borrow_records.return_date',
            'status' => 'borrow_records.status',
        ];

        if ($sort === 'status') {
            $select->order(new Expression(
                "CASE 
                    WHEN borrow_records.status = 'pending' THEN 1
                    WHEN borrow_records.status = 'borrowed' AND borrow_records.return_date < CURDATE() THEN 2
                    WHEN borrow_records.status = 'borrowed' THEN 3
                    WHEN borrow_records.status = 'returned' THEN 4
                    ELSE 5
                 END " . $direction . ", borrow_records.created_at DESC"
            ));
        } elseif ($sort !== null && array_key_exists($sort, $allowedSorts)) {
            $select->order($allowedSorts[$sort] . ' ' . $direction);
        } else {
            $select->order(new Expression(
                "CASE 
                    WHEN borrow_records.status = 'pending' THEN 1
                    WHEN borrow_records.status = 'borrowed' AND borrow_records.return_date < CURDATE() THEN 2
                    WHEN borrow_records.status = 'borrowed' THEN 3
                    WHEN borrow_records.status = 'returned' THEN 4
                    ELSE 5
                 END ASC, borrow_records.created_at DESC"
            ));
        }

        $this->applyFilters($select, $filters, $userId);

        if ($limit > 0) {
            $select->limit($limit);
        }
        if ($offset > 0) {
            $select->offset($offset);
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

    public function countFiltered(array $filters = [], ?int $userId = null): int
    {
        $sql      = $this->tableGateway->getSql();
        $select   = $sql->select()
            ->columns(['c' => new Expression('COUNT(*)')])
            ->join(
                'books',
                'borrow_records.book_id = books.book_id',
                []
            )
            ->join(
                'users',
                'borrow_records.user_id = users.user_id',
                []
            );

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return (int) ($result->current()['c'] ?? 0);
    }

    private function applyFilters(Select $select, array $filters, ?int $userId = null): void
    {
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
            } elseif ($status === 'renew_pending') {
                $select->where(['borrow_records.is_renew_pending' => 1]);
            } else {
                $select->where(['borrow_records.status' => $status]);
            }
        }

        $filterUserId = trim((string) ($filters['user_id'] ?? ''));
        if ($userId === null && $filterUserId !== '') {
            $select->where(['borrow_records.user_id' => (int) $filterUserId]);
        }

        $filterYear = $filters['year'] ?? null;
        $filterPeriod = $filters['period'] ?? null;
        $filterWeek = $filters['week'] ?? null;

        if ($filterYear !== null && $filterYear !== '' && $filterYear !== 'all') {
            $select->where(new \Laminas\Db\Sql\Predicate\Expression("YEAR(borrow_records.borrow_date) = ?", (int)$filterYear));
        }

        if ($filterPeriod !== null && $filterPeriod !== '' && $filterPeriod !== 'year') {
            if (strpos($filterPeriod, 'q') === 0) {
                $qtr = (int)substr($filterPeriod, 1);
                $select->where(new \Laminas\Db\Sql\Predicate\Expression("QUARTER(borrow_records.borrow_date) = ?", $qtr));
            } elseif (strpos($filterPeriod, 'm') === 0) {
                $month = (int)substr($filterPeriod, 1);
                $select->where(new \Laminas\Db\Sql\Predicate\Expression("MONTH(borrow_records.borrow_date) = ?", $month));
            }
        }

        if ($filterWeek !== null && $filterWeek !== '' && $filterWeek !== 'all') {
            $select->where(new \Laminas\Db\Sql\Predicate\Expression("WEEK(borrow_records.borrow_date, 1) = ?", (int)$filterWeek));
        }
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

    public function requestBorrow(int $bookId, int $userId, string $borrowDate, string $returnDate): void
    {
        $this->cleanupExpiredReturnedHistory();

        $this->tableGateway->insert([
            'book_id'     => $bookId,
            'user_id'     => $userId,
            'borrow_date' => $borrowDate,
            'return_date' => $returnDate,
            'status'      => 'pending',
            'returned_at' => null,
        ]);
    }

    public function approve(int $id, ?string $borrowDate = null, ?string $returnDate = null): void
    {
        $this->cleanupExpiredReturnedHistory();

        $updateData = [
            'status' => 'borrowed',
        ];
        if ($borrowDate !== null) {
            $updateData['borrow_date'] = $borrowDate;
        }
        if ($returnDate !== null) {
            $updateData['return_date'] = $returnDate;
        }

        $this->tableGateway->update($updateData, [self::PK => $id]);
    }

    public function reject(int $id): void
    {
        $this->cleanupExpiredReturnedHistory();
        $this->tableGateway->delete([self::PK => $id]);
    }

    public function requestRenew(int $id): void
    {
        $this->tableGateway->update(['is_renew_pending' => 1], [self::PK => $id]);
    }

    public function approveRenew(int $id, string $newReturnDate): void
    {
        $sql = "UPDATE borrow_records SET return_date = ?, renew_count = renew_count + 1, is_renew_pending = 0 WHERE borrow_id = ?";
        $this->tableGateway->getAdapter()->query($sql)->execute([$newReturnDate, $id]);
    }

    public function rejectRenew(int $id): void
    {
        $this->tableGateway->update(['is_renew_pending' => 0], [self::PK => $id]);
    }

    public function renewBook(int $id, string $newReturnDate): void
    {
        $sql = "UPDATE borrow_records SET return_date = ?, renew_count = renew_count + 1, is_renew_pending = 0 WHERE borrow_id = ?";
        $this->tableGateway->getAdapter()->query($sql)->execute([$newReturnDate, $id]);
    }

    public function returnBook(int $id): void
    {
        $this->cleanupExpiredReturnedHistory();

        $this->tableGateway->update([
            'status'      => 'returned',
            'returned_at' => new Expression('NOW()'),
        ], [self::PK => $id]);
    }

    public function countBorrowed(array $filters = [], ?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'c' => new Expression(
                "SUM(CASE
                    WHEN borrow_records.status IN ('borrowed', 'overdue')
                      OR (borrow_records.status = 'borrowed' AND borrow_records.return_date < CURDATE())
                        THEN 1
                    ELSE 0
                 END)"
            ),
        ]);

        if (!empty($filters['search']) || !empty($filters['category'])) {
            $select->join('books', 'borrow_records.book_id = books.book_id', [])
                   ->join('users', 'borrow_records.user_id = users.user_id', []);
        }

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function countOverdue(array $filters = [], ?int $userId = null): int
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

        if (!empty($filters['search']) || !empty($filters['category'])) {
            $select->join('books', 'borrow_records.book_id = books.book_id', [])
                   ->join('users', 'borrow_records.user_id = users.user_id', []);
        }

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function getTopReaders(int $limit = 5, string $period = 'month'): array
    {
        $intervalExpr = 'INTERVAL 30 DAY';
        switch ($period) {
            case 'week':
                $intervalExpr = 'INTERVAL 7 DAY';
                break;
            case 'quarter':
                $intervalExpr = 'INTERVAL 90 DAY';
                break;
            case 'year':
                $intervalExpr = 'INTERVAL 365 DAY';
                break;
            case 'month':
            default:
                $intervalExpr = 'INTERVAL 30 DAY';
                break;
        }

        $sql = "SELECT COUNT(*) AS borrow_count, u.user_id, u.full_name, u.username, u.avatar_url 
                FROM borrow_records 
                INNER JOIN users u ON borrow_records.user_id = u.user_id 
                WHERE u.role = 'student' 
                  AND borrow_records.borrow_date >= DATE_SUB(CURDATE(), {$intervalExpr}) 
                GROUP BY u.user_id, u.full_name, u.username, u.avatar_url 
                ORDER BY borrow_count DESC 
                LIMIT ?";
        $result = $this->tableGateway->getAdapter()->query($sql)->execute([$limit]);
        return iterator_to_array($result);
    }

    public function getCurrentlyBorrowedCategoryStats(): array
    {
        $sql = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns(['cnt' => new Expression('COUNT(*)')])
            ->join('books', 'borrow_records.book_id = books.book_id', ['category'])
            ->where(function ($where) {
                $where->in('borrow_records.status', ['borrowed', 'overdue']);
            })
            ->group('books.category')
            ->order(new Expression('COUNT(*) DESC'));

        $result = $sql->prepareStatementForSqlObject($select)->execute();
        $stats = [];
        foreach ($result as $row) {
            if (is_array($row) && !empty($row['category'])) {
                $stats[(string)$row['category']] = (int)$row['cnt'];
            }
        }
        return $stats;
    }

    public function countOverdueOccurrencesForUser(int $userId): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'c' => new Expression(
                "SUM(CASE
                    WHEN status = 'overdue'
                      OR (status = 'borrowed' AND return_date < CURDATE())
                      OR (status = 'returned' AND returned_at IS NOT NULL AND DATE(returned_at) > return_date)
                        THEN 1
                    ELSE 0
                 END)"
            ),
        ])->where(['user_id' => $userId]);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function countReturnedLateForUser(int $userId): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'c' => new Expression(
                "SUM(CASE WHEN status = 'returned' AND returned_at IS NOT NULL AND DATE(returned_at) > return_date THEN 1 ELSE 0 END)"
            ),
        ])->where(['user_id' => $userId]);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function getOnTimeRateForUser(int $userId): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'evaluated' => new Expression(
                "SUM(CASE 
                    WHEN status = 'returned' 
                      OR status = 'overdue' 
                      OR (status = 'borrowed' AND return_date < CURDATE()) 
                        THEN 1 
                    ELSE 0 
                 END)"
            ),
            'late' => new Expression(
                "SUM(CASE 
                    WHEN status = 'overdue' 
                      OR (status = 'borrowed' AND return_date < CURDATE()) 
                      OR (status = 'returned' AND returned_at IS NOT NULL AND DATE(returned_at) > return_date) 
                        THEN 1 
                    ELSE 0 
                 END)"
            ),
        ])->where(['user_id' => $userId]);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        $row    = $result->current();
        if (!$row) {
            return 100;
        }
        $evaluated = (int) $row['evaluated'];
        $late      = (int) $row['late'];
        if ($evaluated === 0) {
            return 100;
        }
        return (int) ((($evaluated - $late) / $evaluated) * 100);
    }

    public function countReturned(array $filters = [], ?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression("SUM(CASE WHEN borrow_records.status = 'returned' THEN 1 ELSE 0 END)"),
            ]);

        if (!empty($filters['search']) || !empty($filters['category'])) {
            $select->join('books', 'borrow_records.book_id = books.book_id', [])
                   ->join('users', 'borrow_records.user_id = users.user_id', []);
        }

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function countPending(array $filters = [], ?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression("SUM(CASE WHEN borrow_records.status = 'pending' THEN 1 ELSE 0 END)"),
            ]);

        if (!empty($filters['search']) || !empty($filters['category'])) {
            $select->join('books', 'borrow_records.book_id = books.book_id', [])
                   ->join('users', 'borrow_records.user_id = users.user_id', []);
        }

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function countRenewPending(array $filters = [], ?int $userId = null): int
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression("SUM(CASE WHEN borrow_records.is_renew_pending = 1 THEN 1 ELSE 0 END)"),
            ]);

        if (!empty($filters['search']) || !empty($filters['category'])) {
            $select->join('books', 'borrow_records.book_id = books.book_id', [])
                   ->join('users', 'borrow_records.user_id = users.user_id', []);
        }

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return (int) ($result->current()['c'] ?? 0);
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
                        WHEN status IN ('borrowed', 'overdue', 'pending')
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

    public function hasActiveTransactionsForUser(int $userId): bool
    {
        $this->cleanupExpiredReturnedHistory();

        $rowset = $this->tableGateway->select(function (Select $select) use ($userId) {
            $select->columns([self::PK]);
            $select->where(['user_id' => $userId]);
            $select->where->in('status', ['pending', 'borrowed', 'overdue']);
            $select->limit(1);
        });

        return $rowset->count() > 0;
    }

    public function getSummary(?int $userId = null, array $filters = []): array
    {
        $this->cleanupExpiredReturnedHistory();

        // Status filter should be ignored for summary cards 
        // BUT year/period/search should be respected.
        $summaryFilters = $filters;
        unset($summaryFilters['status']);

        return [
            'borrowed'  => $this->countBorrowed($summaryFilters, $userId),
            'overdue'   => $this->countOverdue($summaryFilters, $userId),
            'returned'  => $this->countReturned($summaryFilters, $userId),
            'pending'   => $this->countPending($summaryFilters, $userId),
            'renew_pending' => $this->countRenewPending($summaryFilters, $userId),
            'due_soon'  => $userId !== null ? $this->countDueSoon($userId) : 0,
            'total'     => $this->countTotalBorrowedHistory($summaryFilters, $userId),
        ];
    }

    public function countTotalBorrowedHistory(array $filters = [], ?int $userId = null): int
    {
        $this->cleanupExpiredReturnedHistory();

        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'c' => new Expression("SUM(CASE WHEN borrow_records.status IN ('borrowed', 'returned', 'overdue') THEN 1 ELSE 0 END)"),
            ])
            ->join('books', 'borrow_records.book_id = books.book_id', [])
            ->join('users', 'borrow_records.user_id = users.user_id', []);

        $this->applyFilters($select, $filters, $userId);

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    /**
     * Get monthly borrow/return counts for the current year (12 months).
     * Returns ['borrow' => [0..11], 'return' => [0..11]]
     */
    public function getMonthlyStats(int $year, ?int $userId = null): array
    {
        $borrowCounts = array_fill(0, 12, 0);
        $returnCounts = array_fill(0, 12, 0);

        $sql = $this->tableGateway->getSql();

        // Borrow counts per month (excluding pending ones)
        $borrowSelect = $sql->select()
            ->columns([
                'month' => new Expression('MONTH(borrow_date)'),
                'cnt'   => new Expression('COUNT(*)'),
            ])
            ->where(new \Laminas\Db\Sql\Predicate\Expression("YEAR(borrow_date) = ?", $year))
            ->where(function (\Laminas\Db\Sql\Where $where) {
                $where->in('status', ['borrowed', 'returned', 'overdue']);
            })
            ->group(new Expression('MONTH(borrow_date)'));

        if ($userId !== null) {
            $borrowSelect->where(['user_id' => $userId]);
        }

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
            ->where(new \Laminas\Db\Sql\Predicate\Expression("YEAR(returned_at) = ?", $year))
            ->group(new Expression('MONTH(returned_at)'));

        if ($userId !== null) {
            $returnSelect->where(['user_id' => $userId]);
        }

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

    /**
     * Get monthly borrow count for each category in the current year.
     * Returns an associative array of ['CategoryName' => [month0, ..., month11]]
     */
    public function getCategoryMonthlyStats(int $year): array
    {
        $sql = $this->tableGateway->getSql();
        $select = $sql->select()
            ->columns([
                'borrow_month' => new Expression('MONTH(borrow_records.borrow_date)'),
                'cnt'          => new Expression('COUNT(*)'),
            ])
            ->join(
                'books',
                'borrow_records.book_id = books.book_id',
                ['category']
            )
            ->where(new \Laminas\Db\Sql\Predicate\Expression("YEAR(borrow_records.borrow_date) = ?", $year))
            ->where(function (\Laminas\Db\Sql\Where $where) {
                $where->in('borrow_records.status', ['borrowed', 'returned', 'overdue']);
            })
            ->group([new Expression('MONTH(borrow_records.borrow_date)'), 'books.category'])
            ->order(['borrow_month ASC', 'cnt DESC']);

        $results = $sql->prepareStatementForSqlObject($select)->execute();
        
        $data = [];
        foreach ($results as $row) {
            if (is_array($row)) {
                $category = $row['category'] ?: 'Khác';
                $month = (int)$row['borrow_month'];
                $count = (int)$row['cnt'];
                
                if (!isset($data[$category])) {
                    $data[$category] = array_fill(0, 12, 0);
                }
                $data[$category][$month - 1] = $count;
            }
        }
        return $data;
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

