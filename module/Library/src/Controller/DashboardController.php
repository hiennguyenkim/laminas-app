<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Model\Table\BookTable;
use Library\Model\Table\BorrowTable;
use Library\Model\Table\UserTable;
use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-suppress PossiblyUnusedMethod
 */
class DashboardController extends BaseController
{
    private BookTable $bookTable;
    private BorrowTable $borrowTable;
    private UserTable $userTable;
    private \Library\Model\Table\PublicChatTable $publicChatTable;
    private \Library\Service\GeminiService $geminiService;

    public function __construct(
        AuthSessionContainer $authSessionContainer,
        BookTable $bookTable,
        BorrowTable $borrowTable,
        UserTable $userTable,
        \Library\Model\Table\PublicChatTable $publicChatTable,
        \Library\Service\GeminiService $geminiService
    ) {
        parent::__construct($authSessionContainer);
        $this->bookTable   = $bookTable;
        $this->borrowTable = $borrowTable;
        $this->userTable   = $userTable;
        $this->publicChatTable = $publicChatTable;
        $this->geminiService = $geminiService;
    }

    /**
     * @psalm-suppress InvalidReturnType
     */
    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $isAdmin     = $this->isAdmin();
        $bookSummary = $this->bookTable->getSummary();
        $loanSummary = $this->borrowTable->getSummary($isAdmin ? null : $userId);

        $isLocked = false;
        $lockReason = '';
        $lockedAt = '';
        if (! $isAdmin && $userId > 0) {
            try {
                $userObj = $this->userTable->getUser($userId);
                $isLocked = $userObj->isLocked();
                $lockReason = $userObj->lockReason;
                $lockedAt = $userObj->lockedAt;
            } catch (\Throwable $e) {}
        }

        $categoryMonthlyStats = [];
        $inventoryStatus = [];
        $borrowingCategoryStats = [];
        if ($isAdmin) {
            $categoryMonthlyStats = $this->borrowTable->getCategoryMonthlyStats((int) date('Y'));
            $inventoryStatus = $this->bookTable->getInventoryStatus();
            $borrowingCategoryStats = $this->borrowTable->getCurrentlyBorrowedCategoryStats();
        }

        $recommendedBooks = [];
        $unpaidFinesCount = 0;
        $unpaidFinesSum = 0.0;
        $categoryStats = [];
        if (!$isAdmin && $userId > 0) {
            $categoryStats = $this->bookTable->getCategoryStats($userId);
            $userCats = $categoryStats;
            arsort($userCats);
            $topCategory = !empty($userCats) ? (string)key($userCats) : '';
            
            if ($topCategory !== '') {
                $recommendedBooks = $this->bookTable->getRecommendationsForUser($userId, $topCategory, 3);
            }
            if (count($recommendedBooks) < 3) {
                $additionalBooks = $this->bookTable->getRecommendationsForUser($userId, null, 3 - count($recommendedBooks));
                $recommendedBooks = array_merge($recommendedBooks, $additionalBooks);
            }

            try {
                $sqlFines = "SELECT COUNT(*) AS cnt, SUM(amount) AS total FROM fines WHERE user_id = ? AND status = 'unpaid'";
                $db = $this->userTable->getAdapter();
                $fineRow = $db->query($sqlFines)->execute([$userId])->current();
                $unpaidFinesCount = (int)($fineRow['cnt'] ?? 0);
                $unpaidFinesSum = (float)($fineRow['total'] ?? 0);
            } catch (\Throwable $e) {}
        } else {
            $categoryStats = $this->bookTable->getCategoryStats(null);
        }

