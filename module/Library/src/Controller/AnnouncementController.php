<?php

declare(strict_types=1);

namespace Library\Controller;

use Library\Session\AuthSessionContainer;
use Laminas\Http\Response;
use Laminas\View\Model\ViewModel;
use Laminas\Db\Adapter\AdapterInterface;

class AnnouncementController extends BaseController
{
    private AdapterInterface $dbAdapter;

    public function __construct(
        AuthSessionContainer $authSessionContainer,
        AdapterInterface $dbAdapter
    ) {
        parent::__construct($authSessionContainer);
        $this->dbAdapter = $dbAdapter;
    }

    public function announcementsAction(): ViewModel|Response
    {
        $currentUser = $this->currentUser();
        $isAdmin = $currentUser !== null && ($currentUser['role'] ?? '') === 'admin';
        $isStudent = $currentUser !== null && ($currentUser['role'] ?? '') === 'student';

        $routeMatch = $this->getEvent()->getRouteMatch();
        $matchedRouteName = $routeMatch ? $routeMatch->getMatchedRouteName() : '';

        if ($isAdmin && $matchedRouteName !== 'library/announcements') {
            return $this->redirect()->toRoute('library/announcements');
        }
        if ($isStudent && $matchedRouteName !== 'student/announcements') {
            return $this->redirect()->toRoute('student/announcements');
        }
        if ($currentUser === null && $matchedRouteName !== 'announcements') {
            return $this->redirect()->toRoute('announcements');
        }

        $baseRoute = $matchedRouteName;
        $routePrefix = $isAdmin ? 'library' : 'student';

        if ($currentUser === null) {
            $layout = $this->layout();
            if (method_exists($layout, 'setVariable')) {
                $layout->setVariable('guestCatalogMode', true);
            }
        }

        if ($isAdmin) {
            $page    = max(1, (int)($this->params()->fromQuery('page', 1)));
            $perPage = 5;

            $search = trim($this->queryString('search'));
            $type   = trim($this->queryString('type'));
            $status = trim($this->queryString('status'));

            $filters = [
                'search' => $search,
                'type'   => $type,
                'status' => $status,
            ];

            // Global stats
            $globalCounts = $this->dbAdapter->query(
                "SELECT 
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN start_date IS NOT NULL AND start_date > CURDATE() THEN 1 ELSE 0 END) AS upcoming_count,
                    SUM(CASE WHEN end_date IS NOT NULL AND end_date < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
                    SUM(CASE WHEN is_active = 0 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE()) THEN 1 ELSE 0 END) AS hidden_count
                 FROM announcements"
            )->execute()->current();
            
            $activeCount = (int)($globalCounts['active_count'] ?? 0);
            $upcomingCount = (int)($globalCounts['upcoming_count'] ?? 0);
            $expiredCount = (int)($globalCounts['expired_count'] ?? 0);
            $hiddenCount = (int)($globalCounts['hidden_count'] ?? 0);

            $where = [];
            $params = [];

            // Type filter
            $allowedTypes = ['event', 'contest', 'holiday', 'general'];
            if ($type !== '' && in_array($type, $allowedTypes, true)) {
                $where[] = "a.type = ?";
                $params[] = $type;
            }

            // Status filter
            if ($status === 'active') {
                $where[] = "a.is_active = 1 AND (a.start_date IS NULL OR a.start_date <= CURDATE()) AND (a.end_date IS NULL OR a.end_date >= CURDATE())";
            } elseif ($status === 'upcoming') {
                $where[] = "a.start_date IS NOT NULL AND a.start_date > CURDATE()";
            } elseif ($status === 'expired') {
                $where[] = "a.end_date IS NOT NULL AND a.end_date < CURDATE()";
            } elseif ($status === 'hidden') {
                $where[] = "a.is_active = 0 AND (a.start_date IS NULL OR a.start_date <= CURDATE()) AND (a.end_date IS NULL OR a.end_date >= CURDATE())";
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

            $announcements = [];
            if ($totalCount > 0) {
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
            }

            // Get counts for each type matching current search keyword and status filter
            $allCountWhere = [];
            $allCountParams = [];
            if ($search !== '') {
                $allCountWhere[] = "(title LIKE ? OR content LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $allCountParams[] = $searchTerm;
                $allCountParams[] = $searchTerm;
            }
            if ($status === 'active') {
                $allCountWhere[] = "is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())";
            } elseif ($status === 'upcoming') {
                $allCountWhere[] = "start_date IS NOT NULL AND start_date > CURDATE()";
            } elseif ($status === 'expired') {
                $allCountWhere[] = "end_date IS NOT NULL AND end_date < CURDATE()";
            } elseif ($status === 'hidden') {
                $allCountWhere[] = "is_active = 0 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())";
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

            $viewModel = new ViewModel([
                'announcements'      => $announcements,
                'activeCount'        => $activeCount,
                'upcomingCount'      => $upcomingCount,
                'expiredCount'       => $expiredCount,
                'hiddenCount'        => $hiddenCount,
                'page'               => $page,
                'totalPages'         => $totalPages,
                'totalCount'         => $totalCount,
                'perPage'            => $perPage,
                'filters'            => $filters,
                'typeCounts'         => $typeCounts,
                'totalFilteredCount' => $totalFilteredCount,
                'currentUser'        => $currentUser,
                'baseRoute'          => $baseRoute,
            ]);
            $viewModel->setTemplate('library/announcement/announcements');
            return $viewModel;
        }

        $typeFilter = $this->queryString('type', 'all');
        $searchQuery = trim((string)$this->queryString('search', ''));
        if ($searchQuery === '') {
            $searchQuery = trim((string)$this->queryString('q', ''));
        }

        $announcements = [];
        if ($this->dbAdapter) {
            try {
                $sql = 'SELECT * FROM announcements WHERE is_active = 1 AND (start_date IS NULL OR start_date <= CURDATE()) AND (end_date IS NULL OR end_date >= CURDATE())';
                $params = [];

                if ($typeFilter !== 'all') {
                    $sql .= ' AND type = ?';
                    $params[] = $typeFilter;
                }

                if ($searchQuery !== '') {
                    $sql .= ' AND (title LIKE ? OR content LIKE ?)';
                    $params[] = '%' . $searchQuery . '%';
                    $params[] = '%' . $searchQuery . '%';
                }

                $sql .= ' ORDER BY created_at DESC';

                $statement = $this->dbAdapter->query($sql);
                $result = $statement->execute($params);
                $announcements = iterator_to_array($result);
            } catch (\Exception $e) {
                // ignore
            }
        }

        return new ViewModel([
            'announcements' => $announcements,
            'filters' => [
                'type' => $typeFilter,
                'search' => $searchQuery,
            ],
            'currentUser' => $currentUser,
            'baseRoute' => $baseRoute,
        ]);
    }

    public function addAnnouncementAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        if ($this->getRequest()->isPost()) {
            $currentUser = $this->currentUser();
            $data = $this->postData();

            $title     = trim((string)($data['title'] ?? ''));
            $content   = trim((string)($data['content'] ?? ''));
            $type      = $data['type'] ?? 'general';
            $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
            $endDate   = !empty($data['end_date'])   ? $data['end_date']   : null;
            $isActive  = isset($data['is_active']) ? 1 : 0;

            if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày bắt đầu (Từ ngày).');
                return $this->redirect()->toRoute('library/announcements/add');
            }

            if ($endDate && strtotime($endDate) < strtotime(date('Y-m-d'))) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày hiện tại.');
                return $this->redirect()->toRoute('library/announcements/add');
            }

