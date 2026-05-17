<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Library\Model\Entity\Book;
use Laminas\Db\Sql\Expression;
use Laminas\Db\Sql\Select;
use Laminas\Db\Sql\Where;
use Laminas\Db\TableGateway\TableGateway;
use RuntimeException;

class BookTable
{
    private const PK = 'book_id';

    private TableGateway $tableGateway;

    public function __construct(TableGateway $tableGateway)
    {
        $this->tableGateway = $tableGateway;
    }

    public function fetchAll(array $filters = []): \Laminas\Db\ResultSet\ResultSetInterface
    {
        return $this->tableGateway->select(function (Select $select) use ($filters): void {
            $select->columns([
                'book_id',
                'title',
                'author',
                'isbn',
                'category',
                'quantity',
                'status',
                'created_at',
                'last_returned_at' => new Expression(
                    '(SELECT MAX(br.returned_at) FROM borrow_records br '
                    . 'WHERE br.book_id = books.book_id '
                    . 'AND br.returned_at IS NOT NULL)'
                ),
            ]);
            $this->applyFilters($select, $filters);
            $select->order(self::PK . ' ASC');
        });
    }

    public function fetchPage(array $filters, int $page, int $perPage): \Laminas\Db\ResultSet\ResultSetInterface
    {
        $safePage = max(1, $page);
        $safePerPage = max(1, $perPage);
        $offset = ($safePage - 1) * $safePerPage;

        return $this->tableGateway->select(function (Select $select) use ($filters, $safePerPage, $offset): void {
            $select->columns([
                'book_id',
                'title',
                'author',
                'isbn',
                'category',
                'quantity',
                'status',
                'created_at',
                'last_returned_at' => new Expression(
                    '(SELECT MAX(br.returned_at) FROM borrow_records br '
                    . 'WHERE br.book_id = books.book_id '
                    . 'AND br.returned_at IS NOT NULL)'
                ),
            ]);
            $this->applyFilters($select, $filters);
            $select->order(self::PK . ' ASC');
            $select->limit($safePerPage);
            $select->offset($offset);
        });
    }

    public function countFiltered(array $filters = []): int
    {
        $sql = $this->tableGateway->getSql();
        $select = $sql->select();
        $select->columns(['c' => new Expression('COUNT(*)')]);
        $this->applyFilters($select, $filters);

        $stmt = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        return $this->extractCount($result->current());
    }

    public function getBook(int $id): Book
    {
        $rowset = $this->tableGateway->select([self::PK => $id]);
        $row    = $rowset instanceof \Iterator ? $rowset->current() : null;
        if (! $row instanceof Book) {
            throw new RuntimeException(sprintf('Không tìm thấy sách có ID %d.', $id));
        }
        return $row;
    }

    public function saveBook(Book $book): void
    {
        $status = $book->status === 'unavailable'
            ? 'unavailable'
            : $this->resolveAvailabilityStatus($book->quantity);

        $data = [
            'title'    => $book->title,
            'author'   => $book->author,
            'isbn'     => $book->isbn !== '' ? $book->isbn : null,
            'category' => $book->category,
            'quantity' => $book->quantity,
            'status'   => $status,
        ];

        $book->status = $status;

        if ($book->id === 0) {
            $this->tableGateway->insert($data);
        } else {
            $this->tableGateway->update($data, [self::PK => $book->id]);
        }
    }

    public function deleteBook(int $id): void
    {
        $this->tableGateway->delete([self::PK => $id]);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function countAll(): int
    {
        $sql     = $this->tableGateway->getSql();
        $select  = $sql->select()->columns(['c' => new \Laminas\Db\Sql\Expression('COUNT(*)')]);
        $stmt    = $sql->prepareStatementForSqlObject($select);
        $result  = $stmt->execute();
        return $this->extractCount($result->current());
    }

    public function fetchCategories(): array
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select();
        $select->quantifier(Select::QUANTIFIER_DISTINCT);
        $select->columns(['category']);
        $select->where->isNotNull('category');
        $select->order('category ASC');
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();
        $categories = [];

        foreach ($result as $row) {
            if (! is_array($row)) {
                continue;
            }

            $category = trim((string) ($row['category'] ?? ''));
            if ($category !== '') {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    public function getSummary(): array
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'total_titles'      => new \Laminas\Db\Sql\Expression('COUNT(*)'),
            'available_titles'  => new \Laminas\Db\Sql\Expression(
                "SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END)"
            ),
            'borrowed_titles'   => new \Laminas\Db\Sql\Expression(
                "SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END)"
            ),
            'unavailable_titles' => new \Laminas\Db\Sql\Expression(
                "SUM(CASE WHEN status = 'unavailable' THEN 1 ELSE 0 END)"
            ),
            'total_copies'      => new \Laminas\Db\Sql\Expression('SUM(quantity)'),
        ]);
        $stmt   = $sql->prepareStatementForSqlObject($select);
        $summary = $this->normalizeSummaryRow($stmt->execute()->current());

        return [
            'total_titles'       => (int) ($summary['total_titles'] ?? 0),
            'available_titles'   => (int) ($summary['available_titles'] ?? 0),
            'borrowed_titles'    => (int) ($summary['borrowed_titles'] ?? 0),
            'unavailable_titles' => (int) ($summary['unavailable_titles'] ?? 0),
            'total_copies'       => (int) ($summary['total_copies'] ?? 0),
        ];
    }

