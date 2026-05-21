<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;

class SettingsController extends BaseController
{
    public function __construct(
        AuthSessionContainer $authSessionContainer,
        private AdapterInterface $dbAdapter
    ) {
        parent::__construct($authSessionContainer);
    }

    // ── Main settings page ────────────────────────────────────────────
    public function indexAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $page    = max(1, (int)($this->params()->fromQuery('page', 1)));
        $perPage = 5;

        $search = trim($this->queryString('search'));
        $type   = trim($this->queryString('type'));

        $filters = [
            'search' => $search,
            'type'   => $type,
        ];

        // Global stats (all announcements in DB)
        $globalCounts = $this->dbAdapter->query(
            "SELECT 
                COUNT(*) AS total_count,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
             FROM announcements"
        )->execute()->current();
        
        $globalTotal = (int)($globalCounts['total_count'] ?? 0);
        $activeCount = (int)($globalCounts['active_count'] ?? 0);
        $hiddenCount = $globalTotal - $activeCount;

        // Build filtering query
        $where = [];
        $params = [];

        // Type filter
        $allowedTypes = ['event', 'contest', 'holiday', 'general'];
        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $where[] = "a.type = ?";
            $params[] = $type;
        }

        // Search filter
        if ($search !== '') {
            $where[] = "(a.title LIKE ? OR a.content LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

        // Filtered counts
        $countSql = "SELECT COUNT(*) as cnt FROM announcements a $whereClause";
        $totalCount = (int)(($this->dbAdapter->query($countSql)->execute($params)->current()['cnt']) ?? 0);

        $totalPages  = max(1, (int)ceil($totalCount / $perPage));
        $page        = min($page, $totalPages);
        $offset      = ($page - 1) * $perPage;

        $announcements = iterator_to_array(
            $this->dbAdapter->query(
                "SELECT a.*, u.full_name AS creator_name
                 FROM announcements a
                 LEFT JOIN users u ON a.created_by = u.user_id
                 $whereClause
                 ORDER BY a.created_at DESC
                 LIMIT ? OFFSET ?"
            )->execute(array_merge($params, [$perPage, $offset]))
        );

        // Get counts for each type matching the current search keyword
        $allCountWhere = [];
        $allCountParams = [];
        if ($search !== '') {
            $allCountWhere[] = "(title LIKE ? OR content LIKE ?)";
            $searchTerm = '%' . $search . '%';
            $allCountParams[] = $searchTerm;
            $allCountParams[] = $searchTerm;
        }
        $allCountWhereClause = !empty($allCountWhere) ? "WHERE " . implode(" AND ", $allCountWhere) : "";

        $totalFilteredCount = (int)(($this->dbAdapter->query("SELECT COUNT(*) as cnt FROM announcements $allCountWhereClause")->execute($allCountParams)->current()['cnt']) ?? 0);

        $typeCountsRaw = iterator_to_array($this->dbAdapter->query("SELECT type, COUNT(*) as cnt FROM announcements $allCountWhereClause GROUP BY type")->execute($allCountParams));
        $typeCounts = [
            'general' => 0,
            'event'   => 0,
            'contest' => 0,
            'holiday' => 0,
        ];
        foreach ($typeCountsRaw as $row) {
            if (isset($typeCounts[$row['type']])) {
                $typeCounts[$row['type']] = (int)$row['cnt'];
            }
        }

        // Detect current logo (uploaded file takes priority)
        $logoFile = null;
        $uploadDir = 'public/img/uploads/';
        foreach (['logo.png', 'logo.jpg', 'logo.jpeg', 'logo.svg', 'logo.webp'] as $f) {
            if (file_exists($uploadDir . $f)) {
                $logoFile = $f;
                break;
            }
        }

        return new ViewModel([
            'announcements'      => $announcements,
            'logoFile'           => $logoFile,
            'activeCount'        => $activeCount,
            'hiddenCount'        => $hiddenCount,
            'page'               => $page,
            'totalPages'         => $totalPages,
            'totalCount'         => $totalCount,
            'perPage'            => $perPage,
            'filters'            => $filters,
            'typeCounts'         => $typeCounts,
            'totalFilteredCount' => $totalFilteredCount,
        ]);
    }

    // ── Upload logo ───────────────────────────────────────────────────
    public function logoAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('library/settings');
        }

        $uploadDir = getcwd() . '/public/img/uploads/';
        $file = $_FILES['logo'] ?? null;

        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flash()->addErrorMessage('Không nhận được file upload. Vui lòng thử lại.');
            return $this->redirect()->toRoute('library/settings');
        }

        $maxSize = 2 * 1024 * 1024; // 2 MB
        if ($file['size'] > $maxSize) {
            $this->flash()->addErrorMessage('File quá lớn. Tối đa 2MB.');
            return $this->redirect()->toRoute('library/settings');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
        if (!in_array($ext, $allowed)) {
            $this->flash()->addErrorMessage('Định dạng không hỗ trợ. Chỉ chấp nhận: ' . implode(', ', $allowed));
            return $this->redirect()->toRoute('library/settings');
        }

        // Remove old logo files
        foreach ($allowed as $oldExt) {
            $oldFile = $uploadDir . 'logo.' . $oldExt;
            if (file_exists($oldFile)) {
                unlink($oldFile);
            }
        }

        $destPath = $uploadDir . 'logo.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->flash()->addErrorMessage('Lỗi khi lưu file. Kiểm tra quyền thư mục public/img/uploads/.');
            return $this->redirect()->toRoute('library/settings');
        }

        $this->flash()->addSuccessMessage('Đã cập nhật logo thư viện thành công.');
        return $this->redirect()->toRoute('library/settings');
    }

    // ── Create announcement ───────────────────────────────────────────
    public function announcementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('library/settings');
        }

        $currentUser = $this->currentUser();
        $data = $this->postData();

        $title     = trim((string)($data['title'] ?? ''));
        $content   = trim((string)($data['content'] ?? ''));
        $type      = $data['type'] ?? 'general';
        $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
        $endDate   = !empty($data['end_date'])   ? $data['end_date']   : null;
        $isActive  = isset($data['is_active']) ? 1 : 0;

        $allowedTypes = ['event', 'contest', 'holiday', 'general'];
        if (!in_array($type, $allowedTypes)) {
            $type = 'general';
        }

        if ($title === '' || $content === '') {
            $this->flash()->addErrorMessage('Tiêu đề và nội dung bản tin không được để trống.');
            return $this->redirect()->toRoute('library/settings');
        }

        try {
            $this->dbAdapter->query(
                "INSERT INTO announcements (title, content, type, start_date, end_date, is_active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$title, $content, $type, $startDate, $endDate, $isActive, $currentUser['id']]
            );
            $this->flash()->addSuccessMessage('Đã đăng bản tin "' . htmlspecialchars($title) . '" thành công.');
        } catch (\Throwable $e) {
            $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('library/settings');
    }

    // ── Toggle active/inactive announcement ───────────────────────────
    public function toggleAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->dbAdapter->query(
                "UPDATE announcements SET is_active = NOT is_active WHERE id = ?",
                [$id]
            );
            $this->flash()->addSuccessMessage('Đã cập nhật trạng thái bản tin.');
        }

        return $this->redirect()->toRoute('library/settings');
    }

    // ── Delete announcement ───────────────────────────────────────────
    public function deleteAnnouncementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $this->dbAdapter->query("DELETE FROM announcements WHERE id = ?", [$id]);
            $this->flash()->addSuccessMessage('Đã xóa bản tin.');
        }

        return $this->redirect()->toRoute('library/settings');
    }
}