            $allowedTypes = ['event', 'contest', 'holiday', 'general'];
            if (!in_array($type, $allowedTypes)) {
                $type = 'general';
            }

            if ($title === '' || $content === '') {
                $this->flash()->addErrorMessage('Tiêu đề và nội dung bản tin không được để trống.');
                return $this->redirect()->toRoute('library/announcements/add');
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

            return $this->redirect()->toRoute('library/announcements');
        }

        return new ViewModel([
            'currentUser' => $this->currentUser(),
        ]);
    }

    public function editAnnouncementAction(): Response|ViewModel
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id <= 0) {
            $this->flash()->addErrorMessage('Không tìm thấy ID bản tin.');
            return $this->redirect()->toRoute('library/announcements');
        }

        $stmt = $this->dbAdapter->query("SELECT * FROM announcements WHERE id = ? LIMIT 1");
        $res = iterator_to_array($stmt->execute([$id]));
        if (count($res) === 0) {
            $this->flash()->addErrorMessage('Bản tin không tồn tại.');
            return $this->redirect()->toRoute('library/announcements');
        }
        $ann = $res[0];

        if ($this->getRequest()->isPost()) {
            $data = $this->postData();

            $title     = trim((string)($data['title'] ?? ''));
            $content   = trim((string)($data['content'] ?? ''));
            $type      = $data['type'] ?? 'general';
            $startDate = !empty($data['start_date']) ? $data['start_date'] : null;
            $endDate   = !empty($data['end_date'])   ? $data['end_date']   : null;
            $isActive  = isset($data['is_active']) ? 1 : 0;

            if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày bắt đầu (Từ ngày).');
                return $this->redirect()->toRoute('library/announcements/edit', ['id' => $id]);
            }

            if ($endDate && strtotime($endDate) < strtotime(date('Y-m-d'))) {
                $this->flash()->addErrorMessage('Ngày kết thúc (Đến ngày) không được nhỏ hơn ngày hiện tại.');
                return $this->redirect()->toRoute('library/announcements/edit', ['id' => $id]);
            }

            $allowedTypes = ['event', 'contest', 'holiday', 'general'];
            if (!in_array($type, $allowedTypes)) {
                $type = 'general';
            }

            if ($title === '' || $content === '') {
                $this->flash()->addErrorMessage('Tiêu đề và nội dung bản tin không được để trống.');
                return $this->redirect()->toRoute('library/announcements/edit', ['id' => $id]);
            }

            try {
                $this->dbAdapter->query(
                    "UPDATE announcements SET title = ?, content = ?, type = ?, start_date = ?, end_date = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                    [$title, $content, $type, $startDate, $endDate, $isActive, $id]
                );
                $this->flash()->addSuccessMessage('Đã cập nhật bản tin "' . htmlspecialchars($title) . '" thành công.');
            } catch (\Throwable $e) {
                $this->flash()->addErrorMessage('Lỗi hệ thống: ' . $e->getMessage());
            }

            return $this->redirect()->toRoute('library/announcements');
        }

        return new ViewModel([
            'announcement' => $ann,
            'currentUser' => $this->currentUser(),
        ]);
    }

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

        return $this->redirect()->toRoute('library/announcements');
    }

    public function toggleAnnouncementAction(): Response
    {
        if ($response = $this->requireAdmin()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id', 0);
        if ($id > 0) {
            $stmt = $this->dbAdapter->query("SELECT * FROM announcements WHERE id = ? LIMIT 1");
            $res = iterator_to_array($stmt->execute([$id]));
            if (count($res) > 0) {
                $ann = $res[0];
                $isActive = (bool)$ann['is_active'];
                $today = date('Y-m-d');
                
                $isNotStarted = $ann['start_date'] && $ann['start_date'] > $today;
                $isExpired = $ann['end_date'] && $ann['end_date'] < $today;
                
                $isShowing = $isActive && !$isNotStarted && !$isExpired;
                
                if ($isShowing) {
                    $this->dbAdapter->query(
                        "UPDATE announcements SET is_active = 0, updated_at = NOW() WHERE id = ?",
                        [$id]
                    );
                    $this->flash()->addSuccessMessage('Đã ẩn bản tin thành công.');
                } else {
                    $updates = ["is_active = 1", "updated_at = NOW()"];
                    $params = [];
                    
                    if ($isNotStarted) {
                        $updates[] = "start_date = NULL";
                    }
                    if ($isExpired) {
                        $updates[] = "end_date = NULL";
                    }
                    
                    $params[] = $id;
                    
                    $this->dbAdapter->query(
                        "UPDATE announcements SET " . implode(", ", $updates) . " WHERE id = ?",
                        $params
                    );
                    $this->flash()->addSuccessMessage('Đã hiển thị bản tin thành công.');
                }
            } else {
                $this->flash()->addErrorMessage('Không tìm thấy bản tin.');
            }
        }

        return $this->redirect()->toRoute('library/announcements');
    }
}