    public function decrementAvailability(int $bookId): void
    {
        $book = $this->getBook($bookId);
        if ($book->status === 'unavailable') {
            throw new \DomainException('Sách này hiện đang bị đánh dấu không khả dụng.');
        }
        if ($book->quantity <= 0) {
            throw new \DomainException('Sách này hiện không còn bản sao để mượn.');
        }
        $newQty = $book->quantity - 1;
        $this->tableGateway->update(
            ['quantity' => $newQty, 'status' => $this->resolveAvailabilityStatus($newQty)],
            [self::PK => $bookId]
        );
    }

    public function incrementAvailability(int $bookId): void
    {
        $book   = $this->getBook($bookId);
        $newQty = $book->quantity + 1;
        $status = $book->status === 'unavailable'
            ? 'unavailable'
            : $this->resolveAvailabilityStatus($newQty);

        $this->tableGateway->update(
            ['quantity' => $newQty, 'status' => $status],
            [self::PK => $bookId]
        );
    }

    /**
     * Get book counts grouped by category.
     * Returns ['Văn học' => 5, 'Khoa học' => 3, ...]
     */
    public function getCategoryStats(): array
    {
        $sql = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'category',
            'cnt' => new Expression('COUNT(*)'),
        ])
        ->where->isNotNull('category');

        $select = $sql->select()->columns([
            'category',
            'cnt' => new Expression('COUNT(*)'),
        ]);
        $select->where->isNotNull('category');
        $select->group('category');
        $select->order(new Expression('COUNT(*) DESC'));

        $result = $sql->prepareStatementForSqlObject($select)->execute();
        $stats = [];
        foreach ($result as $row) {
            if (is_array($row) && !empty($row['category'])) {
                $stats[(string)$row['category']] = (int)$row['cnt'];
            }
        }
        return $stats;
    }

    /**
     * Tìm kiếm sách cho AJAX autocomplete.
     *
     * @param string $query       Từ khóa tìm kiếm (title / author / isbn)
     * @param bool   $availableOnly Chỉ trả về sách còn khả dụng (quantity > 0, status != unavailable)
     * @param int    $limit        Số kết quả tối đa
     * @return array<int, array{id:int,title:string,author:string,isbn:string,category:string,quantity:int,status:string}>
     */
    public function searchAvailable(string $query, bool $availableOnly = true, int $limit = 20): array
    {
        $sql    = $this->tableGateway->getSql();
        $select = $sql->select()->columns([
            'book_id',
            'title',
            'author',
            'isbn',
            'category',
            'quantity',
            'status',
        ]);

        // Tìm kiếm full-text theo title, author, isbn
        if ($query !== '') {
            $likeQuery = '%' . $query . '%';
            $select->where(function (Where $where) use ($likeQuery): void {
                $where->nest()
                    ->like('title',  $likeQuery)
                    ->or
                    ->like('author', $likeQuery)
                    ->or
                    ->like('isbn',   $likeQuery)
                    ->unnest();
            });
        }

        // Chỉ lấy sách khả dụng: status != 'unavailable' VÀ quantity >= 1
        if ($availableOnly) {
            $select->where(['status' => 'available']);
            $select->where('quantity >= 1');
        }

        $select->order('title ASC');
        $select->limit(max(1, min($limit, 50))); // tối đa 50 kết quả

        $stmt   = $sql->prepareStatementForSqlObject($select);
        $result = $stmt->execute();

        $books = [];
        foreach ($result as $row) {
            if (! is_array($row)) {
                continue;
            }
            $books[] = [
                'id'       => (int)    ($row['book_id']  ?? 0),
                'title'    => (string) ($row['title']    ?? ''),
                'author'   => (string) ($row['author']   ?? ''),
                'isbn'     => (string) ($row['isbn']     ?? ''),
                'category' => (string) ($row['category'] ?? ''),
                'quantity' => (int)    ($row['quantity'] ?? 0),
                'status'   => (string) ($row['status']   ?? ''),
            ];
        }

        return $books;
    }

    private function resolveAvailabilityStatus(int $quantity): string
    {
        return $quantity > 0 ? 'available' : 'borrowed';
    }

    private function applyFilters(Select $select, array $filters): void
    {
        $searchValue = trim((string) ($filters['search'] ?? ''));
        if ($searchValue !== '') {
            $search = '%' . $searchValue . '%';
            $select->where(function (Where $where) use ($search): void {
                $where->nest()
                    ->like('title', $search)
                    ->or
                    ->like('author', $search)
                    ->or
                    ->like('isbn', $search)
                    ->unnest();
            });
        }

        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $select->where(['category' => $category]);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $select->where(['status' => $status]);
        }
    }

    private function extractCount(mixed $current): int
    {
        if (! is_array($current)) {
            return 0;
        }

        return (int) ($current['c'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     * @psalm-suppress MixedAssignment
     */
    private function normalizeSummaryRow(mixed $row): array
    {
        if (! is_array($row)) {
            return [];
        }

        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
