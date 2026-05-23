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



        // Extract filters
        $search  = trim((string)$this->params()->fromQuery('search', ''));
        $type    = trim((string)$this->params()->fromQuery('type', ''));
        // period: 'year' | 'q1'..'q4' | 'm1'..'m12'
        $period  = trim((string)$this->params()->fromQuery('period', 'year'));
        $sort    = trim((string)$this->params()->fromQuery('sort', ''));
        $direction = strtoupper(trim((string)$this->params()->fromQuery('direction', 'DESC')));
        if (! in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        // Parse period into SQL condition + params
        $periodWhere  = "YEAR(COALESCE(i.import_date, i.created_at)) = ?";
        $periodParams = [$selectedYear];
        $periodWhereNoAlias  = "YEAR(COALESCE(import_date, created_at)) = ?";
        $periodParamsNoAlias = [$selectedYear];

        if (preg_match('/^q([1-4])$/', $period, $m)) {
            $q = (int)$m[1];
            $periodWhere        .= " AND QUARTER(COALESCE(i.import_date, i.created_at)) = ?";
            $periodParams[]      = $q;
            $periodWhereNoAlias .= " AND QUARTER(COALESCE(import_date, created_at)) = ?";
            $periodParamsNoAlias[] = $q;
        } elseif (preg_match('/^m(\d{1,2})$/', $period, $m)) {
            $mo = (int)$m[1];
            if ($mo >= 1 && $mo <= 12) {
                $periodWhere        .= " AND MONTH(COALESCE(i.import_date, i.created_at)) = ?";
                $periodParams[]      = $mo;
                $periodWhereNoAlias .= " AND MONTH(COALESCE(import_date, created_at)) = ?";
                $periodParamsNoAlias[] = $mo;
            }
        }

        // Query import type counts matching current search + period filter
        $typeCountSql    = "SELECT import_type, COUNT(*) AS cnt FROM book_imports WHERE " . $periodWhereNoAlias;
        $paramsTypeCounts = $periodParamsNoAlias;
        if ($search !== '') {
            $typeCountSql .= " AND (title LIKE ? OR author LIKE ? OR isbn LIKE ? OR invoice_code LIKE ? OR publisher LIKE ?)";
            $sw = '%' . $search . '%';
            array_push($paramsTypeCounts, $sw, $sw, $sw, $sw, $sw);
        }
        $typeCountSql .= " GROUP BY import_type";

        $typeCountRaw = iterator_to_array($this->dbAdapter->query($typeCountSql)->execute($paramsTypeCounts));
        $typeCounts = ['all' => 0, 'purchase' => 0, 'donation' => 0, 'other' => 0];
        foreach ($typeCountRaw as $row) {
            if (isset($typeCounts[$row['import_type']])) {
                $typeCounts[$row['import_type']] = (int)$row['cnt'];
            }
            $typeCounts['all'] += (int)$row['cnt'];
        }

        // Paginate the imports list (10 per page)
        $page    = max(1, (int)($this->params()->fromQuery('page', 1)));
        $perPage = 10;

        $whereList  = [$periodWhere];
        $paramsList = $periodParams;
        if ($search !== '') {
            $whereList[] = "(i.title LIKE ? OR i.author LIKE ? OR i.isbn LIKE ? OR i.invoice_code LIKE ? OR i.publisher LIKE ?)";
            $sw = '%' . $search . '%';
            array_push($paramsList, $sw, $sw, $sw, $sw, $sw);
        }
        if ($type !== '') {
            $whereList[] = "i.import_type = ?";
            $paramsList[] = $type;
        }

        $totalSql   = "SELECT COUNT(*) AS cnt FROM book_imports i WHERE " . implode(" AND ", $whereList);
        $totalCount = (int)(($this->dbAdapter->query($totalSql)->execute($paramsList)->current()['cnt']) ?? 0);
        $totalPages = max(1, (int)ceil($totalCount / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        $allowedSorts = [
            'id' => 'i.import_id',
            'invoice' => 'i.invoice_code',
            'book' => 'i.title',
            'category' => 'i.category',
            'price' => 'i.price',
            'quantity' => 'i.quantity',
            'total' => '(i.price * i.quantity)',
            'status' => 'i.status',
            'date' => 'i.created_at',
        ];
        $orderBy = 'i.created_at DESC';
        if (array_key_exists($sort, $allowedSorts)) {
            $orderBy = $allowedSorts[$sort] . ' ' . $direction;
        }

        // Fetch paginated imports
        $sql = "SELECT i.*, u.username as admin_name, b.title as existing_book_title
                FROM book_imports i
                LEFT JOIN users u ON i.imported_by = u.user_id
                LEFT JOIN books b ON i.book_id = b.book_id
                WHERE " . implode(" AND ", $whereList) . "
                ORDER BY " . $orderBy . " LIMIT ? OFFSET ?";

        $bindParams   = $paramsList;
        $bindParams[] = $perPage;
        $bindParams[] = $offset;

        $imports = iterator_to_array($this->dbAdapter->query($sql)->execute($bindParams));

        // Fetch stats for the selected year grouped by Quarter — filter by status = 'approved'
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
        $totalSpendYear   = 0.0;
        $totalImportsYear = 0;

        foreach ($statsRaw as $row) {
            $q = (int)$row['qtr'];
            if (isset($quarterlyStats[$q])) {
                $quarterlyStats[$q]['count'] = (int)$row['total_count'];
                $quarterlyStats[$q]['spend'] = (float)$row['total_spend'];
                $totalSpendYear   += (float)$row['total_spend'];
                $totalImportsYear += (int)$row['total_count'];
            }
        }

        return new ViewModel([
            'imports'          => $imports,
            'quarterlyStats'   => $quarterlyStats,
            'totalSpendYear'   => $totalSpendYear,
            'totalImportsYear' => $totalImportsYear,
            'selectedYear'     => $selectedYear,
            'page'             => $page,
            'totalPages'       => $totalPages,
            'totalCount'       => $totalCount,
            'search'           => $search,
            'type'             => $type,
            'period'           => $period,
            'typeCounts'       => $typeCounts,
            'sort'             => $sort,
            'direction'        => strtolower($direction),
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
        $search = trim((string)$this->params()->fromQuery('search', ''));
        $type   = trim((string)$this->params()->fromQuery('type', ''));
        $period = trim((string)$this->params()->fromQuery('period', 'year'));
        $sort   = trim((string)$this->params()->fromQuery('sort', ''));
        $direction = strtoupper(trim((string)$this->params()->fromQuery('direction', 'DESC')));
        if (! in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'DESC';
        }

        // Parse period into SQL condition + params
        $periodWhere  = "YEAR(COALESCE(i.import_date, i.created_at)) = ?";
        $periodParams = [$selectedYear];
        $periodWhereNoAlias  = "YEAR(COALESCE(import_date, created_at)) = ?";
        $periodParamsNoAlias = [$selectedYear];

        if (preg_match('/^q([1-4])$/', $period, $m)) {
            $q = (int)$m[1];
            $periodWhere        .= " AND QUARTER(COALESCE(i.import_date, i.created_at)) = ?";
            $periodParams[]      = $q;
            $periodWhereNoAlias .= " AND QUARTER(COALESCE(import_date, created_at)) = ?";
            $periodParamsNoAlias[] = $q;
        } elseif (preg_match('/^m(\d{1,2})$/', $period, $m)) {
            $mo = (int)$m[1];
            if ($mo >= 1 && $mo <= 12) {
                $periodWhere        .= " AND MONTH(COALESCE(i.import_date, i.created_at)) = ?";
                $periodParams[]      = $mo;
                $periodWhereNoAlias .= " AND MONTH(COALESCE(import_date, created_at)) = ?";
                $periodParamsNoAlias[] = $mo;
            }
        }

        // Fetch imports with period filter and optional search/type filters
        $whereList = [$periodWhere];
        $paramsList = $periodParams;

        if ($search !== '') {
            $whereList[] = "(i.title LIKE ? OR i.author LIKE ? OR i.isbn LIKE ? OR i.invoice_code LIKE ? OR i.publisher LIKE ?)";
            $searchWildcard = '%' . $search . '%';
            $paramsList[] = $searchWildcard;
            $paramsList[] = $searchWildcard;
            $paramsList[] = $searchWildcard;
            $paramsList[] = $searchWildcard;
            $paramsList[] = $searchWildcard;
        }
        if ($type !== '') {
            $whereList[] = "i.import_type = ?";
            $paramsList[] = $type;
        }

        $allowedSorts = [
            'id' => 'i.import_id',
            'invoice' => 'i.invoice_code',
            'book' => 'i.title',
            'category' => 'i.category',
            'price' => 'i.price',
            'quantity' => 'i.quantity',
            'total' => '(i.price * i.quantity)',
            'status' => 'i.status',
            'date' => 'i.created_at',
        ];
        $orderBy = 'i.created_at DESC';
        if (array_key_exists($sort, $allowedSorts)) {
            $orderBy = $allowedSorts[$sort] . ' ' . $direction;
        }

        $sql = "SELECT i.*, u.username as admin_name, b.title as existing_book_title 
                FROM book_imports i 
                LEFT JOIN users u ON i.imported_by = u.user_id 
                LEFT JOIN books b ON i.book_id = b.book_id 
                WHERE " . implode(" AND ", $whereList) . "
                ORDER BY " . $orderBy;
        $imports = iterator_to_array($this->dbAdapter->query($sql)->execute($paramsList));

        // Fetch quarterly stats for the selected year (unfiltered by quarter/month so it gives full context)
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
        $totalSpendYear   = 0.0;
        $totalImportsYear = 0;
        foreach ($statsRaw as $row) {
            $q = (int)$row['qtr'];
            if (isset($quarterlyStats[$q])) {
                $quarterlyStats[$q]['count'] = (int)$row['total_count'];
                $quarterlyStats[$q]['spend'] = (float)$row['total_spend'];
                $totalSpendYear   += (float)$row['total_spend'];
                $totalImportsYear += (int)$row['total_count'];
            }
        }

        // Generate XLSX output
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Bao cao nhap kho ' . $selectedYear)
            ->setCreator('Thu vien');

        $hStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];
        $sumStyle  = ['font' => ['bold' => true], 'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']]];
        $evenStyle = ['fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0F4FA']]];
        $boldStyle = ['font' => ['bold' => true]];
        $titleStyle = ['font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF1A237E']]];
        $money = '#,##0';

        $periodText = 'Cả năm';
        $periodLabel = 'ca-nam';
        if (preg_match('/^q([1-4])$/', $period, $m)) {
            $periodText = 'Quý ' . $m[1];
            $periodLabel = 'quy-' . $m[1];
        } elseif (preg_match('/^m(\d{1,2})$/', $period, $m)) {
            $periodText = 'Tháng ' . $m[1];
            $periodLabel = 'thang-' . $m[1];
        }

        // ── Sheet 1: Danh sach chi tiet (Now primary!) ──────────────────
        $s1 = $spreadsheet->getActiveSheet();
        $s1->setTitle('Chi tiet hoa don');

        $statusMap = ['pending' => 'Cho duyet', 'approved' => 'Da nhap kho', 'rejected' => 'Da tu choi'];
        $typeMap   = ['purchase' => 'Mua moi', 'donation' => 'Tai tro', 'other' => 'Khac'];

        $totalSpendFiltered = 0.0;
        $totalCountFiltered = 0;
        $dataRows = [];
        foreach ($imports as $imp) {
            $total = (float)$imp['price'] * (int)$imp['quantity'];
            $totalSpendFiltered += $total;
            $totalCountFiltered++;
            $dataRows[] = [
                (int)$imp['import_id'],
                $imp['invoice_code'],
                $imp['title'],
                $imp['author'],
                $imp['isbn'] ?? '',
                $imp['category'],
                $imp['publisher'] ?? '',
                $imp['published_year'] ?? '',
                $typeMap[$imp['import_type']] ?? $imp['import_type'],
                (float)$imp['price'],
                (int)$imp['quantity'],
                $total,
                $statusMap[$imp['status']] ?? $imp['status'],
                $imp['admin_name'] ?? '',
                $imp['import_date'] ?? '',
            ];
        }

        // Title Block
        $s1->setCellValue('A1', 'CHI TIET PHIEU NHAP KHO & HOA DON SACH');
        $s1->mergeCells('A1:F1');
        $s1->getStyle('A1')->applyFromArray($titleStyle);

        $s1->setCellValue('A2', 'Thoi gian:');
        $s1->setCellValue('B2', $periodText . ' / Nam ' . $selectedYear);
        $s1->setCellValue('A3', 'Ngay xuat:');
        $s1->setCellValue('B3', date('d/m/Y H:i:s'));

        $s1->setCellValue('D2', 'Tong so phieu:');
        $s1->setCellValue('E2', $totalCountFiltered);
        $s1->setCellValue('D3', 'Tong chi tieu:');
        $s1->setCellValue('E3', $totalSpendFiltered);
        $s1->getStyle('E3')->getNumberFormat()->setFormatCode($money . ' "d"');

        $s1->getStyle('A2:A3')->applyFromArray($boldStyle);
        $s1->getStyle('D2:D3')->applyFromArray($boldStyle);
        $s1->getStyle('E2:E3')->applyFromArray($boldStyle);

        // Table Headers at Row 5
        $h2 = ['ID', 'Ma hoa don', 'Ten sach', 'Tac gia', 'ISBN', 'The loai', 'NXB',
                'Nam XB', 'Loai nhap', 'Don gia (d)', 'So luong', 'Thanh tien (d)',
                'Trang thai', 'Nguoi nhap', 'Ngay nhap'];
        $s1->fromArray([$h2], null, 'A5');
        $s1->getStyle('A5:O5')->applyFromArray($hStyle);
        $s1->setAutoFilter('A5:O5');
        $s1->freezePane('A6');

        $r2 = 6;
        foreach ($dataRows as $row) {
            $s1->fromArray([$row], null, 'A' . $r2);
            $s1->getStyle('J' . $r2)->getNumberFormat()->setFormatCode($money);
            $s1->getStyle('L' . $r2)->getNumberFormat()->setFormatCode($money);
            if ($r2 % 2 === 1) {
                $s1->getStyle('A' . $r2 . ':O' . $r2)->applyFromArray($evenStyle);
            }
            $r2++;
        }

        if ($r2 > 6) {
            $last = $r2 - 1;
            $s1->setCellValue('A' . $r2, 'Tong cong');
            $s1->setCellValue('K' . $r2, '=SUM(K6:K' . $last . ')');
            $s1->setCellValue('L' . $r2, '=SUM(L6:L' . $last . ')');
            $s1->getStyle('L' . $r2)->getNumberFormat()->setFormatCode($money);
            $s1->getStyle('A' . $r2 . ':O' . $r2)->applyFromArray($sumStyle);
        }
        foreach (range('A', 'O') as $c) {
            $s1->getColumnDimension($c)->setAutoSize(true);
        }

        // ── Sheet 2: Thong ke theo quy (Secondary) ──────────────────────
        $s2 = $spreadsheet->createSheet();
        $s2->setTitle('Thong ke quy');

        $s2->setCellValue('A1', 'BAO CAO THONG KE NHAP KHO & CHI TIEU SACH THU VIEN');
        $s2->mergeCells('A1:C1');
        $s2->getStyle('A1')->applyFromArray($titleStyle);
        $s2->setCellValue('A2', 'Nam bao cao:');
        $s2->setCellValue('B2', $selectedYear);
        $s2->setCellValue('A3', 'Ngay xuat:');
        $s2->setCellValue('B3', date('d/m/Y H:i:s'));
        $s2->setCellValue('A4', 'Tong chi tieu ca nam:');
        $s2->setCellValue('B4', $totalSpendYear);
        $s2->getStyle('B4')->getNumberFormat()->setFormatCode($money . ' "d"');
        $s2->setCellValue('A5', 'Tong phieu nhap:');
        $s2->setCellValue('B5', $totalImportsYear);
        $s2->getStyle('A2:A5')->applyFromArray($boldStyle);

        $s2->fromArray([['Quy', 'So phieu nhap', 'Tong chi tieu (d)']], null, 'A7');
        $s2->getStyle('A7:C7')->applyFromArray($hStyle);
        $s2->freezePane('A8');

        $qNames = [1 => 'Quy I', 2 => 'Quy II', 3 => 'Quy III', 4 => 'Quy IV'];
        $r = 8;
        foreach ($quarterlyStats as $q => $d) {
            $s2->setCellValue('A' . $r, $qNames[$q]);
            $s2->setCellValue('B' . $r, $d['count']);
            $s2->setCellValue('C' . $r, $d['spend']);
            $s2->getStyle('C' . $r)->getNumberFormat()->setFormatCode($money);
            if ($r % 2 === 0) {
                $s2->getStyle('A' . $r . ':C' . $r)->applyFromArray($evenStyle);
            }
            $r++;
        }
        $s2->setCellValue('A' . $r, 'Tong cong');
        $s2->setCellValue('B' . $r, '=SUM(B8:B' . ($r - 1) . ')');
        $s2->setCellValue('C' . $r, '=SUM(C8:C' . ($r - 1) . ')');
        $s2->getStyle('C' . $r)->getNumberFormat()->setFormatCode($money);
        $s2->getStyle('A' . $r . ':C' . $r)->applyFromArray($sumStyle);
        foreach (['A', 'B', 'C'] as $c) {
            $s2->getColumnDimension($c)->setAutoSize(true);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $filename = 'bao-cao-nhap-kho-' . $periodLabel . '-' . $selectedYear . ($type !== '' ? '-' . $type : '') . '.xlsx';

        $response = $this->getResponse();
        $response->getHeaders()->addHeaders([
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
        $response->setContent($content !== false ? $content : '');

        return $response;
    }

    public function addAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $currentUser = $this->currentUser();

        if ($this->getRequest()->isPost()) {
            $data = $this->postData();
            $title = trim((string)($data['title'] ?? ''));
            $author = trim((string)($data['author'] ?? ''));
            $isbn = trim((string)($data['isbn'] ?? ''));
            $category = trim((string)($data['category'] ?? 'Khác'));
            $publisher = trim((string)($data['publisher'] ?? ''));
            $publishedYear = ! empty($data['published_year']) ? (int)$data['published_year'] : null;
            $quantity = min(1000, max(1, (int)($data['quantity'] ?? 1)));
            $price = max(0.0, (float)($data['price'] ?? 0.0));
            $importType = $data['import_type'] ?? 'purchase';
            $invoiceCode = trim((string)($data['invoice_code'] ?? ''));
            $invoiceUrl = trim((string)($data['invoice_url'] ?? ''));
            $note = trim((string)($data['note'] ?? ''));

            if ($title === '') {
                $this->flash()->addErrorMessage('Vui lòng nhập Tên sách.');
            } else {
                try {
                    $generatedCode = $invoiceCode !== '' ? $invoiceCode : 'INV-' . strtoupper(uniqid());

                    $syncData = [
                        'title'          => $title,
                        'isbn'           => $isbn !== '' ? $isbn : null,
                        'quantity'       => $quantity,
                        'author'         => $author !== '' ? $author : 'Khác',
                        'category'       => $category !== '' ? $category : 'Khác',
                        'publisher'      => $publisher !== '' ? $publisher : null,
                        'published_year' => $publishedYear,
                    ];
                    $bookId = $this->syncImportToBooks($syncData);

                    $sql = "INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, invoice_url, price, note, imported_by, status, import_date, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', CURDATE(), NOW(), NOW())";
                    $this->dbAdapter->query($sql, [
                        $bookId,
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

                    $this->flash()->addSuccessMessage('Đã nhập kho sách trực tiếp thành công.');
                } catch (\Throwable $e) {
                    $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
                }
                return $this->redirect()->toRoute('library/books-import');
            }
        }

        return new ViewModel();
    }

    public function downloadTemplateAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Mẫu nhập kho sách')
            ->setCreator('Thư viện');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Nhập kho sách');

        $headers = [
            'Tên sách (Bắt buộc)',
            'Tác giả',
            'ISBN',
            'Thể loại',
            'Nhà xuất bản',
            'Năm xuất bản',
            'Hình thức nhập (purchase/donation/other)',
            'Số lượng nhập (Bắt buộc)',
            'Đơn giá chi phí (đ)',
            'Mã hóa đơn',
            'Đường dẫn hóa đơn/chứng từ',
            'Ghi chú'
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF4F46E5']
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
            ]
        ];
        $sheet->getStyle('A1:L1')->applyFromArray($headerStyle);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $sampleRow = [
            'Lập trình web với PHP Laminas',
            'Nguyễn Văn A',
            '9786049012345',
            'Công nghệ thông tin',
            'NXB Đại học Sư phạm',
            '2024',
            'purchase',
            '10',
            '120000',
            'HD-2024-001',
            'http://example.com/hd-001.pdf',
            'Sách phục vụ môn lập trình web'
        ];
        $sheet->fromArray([$sampleRow], null, 'A2');

        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        $filename = 'mau_nhap_kho_sach.xlsx';

        $response = $this->getResponse();
        $response->getHeaders()->addHeaders([
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'max-age=0',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ]);
        $response->setContent($content !== false ? $content : '');

        return $response;
    }

    public function importExcelAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $currentUser = $this->currentUser();

        if ($this->getRequest()->isPost()) {
            $files = $this->getRequest()->getFiles();
            $file = $files->get('excel_file');

            if (! $file || empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
                $this->flash()->addErrorMessage('Vui lòng chọn một file Excel hợp lệ.');
                return $this->redirect()->toRoute('library/books-import', ['action' => 'add']);
            }

            try {
                $filePath = $file['tmp_name'];
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);

                $successCount = 0;
                $skippedCount = 0;
                $rowNum = 0;

                foreach ($rows as $row) {
                    $rowNum++;
                    if ($rowNum === 1) {
                        continue;
                    }

                    $title = isset($row['A']) ? trim((string)$row['A']) : '';
                    if ($title === '') {
                        $skippedCount++;
                        continue;
                    }

                    $author = isset($row['B']) && trim((string)$row['B']) !== '' ? trim((string)$row['B']) : 'Khác';
                    $isbn = isset($row['C']) && trim((string)$row['C']) !== '' ? trim((string)$row['C']) : null;
                    $category = isset($row['D']) && trim((string)$row['D']) !== '' ? trim((string)$row['D']) : 'Khác';
                    $publisher = isset($row['E']) && trim((string)$row['E']) !== '' ? trim((string)$row['E']) : null;
                    $publishedYear = isset($row['F']) && ! empty($row['F']) ? (int)$row['F'] : null;

                    $importType = isset($row['G']) ? trim(strtolower((string)$row['G'])) : 'purchase';
                    if (! in_array($importType, ['purchase', 'donation', 'other'])) {
                        $importType = 'purchase';
                    }

                    $quantity = isset($row['H']) ? (int)$row['H'] : 1;
                    $quantity = min(1000, max(1, $quantity));

                    $price = isset($row['I']) ? (float)$row['I'] : 0.0;
                    $price = max(0.0, $price);

                    $invoiceCode = isset($row['J']) && trim((string)$row['J']) !== '' ? trim((string)$row['J']) : 'INV-EXCEL-' . strtoupper(uniqid());
                    $invoiceUrl = isset($row['K']) && trim((string)$row['K']) !== '' ? trim((string)$row['K']) : null;
                    $note = isset($row['L']) && trim((string)$row['L']) !== '' ? trim((string)$row['L']) : null;

                    $syncData = [
                        'title'          => $title,
                        'isbn'           => $isbn,
                        'quantity'       => $quantity,
                        'author'         => $author,
                        'category'       => $category,
                        'publisher'      => $publisher,
                        'published_year' => $publishedYear,
                    ];
                    $bookId = $this->syncImportToBooks($syncData);

                    $sql = "INSERT INTO book_imports (book_id, invoice_code, title, author, isbn, category, publisher, published_year, quantity, import_type, invoice_url, price, note, imported_by, status, import_date, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', CURDATE(), NOW(), NOW())";
                    $this->dbAdapter->query($sql, [
                        $bookId,
                        $invoiceCode,
                        $title,
                        $author,
                        $isbn,
                        $category,
                        $publisher,
                        $publishedYear,
                        $quantity,
                        $importType,
                        $invoiceUrl,
                        $price,
                        $note,
                        $currentUser['id']
                    ]);

                    $successCount++;
                }

                $this->flash()->addSuccessMessage("Đã nhập thành công {$successCount} đầu sách từ file Excel. (Bỏ qua {$skippedCount} dòng trống)");
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Lỗi đọc file Excel: ' . $e->getMessage());
            }
        }

        return $this->redirect()->toRoute('library/books-import');
    }

    private function syncImportToBooks(array $import): int
    {
        $title    = $import['title'];
        // Bug 4 fix: use !empty() to handle both null and empty-string ISBN
        $isbn     = ! empty($import['isbn']) ? trim((string)$import['isbn']) : null;
        $quantity = (int)$import['quantity'];

        // Try to find matching book by ISBN first
        $matchingBookId = null;
        if ($isbn !== null) {
            $checkStmt = $this->dbAdapter->query("SELECT book_id FROM books WHERE isbn = ? LIMIT 1");
            $books = iterator_to_array($checkStmt->execute([$isbn]));
            if (count($books) > 0) {
                $matchingBookId = (int)$books[0]['book_id'];
            }
        }

        // Fallback: match by title
        if ($matchingBookId === null) {
            $checkStmt = $this->dbAdapter->query("SELECT book_id FROM books WHERE title = ? LIMIT 1");
            $books = iterator_to_array($checkStmt->execute([$title]));
            if (count($books) > 0) {
                $matchingBookId = (int)$books[0]['book_id'];
            }
        }

        if ($matchingBookId !== null) {
            // Existing book — increment quantity and ensure status = available
            $this->dbAdapter->query(
                "UPDATE books SET quantity = quantity + ?, status = 'available' WHERE book_id = ?",
                [$quantity, $matchingBookId]
            );
        } else {
            // New book — insert into catalog
            $insertSql = "INSERT INTO books (title, author, isbn, category, publisher, published_year, quantity, status, import_date, created_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'available', CURDATE(), NOW())";
            $this->dbAdapter->query($insertSql, [
                $title,
                ! empty($import['author']) ? $import['author'] : 'Khác',
                $isbn,
                ! empty($import['category']) ? $import['category'] : 'Khác',
                ! empty($import['publisher']) ? $import['publisher'] : null,
                ! empty($import['published_year']) ? $import['published_year'] : null,
                $quantity,
            ]);
            $matchingBookId = (int)$this->dbAdapter->getDriver()->getLastGeneratedValue();
        }

        return $matchingBookId;
    }
}
