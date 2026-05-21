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

        // If POST, handle creating a new book import request directly as approved
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
            $invoiceCode = trim((string)($data['invoice_code'] ?? ''));
            $invoiceUrl = trim((string)($data['invoice_url'] ?? ''));
            $note = trim((string)($data['note'] ?? ''));

            if ($title === '') {
                $this->flash()->addErrorMessage('Vui lòng nhập Tên sách.');
            } else {
                $sql = "INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, invoice_url, price, note, imported_by, status, import_date, created_at, updated_at) 
                        VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', CURDATE(), NOW(), NOW())";
                
                try {
                    $generatedCode = $invoiceCode !== '' ? $invoiceCode : 'INV-' . strtoupper(uniqid());
                    $this->dbAdapter->query($sql, [
                        $generatedCode,
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
                    $importId = (int)$this->dbAdapter->getDriver()->getLastGeneratedValue();

                    // Retrieve inserted record to sync
                    $stmt = $this->dbAdapter->query("SELECT * FROM book_imports WHERE import_id = ? LIMIT 1");
                    $insertedRow = iterator_to_array($stmt->execute([$importId]))[0];

                    $bookId = $this->syncImportToBooks($insertedRow);

                    // Update import record with book_id
                    $this->dbAdapter->query(
                        "UPDATE book_imports SET book_id = ? WHERE import_id = ?",
                        [$bookId, $importId]
                    );

                    $this->flash()->addSuccessMessage('Đã nhập kho sách trực tiếp thành công.');
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

        // Fetch stats for the selected year grouped by Quarter (Q1, Q2, Q3, Q4) - filter by status = 'approved'
        $statsSql = "SELECT 
                        QUARTER(import_date) AS qtr, 
                        COUNT(*) AS total_count,
                        SUM(price * quantity) AS total_spend
                     FROM book_imports
                     WHERE status = 'approved' AND YEAR(import_date) = ?
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

        if (($status === 'approved' || $status === 'completed') && $import['status'] === 'pending') {
            try {
                $bookId = $this->syncImportToBooks($import);
                // Update import request to approved
                $this->dbAdapter->query(
                    "UPDATE book_imports SET status = 'approved', book_id = ?, import_date = CURDATE(), updated_at = NOW() WHERE import_id = ?",
                    [$bookId, $id]
                );
                $this->flash()->addSuccessMessage('Đã phê duyệt nhập kho và cập nhật số lượng sách #' . $id);
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Lỗi phê duyệt: ' . $e->getMessage());
            }
        } elseif ($status === 'rejected' && $import['status'] === 'pending') {
            $this->dbAdapter->query(
                "UPDATE book_imports SET status = 'rejected', updated_at = NOW() WHERE import_id = ?",
                [$id]
            );
            $this->flash()->addSuccessMessage('Đã từ chối yêu cầu nhập sách #' . $id);
        } else {
            $this->flash()->addErrorMessage('Hành động hoặc trạng thái không hợp lệ.');
        }

        return $this->redirect()->toRoute('library/books-import');
    }

    public function exportAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $selectedYear = (int)($this->params()->fromQuery('year', date('Y')));

        // Fetch imports
        $sql = "SELECT i.*, u.username as admin_name, b.title as existing_book_title 
                FROM book_imports i 
                LEFT JOIN users u ON i.imported_by = u.user_id 
                LEFT JOIN books b ON i.book_id = b.book_id 
                WHERE YEAR(i.import_date) = ?
                ORDER BY i.created_at DESC";
        $imports = iterator_to_array($this->dbAdapter->query($sql)->execute([$selectedYear]));

        // Fetch quarterly stats
        $statsSql = "SELECT 
                        QUARTER(import_date) AS qtr, 
                        COUNT(*) AS total_count,
                        SUM(price * quantity) AS total_spend
                     FROM book_imports
                     WHERE status = 'approved' AND YEAR(import_date) = ?
                     GROUP BY QUARTER(import_date)";
        $statsRaw = iterator_to_array($this->dbAdapter->query($statsSql)->execute([$selectedYear]));

        $quarterlyStats = [
            1 => ['count' => 0, 'spend' => 0.0],
            2 => ['count' => 0, 'spend' => 0.0],
            3 => ['count' => 0, 'spend' => 0.0],
            4 => ['count' => 0, 'spend' => 0.0]
        ];
        $totalSpendYear = 0.0;
        foreach ($statsRaw as $row) {
            $q = (int)$row['qtr'];
            if (isset($quarterlyStats[$q])) {
                $quarterlyStats[$q]['count'] = (int)$row['total_count'];
                $quarterlyStats[$q]['spend'] = (float)$row['total_spend'];
                $totalSpendYear += (float)$row['total_spend'];
            }
        }

        // Generate CSV output
        $output = fopen('php://temp', 'r+');
        
        // UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");

        // 1. Report Header
        fputcsv($output, ['BÁO CÁO THỐNG KÊ NHẬP KHO & CHI TIÊU SÁCH THƯ VIỆN']);
        fputcsv($output, ['Năm báo cáo:', $selectedYear]);
        fputcsv($output, ['Ngày xuất báo cáo:', date('d/m/Y H:i:s')]);
        fputcsv($output, ['Tổng chi tiêu cả năm:', number_format($totalSpendYear, 0, ',', '.') . ' đ']);
        fputcsv($output, []);

        // 2. Quarterly Stats Section
        fputcsv($output, ['THỐNG KÊ CHI TIÊU THEO QUÝ']);
        fputcsv($output, ['Quý', 'Số lượng yêu cầu', 'Tổng chi tiêu (đ)']);
        $quartersNames = [1 => 'Quý I', 2 => 'Quý II', 3 => 'Quý III', 4 => 'Quý IV'];
        foreach ($quarterlyStats as $qtr => $data) {
            fputcsv($output, [
                $quartersNames[$qtr],
                $data['count'],
                number_format($data['spend'], 0, ',', '.')
            ]);
        }
        fputcsv($output, []);

        // 3. Detailed Imports List Section
        fputcsv($output, ['DANH SÁCH CHI TIẾT PHIẾU NHẬP KHO']);
        fputcsv($output, [
            'ID', 
            'Mã Hóa Đơn', 
            'Tên Sách', 
            'Tác Giả', 
            'ISBN', 
            'Thể Loại', 
            'NXB', 
            'Năm XB', 
            'Loại Nhập', 
            'Đơn Giá (đ)', 
            'Số Lượng', 
            'Thành Tiền (đ)', 
            'Trạng Thái', 
            'Ngày Nhập'
        ]);

        $statusMap = [
            'pending'  => 'Chờ duyệt',
            'approved' => 'Đã nhập kho',
            'rejected' => 'Đã từ chối'
        ];
        $typeMap = [
            'purchase' => 'Mua mới',
            'donation' => 'Tài trợ',
            'other'    => 'Khác'
        ];

        foreach ($imports as $import) {
            $totalPrice = (float)$import['price'] * (int)$import['quantity'];
            fputcsv($output, [
                $import['import_id'],
                $import['invoice_code'],
                $import['title'],
                $import['author'],
                $import['isbn'],
                $import['category'],
                $import['publisher'],
                $import['published_year'],
                $typeMap[$import['import_type']] ?? $import['import_type'],
                number_format((float)$import['price'], 0, ',', '.'),
                $import['quantity'],
                number_format($totalPrice, 0, ',', '.'),
                $statusMap[$import['status']] ?? $import['status'],
                $import['import_date']
            ]);
        }

        rewind($output);
        $csvData = stream_get_contents($output);
        fclose($output);

        $response = $this->getResponse();
        $response->getHeaders()->addHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="bao-cao-nhap-kho-' . $selectedYear . '.csv"',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
        $response->setContent($csvData);

        return $response;
    }

    private function syncImportToBooks(array $import): int
    {
        $title = $import['title'];
        $isbn = $import['isbn'];
        $quantity = (int)$import['quantity'];

        // Try to find matching book by ISBN
        $matchingBookId = null;
        if ($isbn !== null && trim($isbn) !== '') {
            $checkStmt = $this->dbAdapter->query("SELECT book_id FROM books WHERE isbn = ? LIMIT 1");
            $books = iterator_to_array($checkStmt->execute([$isbn]));
            if (count($books) > 0) {
                $matchingBookId = (int)$books[0]['book_id'];
            }
        }

        // Fallback to title match
        if ($matchingBookId === null) {
            $checkStmt = $this->dbAdapter->query("SELECT book_id FROM books WHERE title = ? LIMIT 1");
            $books = iterator_to_array($checkStmt->execute([$title]));
            if (count($books) > 0) {
                $matchingBookId = (int)$books[0]['book_id'];
            }
        }

        if ($matchingBookId !== null) {
            // Increment quantity and make available
            $this->dbAdapter->query(
                "UPDATE books SET quantity = quantity + ?, status = 'available' WHERE book_id = ?", 
                [$quantity, $matchingBookId]
            );
        } else {
            // Insert as a new book in the catalog
            $insertSql = "INSERT INTO books (title, author, isbn, category, publisher, published_year, quantity, status, import_date, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'available', CURDATE(), NOW())";
            $this->dbAdapter->query($insertSql, [
                $title,
                $import['author'] !== '' ? $import['author'] : 'Khác',
                $isbn !== '' ? $isbn : null,
                $import['category'] !== '' ? $import['category'] : 'Khác',
                $import['publisher'] !== '' ? $import['publisher'] : null,
                $import['published_year'] !== '' ? $import['published_year'] : null,
                $quantity
            ]);
            $matchingBookId = (int)$this->dbAdapter->getDriver()->getLastGeneratedValue();
        }

        return $matchingBookId;
    }
}
