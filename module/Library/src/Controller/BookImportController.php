<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Model\Table\BookTable;
use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;

class BookImportController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private BookTable $bookTable,
        private AdapterInterface $dbAdapter
    ) {
        parent::__construct($authSessionContainer);
    }

    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $currentUser = $this->currentUser();
        $selectedYear = (int)($this->params()->fromQuery('year', date('Y')));

        // If POST, handle creating a new book import request
        if ($this->getRequest()->isPost()) {
            $data = $this->postData();
            $title = trim((string)($data['title'] ?? ''));
            $author = trim((string)($data['author'] ?? ''));
            $isbn = trim((string)($data['isbn'] ?? ''));
            $category = trim((string)($data['category'] ?? 'Khác'));
            $publisher = trim((string)($data['publisher'] ?? ''));
            $publishedYear = !empty($data['published_year']) ? (int)$data['published_year'] : null;
            $quantity = max(1, (int)($data['quantity'] ?? 1));
            $price = max(0.0, (float)($data['price'] ?? 0.0));
            $importType = $data['import_type'] ?? 'purchase';
            $invoiceUrl = trim((string)($data['invoice_url'] ?? ''));
            $note = trim((string)($data['note'] ?? ''));

            if ($title === '') {
                $this->flash()->addErrorMessage('Vui lòng nhập Tên sách.');
            } else {
                $sql = "INSERT INTO book_imports (book_id, title, author, isbn, category, publisher, published_year, quantity, import_type, invoice_url, price, note, imported_by, status, import_date, created_at, updated_at) 
                        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE(), NOW(), NOW())";
                
                try {
                    $this->dbAdapter->query($sql, [
                        $title,
                        $author !== '' ? $author : 'Khác',
                        $isbn !== '' ? $isbn : null,
                        $category !== '' ? $category : 'Khác',
                        $publisher !== '' ? $publisher : null,
                        $publishedYear,
                        $quantity,
                        $importType,
                        $invoiceUrl !== '' ? $invoiceUrl : null,
                        $price,
                        $note !== '' ? $note : null,
                        $currentUser['id']
                    ]);
                    $this->flash()->addSuccessMessage('Đã tạo yêu cầu nhập sách online thành công.');
                } catch (\Throwable $e) {
                    $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
                }
                return $this->redirect()->toRoute('library/books-import');
            }
        }

        // Fetch imports
        $sql = "SELECT i.*, u.username as admin_name, b.title as existing_book_title 
                FROM book_imports i 
                LEFT JOIN users u ON i.imported_by = u.user_id 
                LEFT JOIN books b ON i.book_id = b.book_id 
                ORDER BY i.created_at DESC";
        $imports = iterator_to_array($this->dbAdapter->query($sql)->execute());

        // Fetch stats for the selected year grouped by Quarter (Q1, Q2, Q3, Q4)
        $statsSql = "SELECT 
                        QUARTER(import_date) AS qtr, 
                        COUNT(*) AS total_count,
                        SUM(price * quantity) AS total_spend
                     FROM book_imports
                     WHERE status = 'completed' AND YEAR(import_date) = ?
                     GROUP BY QUARTER(import_date)";
        $statsRaw = iterator_to_array($this->dbAdapter->query($statsSql)->execute([$selectedYear]));

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

        return new ViewModel([
            'imports' => $imports,
            'quarterlyStats' => $quarterlyStats,
            'totalSpendYear' => $totalSpendYear,
            'totalImportsYear' => $totalImportsYear,
            'selectedYear' => $selectedYear
        ]);
    }

    public function updateAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        $status = $this->params()->fromQuery('status', '');

        if ($id <= 0) {
            $this->flash()->addErrorMessage('ID yêu cầu không hợp lệ.');
            return $this->redirect()->toRoute('library/books-import');
        }

        // Get import record
        $stmt = $this->dbAdapter->query("SELECT * FROM book_imports WHERE import_id = ? LIMIT 1");
        $imports = iterator_to_array($stmt->execute([$id]));
        if (count($imports) === 0) {
            $this->flash()->addErrorMessage('Không tìm thấy yêu cầu nhập sách.');
            return $this->redirect()->toRoute('library/books-import');
        }
        $import = $imports[0];

        if ($status === 'processing' && $import['status'] === 'pending') {
            $this->dbAdapter->query("UPDATE book_imports SET status = 'processing', updated_at = NOW() WHERE import_id = ?", [$id]);
            $this->flash()->addSuccessMessage('Đang xử lý yêu cầu nhập sách #' . $id);
        } elseif ($status === 'completed' && ($import['status'] === 'pending' || $import['status'] === 'processing')) {
            // Perform Database Synchronization
            $title = $import['title'];
            $isbn = $import['isbn'];
            $quantity = (int)$import['quantity'];

            // Try to find matching book
            $matchingBookId = null;
            if ($isbn !== null && trim($isbn) !== '') {
                $checkStmt = $this->dbAdapter->query("SELECT book_id FROM books WHERE isbn = ? LIMIT 1");
                $books = iterator_to_array($checkStmt->execute([$isbn]));
                if (count($books) > 0) {
                    $matchingBookId = (int)$books[0]['book_id'];
                }
            }

            if ($matchingBookId === null) {
                $checkStmt = $this->dbAdapter->query("SELECT book_id FROM books WHERE title = ? LIMIT 1");
                $books = iterator_to_array($checkStmt->execute([$title]));
                if (count($books) > 0) {
                    $matchingBookId = (int)$books[0]['book_id'];
                }
            }

            if ($matchingBookId !== null) {
                // Increment availability/quantity of existing book
                $this->dbAdapter->query(
                    "UPDATE books SET quantity = quantity + ?, status = 'available' WHERE book_id = ?", 
                    [$quantity, $matchingBookId]
                );
            } else {
                // Insert a new book
                $insertSql = "INSERT INTO books (title, author, isbn, category, publisher, published_year, quantity, status, import_date, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'available', CURDATE(), NOW())";
                $this->dbAdapter->query($insertSql, [
                    $title,
                    $import['author'] ?? 'Khác',
                    $isbn,
                    $import['category'] ?? 'Khác',
                    $import['publisher'] ?? null,
                    $import['published_year'] ?? null,
                    $quantity
                ]);
                $matchingBookId = (int)$this->dbAdapter->getDriver()->getLastGeneratedValue();
            }

            // Update import request to completed
            $this->dbAdapter->query(
                "UPDATE book_imports SET status = 'completed', book_id = ?, import_date = CURDATE(), updated_at = NOW() WHERE import_id = ?",
                [$matchingBookId, $id]
            );

            $this->flash()->addSuccessMessage('Đã hoàn thành nhập sách #' . $id . ' và cập nhật vào kho thư viện.');
        } elseif ($status === 'delete' && $import['status'] !== 'completed') {
            $this->dbAdapter->query("DELETE FROM book_imports WHERE import_id = ?", [$id]);
            $this->flash()->addSuccessMessage('Đã xóa yêu cầu nhập sách #' . $id);
        } else {
            $this->flash()->addErrorMessage('Trạng thái chuyển đổi không hợp lệ.');
        }

        return $this->redirect()->toRoute('library/books-import');
    }
}