        $viewModel = new ViewModel([
            'isAdmin'              => $isAdmin,
            'currentUser'          => $currentUser,
            'bookSummary'          => $bookSummary,
            'loanSummary'          => $loanSummary,
            'recommendedBooks'     => $recommendedBooks,
            'unpaidFinesCount'     => $unpaidFinesCount,
            'unpaidFinesSum'       => $unpaidFinesSum,
            'totalCategories'      => $this->bookTable->countCategories(),
            'totalTitles'          => $bookSummary['total_titles'] ?? 0,
            'totalCopies'          => $bookSummary['total_copies'] ?? 0,
            'totalBorrowed'        => $loanSummary['borrowed'] ?? 0,
            'totalOverdue'         => $loanSummary['overdue'] ?? 0,
            'totalReturned'        => $loanSummary['returned'] ?? 0,
            'dueSoon'              => $loanSummary['due_soon'] ?? 0,
            'totalMembers'         => $isAdmin ? $this->userTable->countByRole('student') : 0,
            'recentBorrows'        => $this->borrowTable->fetchAllWithDetails([], $isAdmin ? null : $userId, 6),
            'monthlyStats'         => $this->borrowTable->getMonthlyStats((int) date('Y'), $isAdmin ? null : $userId),
            'categoryStats'        => $categoryStats,
            'categoryMonthlyStats' => $categoryMonthlyStats,
            'inventoryStatus'      => $inventoryStatus,
            'borrowingCategoryStats' => $borrowingCategoryStats,
            'isLocked'             => $isLocked,
            'lockReason'           => $lockReason,
            'lockedAt'             => $lockedAt,
            'trendingBooks'        => $this->bookTable->getTrendingBooks(5),
            'topReaders'           => $this->borrowTable->getTopReaders(5, 'month'),
        ]);

        if ($isAdmin) {
            $viewModel->setTemplate('library/dashboard/index-admin');
        } else {
            $viewModel->setTemplate('library/dashboard/index');
        }

