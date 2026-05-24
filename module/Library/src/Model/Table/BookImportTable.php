<?php

declare(strict_types=1);

namespace Library\Model\Table;

use Laminas\Db\TableGateway\TableGateway;
use Laminas\Db\Adapter\AdapterInterface;

class BookImportTable
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

    public function getImport(int $id): ?array
    {
        $sql = "SELECT * FROM book_imports WHERE import_id = ? LIMIT 1";
        $row = $this->getAdapter()->query($sql)->execute([$id])->current();
        return $row ? (array) $row : null;
    }

    public function approveImport(int $importId, int $bookId): void
    {
        $this->getAdapter()->query(
            "UPDATE book_imports SET status = 'approved', book_id = ?, import_date = CURDATE(), updated_at = NOW() WHERE import_id = ?",
            [$bookId, $importId]
        );
    }

    public function rejectImport(int $importId): void
    {
        $this->getAdapter()->query(
            "UPDATE book_imports SET status = 'rejected', updated_at = NOW() WHERE import_id = ?",
            [$importId]
        );
    }

    public function insertImport(array $data): int
    {
        $this->tableGateway->insert([
            'book_id'        => $data['book_id'],
            'invoice_code'   => $data['invoice_code'],
            'title'          => $data['title'],
            'author'         => $data['author'],
            'isbn'           => $data['isbn'],
            'category'       => $data['category'],
            'publisher'      => $data['publisher'],
            'published_year' => $data['published_year'],
            'quantity'       => $data['quantity'],
            'import_type'    => $data['import_type'],
            'invoice_url'    => $data['invoice_url'],
            'price'          => $data['price'],
            'note'           => $data['note'],
            'imported_by'    => $data['imported_by'],
            'status'         => $data['status'] ?? 'pending',
            'import_date'    => $data['status'] === 'approved' ? new \Laminas\Db\Sql\Expression('CURDATE()') : null,
            'created_at'     => new \Laminas\Db\Sql\Expression('NOW()'),
            'updated_at'     => new \Laminas\Db\Sql\Expression('NOW()'),
        ]);
        return (int) $this->tableGateway->getLastInsertValue();
    }

    public function getTypeCounts(string $periodWhereNoAlias, array $periodParamsNoAlias, string $search): array
    {
        $typeCountSql = "SELECT import_type, COUNT(*) AS cnt FROM book_imports WHERE " . $periodWhereNoAlias;
        $paramsTypeCounts = $periodParamsNoAlias;
        if ($search !== '') {
            $typeCountSql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR invoice_code LIKE ? OR publisher LIKE ?)";
            $sw = '%' . $search . '%';
            array_push($paramsTypeCounts, $sw, $sw, $sw, $sw, $sw);
        }
        $typeCountSql .= " GROUP BY import_type";

        $typeCountRaw = iterator_to_array($this->getAdapter()->query($typeCountSql)->execute($paramsTypeCounts));
        $typeCounts = ['all' => 0, 'purchase' => 0, 'donation' => 0, 'other' => 0];
        foreach ($typeCountRaw as $row) {
            if (isset($typeCounts[$row['import_type']])) {
                $typeCounts[$row['import_type']] = (int)$row['cnt'];
            }
            $typeCounts['all'] += (int)$row['cnt'];
        }
        return $typeCounts;
    }

    public function countFiltered(array $whereList, array $paramsList): int
    {
        $totalSql = "SELECT COUNT(*) AS cnt FROM book_imports i WHERE " . implode(" AND ", $whereList);
        $row = $this->getAdapter()->query($totalSql)->execute($paramsList)->current();
        return (int)(($row['cnt']) ?? 0);
    }

    public function fetchImports(array $whereList, array $paramsList, string $orderBy, int $limit, int $offset): array
    {
        $sql = "SELECT i.*, u.username as admin_name, b.title as existing_book_title
                FROM book_imports i
                LEFT JOIN users u ON i.imported_by = u.user_id
                LEFT JOIN books b ON i.book_id = b.book_id
                WHERE " . implode(" AND ", $whereList) . "
                ORDER BY " . $orderBy . " LIMIT ? OFFSET ?";

        $bindParams = $paramsList;
        $bindParams[] = $limit;
        $bindParams[] = $offset;

        $results = $this->getAdapter()->query($sql)->execute($bindParams);
        return iterator_to_array($results);
    }

    public function fetchAllImportsForExport(array $whereList, array $paramsList, string $orderBy): array
    {
        $sql = "SELECT i.*, u.username as admin_name, b.title as existing_book_title 
                FROM book_imports i 
                LEFT JOIN users u ON i.imported_by = u.user_id 
                LEFT JOIN books b ON i.book_id = b.book_id 
                WHERE " . implode(" AND ", $whereList) . "
                ORDER BY " . $orderBy;
        $results = $this->getAdapter()->query($sql)->execute($paramsList);
        return iterator_to_array($results);
    }

    public function getQuarterlyStats(int $year): array
    {
        $statsSql = "SELECT
                        QUARTER(import_date) AS qtr,
                        COUNT(*) AS total_count,
                        SUM(price * quantity) AS total_spend
                     FROM book_imports
                     WHERE status = 'approved' AND YEAR(import_date) = ?
                     GROUP BY QUARTER(import_date)";
        $statsRaw = iterator_to_array($this->getAdapter()->query($statsSql)->execute([$year]));
        
        $quarterlyStats = [
            1 => ['count' => 0, 'spend' => 0.0],
            2 => ['count' => 0, 'spend' => 0.0],
            3 => ['count' => 0, 'spend' => 0.0],
            4 => ['count' => 0, 'spend' => 0.0]
        ];
        $totalSpendYear = 0.0;
        $totalImportsYear = 0;

        foreach ($statsRaw as $row) {
            $q = (int)$row['qtr'];
            if (isset($quarterlyStats[$q])) {
                $quarterlyStats[$q]['count'] = (int)$row['total_count'];
                $quarterlyStats[$q]['spend'] = (float)$row['total_spend'];
                $totalSpendYear += (float)$row['total_spend'];
                $totalImportsYear += (int)$row['total_count'];
            }
        }

        return [
            'quarterlyStats' => $quarterlyStats,
            'totalSpendYear' => $totalSpendYear,
            'totalImportsYear' => $totalImportsYear
        ];
    }

    public function syncImportToBooks(array $import): int
    {
        $title = $import['title'];
        $isbn = ! empty($import['isbn']) ? trim((string)$import['isbn']) : null;
        $quantity = (int)$import['quantity'];

        $matchingBookId = null;
        if ($isbn !== null) {
            $checkStmt = $this->getAdapter()->query("SELECT book_id FROM books WHERE isbn = ? LIMIT 1");
            $books = iterator_to_array($checkStmt->execute([$isbn]));
            if (count($books) > 0) {
                $matchingBookId = (int)$books[0]['book_id'];
            }
        }

        if ($matchingBookId === null) {
            $checkStmt = $this->getAdapter()->query("SELECT book_id FROM books WHERE title = ? LIMIT 1");
            $books = iterator_to_array($checkStmt->execute([$title]));
            if (count($books) > 0) {
                $matchingBookId = (int)$books[0]['book_id'];
            }
        }

        if ($matchingBookId !== null) {
            $this->getAdapter()->query(
                "UPDATE books SET quantity = quantity + ?, status = 'available' WHERE book_id = ?",
                [$quantity, $matchingBookId]
            );
        } else {
            $insertSql = "INSERT INTO books (title, author, isbn, category, publisher, published_year, quantity, status, import_date, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'available', CURDATE(), NOW())";
            $this->getAdapter()->query($insertSql, [
                $title,
                ! empty($import['author']) ? $import['author'] : 'Khác',
                $isbn,
                ! empty($import['category']) ? $import['category'] : 'Khác',
                ! empty($import['publisher']) ? $import['publisher'] : null,
                ! empty($import['published_year']) ? $import['published_year'] : null,
                $quantity,
            ]);
            $matchingBookId = (int)$this->getAdapter()->getDriver()->getLastGeneratedValue();
        }

        return $matchingBookId;
    }
}