        return $viewModel;
    }

    public function chatAction(): Response
    {
        $currentUser = $this->currentUser();

        if ($this->getRequest()->isPost()) {
            if (!$currentUser) {
                return $this->jsonResponse(['error' => 'Unauthorized'], 401);
            }
            
            $userId = (int)($currentUser['id'] ?? 0);
            try {
                $userObj = $this->userTable->getUser($userId);
                if ($userObj->isPermanentlyLocked()) {
                    return $this->jsonResponse(['error' => 'Tài khoản của bạn đã bị khóa vĩnh viễn và bị chặn tính năng thảo luận.'], 403);
                }
            } catch (\Throwable $e) {
                // Fallback
            }

            $data = $this->postData();
            $action = trim((string)($data['action'] ?? ''));

            if ($action === 'delete') {
                $messageId = (int)($data['id'] ?? 0);
                if ($messageId <= 0) {
                    return $this->jsonResponse(['error' => 'Invalid message ID'], 400);
                }
                $isAdmin = ($currentUser['role'] ?? '') === 'admin';
                $userId = (int)$currentUser['id'];

                if ($isAdmin) {
                    $this->publicChatTable->deleteMessage($messageId);
                } else {
                    $this->publicChatTable->deleteUserMessage($messageId, $userId);
                }
                return $this->jsonResponse(['success' => true]);
            }

            if ($action === 'pin') {
                $messageId = (int)($data['id'] ?? 0);
                if ($messageId <= 0) {
                    return $this->jsonResponse(['error' => 'Invalid message ID'], 400);
                }
                $isAdmin = ($currentUser['role'] ?? '') === 'admin';
                if (!$isAdmin) {
                    return $this->jsonResponse(['error' => 'Forbidden'], 403);
                }

                // Unpin everything first
                $this->publicChatTable->unpinAll();
                // Pin the target message
                $this->publicChatTable->pinMessage($messageId);

                return $this->jsonResponse(['success' => true]);
            }

            if ($action === 'unpin') {
                $isAdmin = ($currentUser['role'] ?? '') === 'admin';
                if (!$isAdmin) {
                    return $this->jsonResponse(['error' => 'Forbidden'], 403);
                }

                // Unpin everything
                $this->publicChatTable->unpinAll();

                return $this->jsonResponse(['success' => true]);
            }

            if ($action === 'react') {
                $messageId = (int)($data['id'] ?? 0);
                $emoji = trim((string)($data['emoji'] ?? ''));
                if ($messageId <= 0 || $emoji === '') {
                    return $this->jsonResponse(['error' => 'Invalid parameters'], 400);
                }

                // Fetch message
                $reactionsStr = $this->publicChatTable->getReactions($messageId);
                if ($reactionsStr === null) {
                    return $this->jsonResponse(['error' => 'Message not found'], 404);
                }

                $reactions = [];
                if ($reactionsStr !== '') {
                    $reactions = json_decode($reactionsStr, true) ?? [];
                }

                $userId = (int)$currentUser['id'];

                // Toggle logic
                if (!isset($reactions[$emoji])) {
                    $reactions[$emoji] = [];
                }

                $userIndex = array_search($userId, $reactions[$emoji]);
                if ($userIndex !== false) {
                    // User already reacted with this emoji, remove it
                    unset($reactions[$emoji][$userIndex]);
                    $reactions[$emoji] = array_values($reactions[$emoji]); // reindex
                    if (empty($reactions[$emoji])) {
                        unset($reactions[$emoji]);
                    }
                } else {
                    // Add user reaction
                    $reactions[$emoji][] = $userId;
                }

                $newReactionsStr = json_encode($reactions, JSON_UNESCAPED_UNICODE);
                $this->publicChatTable->updateReactions($messageId, $newReactionsStr);

                return $this->jsonResponse(['success' => true]);
            }

            // Normal chat insert action
            $message = trim((string)($data['message'] ?? ''));
            if ($message === '') {
                return $this->jsonResponse(['error' => 'Message cannot be empty'], 400);
            }
            if (mb_strlen($message) > 255) {
                return $this->jsonResponse(['error' => 'Message is too long'], 400);
            }

            // Gemini Moderation
            if (!$this->geminiService->checkContent($message)) {
                return $this->jsonResponse(['error' => 'Tin nhắn chứa nội dung không phù hợp và đã bị chặn.'], 400);
            }

            $userId = (int)$currentUser['id'];
            $this->publicChatTable->insertMessage($userId, $message);

            return $this->jsonResponse(['success' => true]);
        }

        // Fetch pinned message if any
        $pinnedResult = $this->publicChatTable->getPinnedMessage();
        $formattedPinned = null;
        if ($pinnedResult) {
            $timestamp = strtotime($pinnedResult['created_at']);
            $dateLabel = '';
            if (date('Y-m-d', $timestamp) !== date('Y-m-d')) {
                $dateLabel = ' ' . date('d/m', $timestamp);
            }
            $formattedPinned = [
                'id'         => $pinnedResult['id'],
                'user_id'    => $pinnedResult['user_id'],
                'nickname'   => $pinnedResult['nickname'],
                'message'    => $pinnedResult['message'],
                'role'       => $pinnedResult['role'] ?? 'student',
                'avatar_url' => $pinnedResult['avatar_url'] ?? '',
                'is_pinned'  => (int)($pinnedResult['is_pinned'] ?? 0),
                'reactions'  => $pinnedResult['reactions'] ?? '',
                'created_at' => date('H:i', $timestamp),
                'date_label' => $dateLabel,
            ];
        }

        // Fetch last 50 messages joining with users to get nickname securely
        $beforeId = (int)$this->queryString('before_id', '0');
        if ($beforeId > 0) {
            $results = $this->publicChatTable->fetchMessagesBefore($beforeId, 50);
        } else {
            $results = $this->publicChatTable->fetchRecentMessages(50);
        }

        // Format for display
        $formattedResults = array_map(function($row) {
            $timestamp = strtotime($row['created_at']);
            $dateLabel = '';
            if (date('Y-m-d', $timestamp) !== date('Y-m-d')) {
                $dateLabel = ' ' . date('d/m', $timestamp);
            }
            return [
                'id'         => $row['id'],
                'user_id'    => $row['user_id'],
                'nickname'   => $row['nickname'],
                'message'    => $row['message'],
                'role'       => $row['role'] ?? 'student',
                'avatar_url' => $row['avatar_url'] ?? '',
                'is_pinned'  => (int)($row['is_pinned'] ?? 0),
                'reactions'  => $row['reactions'] ?? '',
                'created_at' => date('H:i', $timestamp),
                'date_label' => $dateLabel,
            ];
        }, $results);

        return $this->jsonResponse([
            'messages' => $formattedResults,
            'pinned'   => $formattedPinned
        ]);
    }

    public function statsAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $isAdmin     = $this->isAdmin();
        $year        = (int)$this->params()->fromQuery('year', date('Y'));

        $stats = $this->borrowTable->getMonthlyStats($year, $isAdmin ? null : $userId);
        return $this->jsonResponse($stats);
    }

    public function categoryStatsAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $isAdmin = $this->isAdmin();
        if (!$isAdmin) {
            return $this->jsonResponse(['error' => 'Access denied'], 403);
        }

        $year = (int)$this->params()->fromQuery('year', date('Y'));
        $stats = $this->borrowTable->getCategoryMonthlyStats($year);
        return $this->jsonResponse($stats);
    }

    public function exportStatsAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $isAdmin     = $this->isAdmin();
        $year        = (int)$this->params()->fromQuery('year', date('Y'));
        $quarter     = $this->params()->fromQuery('quarter', 'all');

        $stats = $this->borrowTable->getMonthlyStats($year, $isAdmin ? null : $userId);

        $borrowData = $stats['borrow'] ?? array_fill(0, 12, 0);
        $returnData = $stats['return'] ?? array_fill(0, 12, 0);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Thống kê mượn trả');

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];
        $evenRowStyle = [
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0F4FA']],
        ];

        $sheet->fromArray([['Năm', 'Tháng', 'Lượt mượn', 'Lượt trả']], null, 'A1');
        $sheet->getStyle('A1:D1')->applyFromArray($headerStyle);
        $sheet->setAutoFilter('A1:D1');
        $sheet->freezePane('A2');

        $row = 2;
        for ($i = 0; $i < 12; $i++) {
            $monthNum = $i + 1;
            if ($quarter !== 'all') {
                $q          = (int)$quarter;
                $startMonth = ($q - 1) * 3 + 1;
                $endMonth   = $startMonth + 2;
                if ($monthNum < $startMonth || $monthNum > $endMonth) {
                    continue;
                }
            }
            $sheet->setCellValue('A' . $row, $year);
            $sheet->setCellValue('B' . $row, 'Tháng ' . $monthNum);
            $sheet->setCellValue('C' . $row, $borrowData[$i] ?? 0);
            $sheet->setCellValue('D' . $row, $returnData[$i] ?? 0);
            if ($row % 2 === 0) {
                $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray($evenRowStyle);
            }
            $row++;
        }

        // Sum row
        if ($row > 2) {
            $lastDataRow = $row - 1;
            $sheet->setCellValue('A' . $row, 'Tổng cộng');
            $sheet->setCellValue('C' . $row, '=SUM(C2:C' . $lastDataRow . ')');
            $sheet->setCellValue('D' . $row, '=SUM(D2:D' . $lastDataRow . ')');
            $sheet->getStyle('A' . $row . ':D' . $row)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCE6F1']],
            ]);
            $sheet->mergeCells('A' . $row . ':B' . $row);
        }

        foreach (['A', 'B', 'C', 'D'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('C2:D' . $row)->getNumberFormat()->setFormatCode('#,##0');

        $filename = sprintf('thong-ke-muon-tra-%s%s.xlsx', $year, $quarter !== 'all' ? '-quy-' . $quarter : '');
        return $this->buildXlsxResponse($filename, $spreadsheet);
    }

    public function exportCategoryMonthlyStatsAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $isAdmin = $this->isAdmin();
        if (!$isAdmin) {
            $response = $this->getResponse();
            $response->setStatusCode(403);
            $response->setContent('Access denied');
            return $response;
        }

        $year  = (int)$this->params()->fromQuery('year', date('Y'));
        $month = $this->params()->fromQuery('month', 'year');

        $stats = $this->borrowTable->getCategoryMonthlyStats($year);

        // Compute items and total count for percentage calculation
        $items    = [];
        $totalAll = 0;
        foreach ($stats as $catName => $monthlyCounts) {
            $count = ($month === 'year')
                ? array_sum($monthlyCounts)
                : ($monthlyCounts[(int)$month - 1] ?? 0);
            if ($count > 0) {
                $items[$catName] = $count;
                $totalAll       += $count;
            }
        }
        arsort($items);
        $totalAllForPct = $totalAll ?: 1;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle($month === 'year' ? 'Năm ' . $year : 'Tháng ' . $month . '/' . $year);

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];
        $evenRowStyle = [
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0F4FA']],
        ];

        if ($month === 'year') {
            $headers = [
                'Thể loại',
                'Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4',
                'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8',
                'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12',
                'Tổng lượt mượn (Năm ' . $year . ')',
                'Tỷ lệ (%)',
            ];
            $lastCol  = 'O';
            $dataCols = range('B', 'N');

            $sheet->fromArray([$headers], null, 'A1');
            $sheet->getStyle('A1:' . $lastCol . '1')->applyFromArray($headerStyle);
            $sheet->setAutoFilter('A1:' . $lastCol . '1');
            $sheet->freezePane('A2');

            $row = 2;
            foreach ($items as $catName => $totalCount) {
                $monthlyCounts = $stats[$catName] ?? array_fill(0, 12, 0);
                $rowData = [$catName];
                for ($i = 0; $i < 12; $i++) {
                    $rowData[] = $monthlyCounts[$i] ?? 0;
                }
                $rowData[] = $totalCount;
                $rowData[] = round(($totalCount / $totalAllForPct) * 100) . '%';
                $sheet->fromArray([$rowData], null, 'A' . $row);
                if ($row % 2 === 0) {
                    $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray($evenRowStyle);
                }
                $row++;
            }

            foreach (array_merge(['A'], $dataCols, [$lastCol]) as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            // Number format for monthly count columns B-N
            $sheet->getStyle('B2:N' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
        } else {
            $headers = [
                'Thể loại',
                'Số lượt mượn (Tháng ' . $month . '/' . $year . ')',
                'Tỷ lệ (%)',
            ];

            $sheet->fromArray([$headers], null, 'A1');
            $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);
            $sheet->setAutoFilter('A1:C1');
            $sheet->freezePane('A2');

            $row = 2;
            foreach ($items as $catName => $count) {
                $sheet->setCellValue('A' . $row, $catName);
                $sheet->setCellValue('B' . $row, $count);
                $sheet->setCellValue('C' . $row, round(($count / $totalAllForPct) * 100) . '%');
                if ($row % 2 === 0) {
                    $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($evenRowStyle);
                }
                $row++;
            }

            foreach (['A', 'B', 'C'] as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }
            $sheet->getStyle('B2:B' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');
        }

        $filename = sprintf('the-loai-sach-duoc-muon-%s%s.xlsx', $year, $month !== 'year' ? '-thang-' . $month : '');
        return $this->buildXlsxResponse($filename, $spreadsheet);
    }

    public function exportCategoryStatsAction(): Response
    {
        if ($response = $this->requireLogin()) {
            return $response;
        }

        $currentUser = $this->currentUser() ?? [];
        $userId      = $currentUser['id'] ?? 0;
        $isAdmin     = $this->isAdmin();

        $stats = $this->bookTable->getCategoryStats($isAdmin ? null : $userId);

        // Sort descending
        arsort($stats);

        $totalAll       = array_sum($stats);
        $totalAllForPct = $totalAll ?: 1;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle($isAdmin ? 'Phân bổ thể loại' : 'Thể loại đã mượn');

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFB0BEC5']]],
        ];
        $evenRowStyle = [
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF0F4FA']],
        ];

        $headers = ['Thể loại', $isAdmin ? 'Số lượng sách' : 'Số lượt mượn', 'Tỷ lệ (%)'];
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:C1')->applyFromArray($headerStyle);
        $sheet->setAutoFilter('A1:C1');
        $sheet->freezePane('A2');

        $row = 2;
        foreach ($stats as $catName => $count) {
            $sheet->setCellValue('A' . $row, $catName);
            $sheet->setCellValue('B' . $row, $count);
            $sheet->setCellValue('C' . $row, round(($count / $totalAllForPct) * 100) . '%');
            if ($row % 2 === 0) {
                $sheet->getStyle('A' . $row . ':C' . $row)->applyFromArray($evenRowStyle);
            }
            $row++;
        }

        foreach (['A', 'B', 'C'] as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getStyle('B2:B' . ($row - 1))->getNumberFormat()->setFormatCode('#,##0');

        $filename = $isAdmin ? 'phan-bo-the-loai-sach.xlsx' : 'the-loai-sach-da-muon.xlsx';
        return $this->buildXlsxResponse($filename, $spreadsheet);
    }

    public function borrowedAction(): Response
    {
        return $this->redirect()->toRoute($this->routeForRole('transaction'), [], ['query' => ['status' => 'borrowed']]);
    }

    public function overdueAction(): Response
    {
        return $this->redirect()->toRoute($this->routeForRole('transaction'), [], ['query' => ['status' => 'overdue']]);
    }

    private function buildXlsxResponse(string $filename, \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): Response
    {
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

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

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        $response = $this->getResponse();
        if (! $response instanceof Response) {
            throw new \RuntimeException('Unexpected response instance.');
        }

        $response->setStatusCode($statusCode);
        $response->setContent((string) json_encode($data, JSON_UNESCAPED_UNICODE));
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        return $response;
    }
}
